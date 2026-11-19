<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Maps the two roles to their permissions exactly as SDD Table 5.1 (Role and
 * permission matrix) and Table 5.2 (assistant access) state them:
 * "Prepare / Record / Receive" go to the Secretary, "Approve / Decide" and all
 * System Administrator capabilities go to the Captain.
 */
class RolePermissionSeeder extends Seeder
{
    /** Capabilities both roles hold ("Yes / Yes", "View", "All / All"). */
    private const SHARED = [
        'dashboard.view', 'f4.view',
        'residents.view',
        'households.view',
        'relationships.view',
        'puroks.view',
        'classifications.view',
        'a6.view',
        'assistance.view',
        'relief.view',
        'population_events.view',
        'reports.generate.all',
        'record_copies.issue',
        'f1.read',
        'f2.view',
        'assistant.use',
    ];

    public function run(): void
    {
        $grants = [
            'secretary' => array_merge(self::SHARED, [
                'residents.create', 'residents.edit', 'residents.merge_archive',
                'households.manage',
                'relationships.manage',
                'puroks.manage',
                'classifications.manage',
                'a6.manage',
                'assistance.record',
                'relief.create', 'relief.record_claim', 'relief.reverse_claim', 'f3.prepare',
                'population_events.record',
                'f1.confirm',
                'f2.manage',
                'g1.record',
                'g2.receive',
            ]),
            'captain' => array_merge(self::SHARED, [
                'residents.restore',
                'classifications.configure',
                'f3.approve',
                'g1.configure',
                'g2.decide',
                'users.manage',
                'audit.view',
                'backup.restore',
                'assistant.configure',
            ]),
        ];

        foreach ($grants as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->firstOrFail();
            $permissionIds = Permission::whereIn('name', $permissionNames)->pluck('id');
            $role->permissions()->sync($permissionIds);
        }
    }
}
