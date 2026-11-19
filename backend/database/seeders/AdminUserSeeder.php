<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The two initial accounts of SRS §2.3: the Barangay Captain (System
 * Administrator) and the Barangay Secretary. SDD §5.1: accounts start with a
 * temporary password that must be changed at first login.
 *
 * Accounts are only created when missing — re-running the seeder never resets
 * a password that the user has already changed.
 *
 * email_verified_at is set here deliberately: on a fresh install, migrations
 * (including the one that backfills email_verified_at for pre-existing rows)
 * run before any seeder, so these rows wouldn't be caught by that backfill and
 * login()'s email-verification check would lock them out.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['username' => 'captain', 'full_name' => 'Barangay Captain', 'email' => 'captain@bris.local', 'role' => 'captain', 'position' => 'Barangay Captain'],
            ['username' => 'secretary', 'full_name' => 'Barangay Secretary', 'email' => 'secretary@bris.local', 'role' => 'secretary', 'position' => 'Barangay Secretary'],
        ];

        foreach ($accounts as $account) {
            $roleId = Role::where('name', $account['role'])->firstOrFail()->id;

            $user = User::firstOrCreate(
                ['username' => $account['username']],
                [
                    'full_name' => $account['full_name'],
                    'email' => $account['email'],
                    'password' => Hash::make('ChangeMe123!'),
                    'role_id' => $roleId,
                    'position' => $account['position'],
                    'is_active' => true,
                    'must_change_password' => true,
                ]
            );

            // The role is always enforced, even on an existing account.
            if ($user->role_id !== $roleId) {
                $user->update(['role_id' => $roleId]);
            }

            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
        }
    }
}
