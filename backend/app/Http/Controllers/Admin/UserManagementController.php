<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * D1 — User, role and permission management (SDD §4.13, §5.1). The Barangay
 * Captain creates accounts with a temporary password that must be changed at
 * first login, assigns one of the two roles, deactivates (never deletes)
 * accounts, forces password resets, and edits role permissions.
 */
class UserManagementController extends Controller
{
    public function index()
    {
        return response()->json(['data' => User::with('role:id,name')->orderBy('full_name')->get()->map(fn (User $u) => [
            'id' => $u->id, 'full_name' => $u->full_name, 'username' => $u->username, 'email' => $u->email,
            'role' => $u->role?->name, 'role_id' => $u->role_id, 'position' => $u->position, 'contact_number' => $u->contact_number,
            'is_active' => $u->is_active, 'must_change_password' => $u->must_change_password, 'last_login_at' => $u->last_login_at,
        ])]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'alpha_dash', 'max:50', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role_id' => ['required', 'exists:roles,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
        ]);
        $temporary = $this->temporaryPassword();

        $user = User::create($data + ['password' => Hash::make($temporary), 'is_active' => true, 'must_change_password' => true]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Audit::log('user_created', 'D1', 'users', $user->id, ['username' => $user->username, 'role_id' => $user->role_id]);

        return response()->json(['data' => ['id' => $user->id, 'temporary_password' => $temporary]], 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'role_id' => ['sometimes', 'exists:roles,id'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_number' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);
        if ($user->id === $request->user()->id && (($data['is_active'] ?? true) === false || (isset($data['role_id']) && (int) $data['role_id'] !== $user->role_id))) {
            abort(422, 'You cannot deactivate your own account or change your own role.');
        }

        $old = $user->only(array_keys($data));
        $user->forceFill($data)->save();
        if (($data['is_active'] ?? true) === false || isset($data['role_id'])) {
            $user->tokens()->delete(); // revoked on deactivation and role change (SDD §5.1)
        }
        Audit::log('user_updated', 'D1', 'users', $user->id, $data, $old);

        return $this->index();
    }

    public function resetPassword(User $user)
    {
        $temporary = $this->temporaryPassword();
        $user->forceFill(['password' => Hash::make($temporary), 'must_change_password' => true])->save();
        $user->tokens()->delete();
        Audit::log('password_reset_by_captain', 'D1', 'users', $user->id);

        return response()->json(['data' => ['temporary_password' => $temporary]]);
    }

    /** Roles are collections of named permissions and are editable (SDD §4.13). */
    public function roles()
    {
        return response()->json(['data' => [
            'roles' => Role::with('permissions:id,name')->orderBy('name')->get()->map(fn (Role $r) => [
                'id' => $r->id, 'name' => $r->name, 'description' => $r->description, 'permissions' => $r->permissions->pluck('name'),
            ]),
            'permissions' => Permission::orderBy('module')->orderBy('name')->get(['id', 'name', 'module', 'description']),
        ]]);
    }

    public function updateRolePermissions(Request $request, Role $role)
    {
        $data = $request->validate(['permissions' => ['required', 'array'], 'permissions.*' => ['string', Rule::exists('permissions', 'name')]]);
        // The Captain must never lock themselves out of administration.
        if ($role->name === 'captain') {
            $data['permissions'] = array_values(array_unique(array_merge($data['permissions'], ['users.manage'])));
        }
        $old = $role->permissions()->pluck('name')->all();
        $role->permissions()->sync(Permission::whereIn('name', $data['permissions'])->pluck('id'));
        Audit::log('role_permissions_changed', 'D1', 'roles', $role->id, ['permissions' => $data['permissions']], ['permissions' => $old]);

        return $this->roles();
    }

    private function temporaryPassword(): string
    {
        return 'Bris-'.Str::upper(Str::random(4)).'-'.random_int(1000, 9999);
    }
}
