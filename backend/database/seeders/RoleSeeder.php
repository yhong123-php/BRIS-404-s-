<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * The five staff-side roles from SDD Table 6.0 (roles.name is an enum of
 * exactly these). Resident is a distinct actor authenticated through
 * resident_accounts, not a row in this table (SDD §3.5).
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'captain', 'description' => 'Barangay Captain'],
            ['name' => 'secretary', 'description' => 'Barangay Secretary — registry custodian, de facto administrator'],
            ['name' => 'staff', 'description' => 'Barangay Staff'],
            ['name' => 'treasurer', 'description' => 'Treasurer'],
            ['name' => 'health_worker', 'description' => 'Health Worker'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['name' => $role['name']], $role + ['is_system_role' => true]);
        }
    }
}
