<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Permission names follow SDD §5.2's "module.operation" convention
 * (e.g. residents.create). The full set is derived from Table 9.0 (Role and
 * permission matrix) plus the addendum's Table 7.0 extension for F/G.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Dashboard (C1)
            ['dashboard.view', 'C1', 'View the dashboard'],

            // A1 — Resident registration
            ['residents.view', 'A1', 'View resident records'],
            ['residents.create', 'A1', 'Create a resident record'],
            ['residents.edit', 'A1', 'Edit a resident record (full fields)'],
            ['residents.edit_health_fields', 'A1', 'Edit only health and sectoral fields on an existing resident'],
            ['residents.merge_archive', 'A1', 'Merge duplicate residents or archive a record'],

            // A2 — Household management
            ['households.view', 'A2', 'View household records'],
            ['households.manage', 'A2', 'Create, update, and split households'],

            // A3 — Family relationship management
            ['relationships.manage', 'A3', 'Record family relationships'],

            // A4 — Purok population monitoring
            ['puroks.view', 'A4', 'View purok population monitoring'],
            ['puroks.manage', 'A4', 'Maintain the purok list and purok leaders'],

            // A5 — Resident classification
            ['classifications.manage', 'A5', 'Assign or revoke sectoral classifications'],

            // A6 — Skills, achievements, livelihood registry
            ['a6.manage', 'A6', 'Record and verify skills and achievements'],
            ['a6.manage_health_linked', 'A6', 'Record health- and livelihood-linked skills only'],

            // B1 — Certificates
            ['clearance.approve', 'B1', 'Approve barangay clearance'],
            ['certificates.issue', 'B1', 'Issue a certificate'],
            ['certificates.prepare', 'B1', 'Prepare/receive a certificate request'],
            ['certificates.release', 'B1', 'Release a printed certificate'],
            ['fees.record', 'B1', 'Record a certificate fee payment'],

            // B2 — Relief distribution
            ['relief.create', 'B2', 'Create or close a relief distribution'],
            ['relief.record_claim', 'B2', 'Record a relief claim'],
            ['relief.reverse_claim', 'B2', 'Reverse a recorded relief claim'],

            // B3 — Population events
            ['population_events.record', 'B3', 'Record a birth, death, or transfer'],

            // C2 — Reports
            ['reports.generate.all', 'C2', 'Generate any report'],
            ['reports.generate.operational', 'C2', 'Generate operational reports'],
            ['reports.generate.fees', 'C2', 'Generate fee reports'],
            ['reports.generate.health', 'C2', 'Generate sectoral and vital reports'],

            // D-series — Administration
            ['users.manage', 'D1', 'Manage user accounts, roles, and permissions'],
            ['audit.view', 'D2', 'View the audit log'],
            ['backup.restore', 'D3', 'Trigger backups and restores'],

            // F-series — Analytics
            ['f1.confirm', 'F1', 'Review and confirm AI Resident Analysis suggestions'],
            ['f1.read', 'F1', 'Read F1 suggestions and outputs'],
            ['f2.manage', 'F2', 'Post opportunities and review skill matches'],
            ['f3.prepare', 'F3', 'Prepare a smart relief allocation plan'],
            ['f3.approve', 'F3', 'Approve a smart relief allocation plan'],
            ['f4.view', 'F4', 'View the predictive/demographic dashboard'],

            // G1 — Consent and privacy
            ['g1.configure', 'G1', 'Configure consent purposes and handle data-subject requests'],
        ];

        foreach ($permissions as [$name, $module, $description]) {
            Permission::updateOrCreate(['name' => $name], ['module' => $module, 'description' => $description]);
        }
    }
}
