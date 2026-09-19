<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One seeded Secretary account so the system is reachable on first run.
 * SDD §5.1: staff accounts are created with a temporary password that must
 * be changed at first login — must_change_password starts true.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $secretaryRole = Role::where('name', 'secretary')->firstOrFail();

        User::updateOrCreate(
            ['username' => 'secretary'],
            [
                'full_name' => 'Barangay Secretary',
                'email' => 'secretary@bris.local',
                'password' => Hash::make('ChangeMe123!'),
                'role_id' => $secretaryRole->id,
                'position' => 'Barangay Secretary',
                'is_active' => true,
                'must_change_password' => true,
            ]
        );
    }
}
