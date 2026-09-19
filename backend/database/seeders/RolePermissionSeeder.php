<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Maps each role to its exact permissions. Tie-breaker rule (client-confirmed):
 * where SDD Table 9.0 (Role and permission matrix), Table 7.0 (Actors and
 * module access), and a module's own §4.x narrative disagree, Table 9.0 wins
 * whenever it speaks to the capability; where Table 9.0 is silent, fall back
 * to the narrative/Table 7.0. Three resolved cases:
 *   1. Table 9.0 says Health Worker "Yes" for "Record relief claim", though
 *      the B2 narrative (§4.9) calls Health Worker advisory-only. Table 9.0
 *      speaks to it directly, so relief.record_claim is granted.
 *   2. Table 7.0 says Staff has B1 "prepare and release", but Table 9.0's
 *      "Issue certificate" row assigns "Release" only to Treasurer. Table
 *      9.0 speaks to it directly, so certificates.release is Treasurer-only.
 *   3. Table 9.0 has no household-read row at all (only "Manage households").
 *      Since it's silent, this falls back to §4.4's narrative ("Captain and
 *      Health Worker (read)"), even though Table 7.0's actor list omits A2
 *      for both — so households.view is granted to Captain and Health
 *      Worker despite Table 7.0.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $grants = [
            'captain' => [
                // Table 9.0's "Record population event" row has no "Yes" for
                // Captain (B3 access is "review" only per Table 7.0), so
                // population_events.record is deliberately not granted here.
                'dashboard.view', 'residents.view',
                'households.view', 'puroks.view',
                'clearance.approve',
                'relief.create',
                'reports.generate.all',
                'audit.view',
                'f1.read', 'f3.approve', 'f4.view',
            ],
            'secretary' => [
                'dashboard.view', 'residents.view', 'residents.create', 'residents.edit', 'residents.merge_archive',
                'households.view', 'households.manage',
                'relationships.manage',
                'puroks.view', 'puroks.manage',
                'classifications.manage',
                'a6.manage',
                'clearance.approve', 'certificates.issue', 'fees.record',
                'relief.create', 'relief.record_claim', 'relief.reverse_claim',
                'population_events.record',
                'reports.generate.all',
                'users.manage', 'audit.view', 'backup.restore',
                'f1.confirm', 'f2.manage', 'f3.prepare', 'f4.view',
                'g1.configure',
            ],
            'staff' => [
                'dashboard.view', 'residents.view', 'residents.create', 'residents.edit',
                'households.view', 'households.manage',
                'relationships.manage',
                'puroks.view',
                'classifications.manage',
                'a6.manage',
                'certificates.prepare',
                'relief.record_claim',
                'population_events.record',
                'reports.generate.operational',
                'f1.confirm', 'f2.manage', 'f3.prepare',
            ],
            'treasurer' => [
                'dashboard.view', 'residents.view',
                'certificates.release', 'fees.record',
                'reports.generate.fees',
                'f4.view',
            ],
            'health_worker' => [
                'dashboard.view', 'residents.view', 'residents.edit_health_fields',
                'households.view', 'puroks.view',
                'classifications.manage',
                'a6.manage_health_linked',
                'relief.record_claim',
                'population_events.record',
                'reports.generate.health',
                'f1.read', 'f4.view',
            ],
        ];

        foreach ($grants as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->firstOrFail();
            $permissionIds = Permission::whereIn('name', $permissionNames)->pluck('id');
            $role->permissions()->sync($permissionIds);
        }
    }
}
