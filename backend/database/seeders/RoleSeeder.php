<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * The two user roles of SRS §2.3 / SDD Table 5.1. The Barangay Captain is the
 * System Administrator. Residents are data subjects, not users — they have no
 * accounts (SDD §1.2).
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'captain', 'description' => 'Barangay Captain — System Administrator'],
            ['name' => 'secretary', 'description' => 'Barangay Secretary — encodes resident records'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['name' => $role['name']], $role + ['is_system_role' => true]);
        }
    }
}
