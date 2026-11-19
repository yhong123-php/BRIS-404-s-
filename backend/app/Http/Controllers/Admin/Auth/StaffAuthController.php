<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Authentication of the two user roles against the `users` table (SDD §5.1).
 * Accounts are created only by the Barangay Captain (User Management, D1);
 * residents have no accounts.
 */
class StaffAuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'], // username or email
            'password' => ['required', 'string'],
        ]);

        $throttleKey = 'staff-login:'.Str::lower($credentials['login']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'login' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        $user = User::where('username', $credentials['login'])
            ->orWhere('email', $credentials['login'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_MINUTES * 60);

            AuditLog::create([
                'user_id' => $user?->id,
                'action' => 'login_failed',
                'module' => 'Auth',
                'table_name' => 'users',
                'record_id' => $user?->id,
                'new_values' => ['login' => $credentials['login']],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'client_type' => 'staff',
            ]);

            throw ValidationException::withMessages([
                'login' => 'The provided credentials are incorrect.',
            ]);
        }

        if (! $user->email_verified_at) {
            throw ValidationException::withMessages([
                'login' => 'This account is not registered or verified.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'login' => 'This account has been deactivated. Contact the Secretary.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('staff-session', ['staff'])->plainTextToken;

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'login_success',
            'module' => 'Auth',
            'table_name' => 'users',
            'record_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'client_type' => 'staff',
        ]);

        return response()->json([
            'token' => $token,
            'user' => $this->presentUser($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->presentUser($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'logout',
            'module' => 'Auth',
            'table_name' => 'users',
            'record_id' => $request->user()->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'client_type' => 'staff',
        ]);

        return response()->json(['message' => 'Logged out.']);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $user->forceFill([
            'password' => $data['new_password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        // Revoke every other token on password change (SDD §5.1) but keep
        // the session the user just changed the password from working.
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'password_changed',
            'module' => 'Auth',
            'table_name' => 'users',
            'record_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'client_type' => 'staff',
        ]);

        return response()->json(['message' => 'Password changed.']);
    }

    private function presentUser(User $user): array
    {
        $user->loadMissing('role.permissions');

        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role->name,
            'position' => $user->position,
            'must_change_password' => $user->must_change_password,
            // The role's permissions, so the interface hides exactly what the API would refuse (SDD §2.3).
            'permissions' => $user->role->permissions->pluck('name')->values()->all(),
        ];
    }
}
