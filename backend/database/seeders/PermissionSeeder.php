<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Permission names follow SDD §5.2's "module.operation" convention. The set
 * covers every capability row of SDD Table 5.1 and the assistant rows of
 * Table 5.2. Module codes match SDD Figure 4.1.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // C1 / C3 — Dashboards
            ['dashboard.view', 'C1', 'View the dashboard'],
            ['f4.view', 'C3', 'View the predictive and demographic dashboard'],

            // A1 — Resident registration and updates
            ['residents.view', 'A1', 'View resident records'],
            ['residents.create', 'A1', 'Create a resident record'],
            ['residents.edit', 'A1', 'Edit a resident record'],
            ['residents.merge_archive', 'A1', 'Merge duplicate residents or archive a record'],
            ['residents.restore', 'A1', 'Restore an archived resident record'],

            // A2 / A3 — Households and family relationships
            ['households.view', 'A2', 'View household records'],
            ['households.manage', 'A2', 'Create, update, and split households'],
            ['relationships.view', 'A3', 'View family relationships and families'],
            ['relationships.manage', 'A3', 'Record family relationships'],

            // A4 — Purok population monitoring
            ['puroks.view', 'A4', 'View purok population monitoring'],
            ['puroks.manage', 'A4', 'Maintain the purok list and purok leaders'],

            // A5 — Resident classification
            ['classifications.view', 'A5', 'View sectoral classifications'],
            ['classifications.manage', 'A5', 'Record and verify sectoral classifications'],
            ['classifications.configure', 'A5', 'Configure classification types'],

            // A6 — Skills and achievements registry
            ['a6.view', 'A6', 'View skills and achievements'],
            ['a6.manage', 'A6', 'Record and verify skills and achievements'],

            // Social assistance records (SRS §3.2.3.10)
            ['assistance.view', 'A5', 'View social assistance received by residents'],
            ['assistance.record', 'A5', 'Record social assistance received by residents'],

            // B2 / B4 — Relief distribution and smart allocation
            ['relief.view', 'B2', 'View relief distributions'],
            ['relief.create', 'B2', 'Create draft distributions and prepare beneficiary lists'],
            ['relief.record_claim', 'B2', 'Record a relief claim'],
            ['relief.reverse_claim', 'B2', 'Reverse a relief claim with a recorded reason'],
            ['f3.prepare', 'B4', 'Prepare a smart relief allocation plan'],
            ['f3.approve', 'B4', 'Approve beneficiary lists and relief allocation plans'],

            // B3 — Population events
            ['population_events.view', 'B3', 'View births, deaths, and transfers'],
            ['population_events.record', 'B3', 'Record a birth, death, or transfer'],

            // C2 — Reports
            ['reports.generate.all', 'C2', 'Generate any report'],

            // D1–D3 — Administration
            ['users.manage', 'D1', 'Manage user accounts, roles, and permissions'],
            ['audit.view', 'D2', 'View the audit log and assistant logs'],
            ['backup.restore', 'D3', 'Trigger backups and restores'],

            // E1 — Record copies
            ['record_copies.issue', 'E1', 'Issue a printed copy of a resident record'],

            // F1 / F2 — AI resident analysis and opportunity matching
            ['f1.read', 'F1', 'View AI resident-analysis suggestions'],
            ['f1.confirm', 'F1', 'Confirm or reject AI resident-analysis suggestions'],
            ['f2.view', 'F2', 'View opportunities and shortlists'],
            ['f2.manage', 'F2', 'Post opportunities and review skill matches'],

            // G1 / G2 — Consent and data-subject requests
            ['g1.record', 'G1', 'Record a resident consent or revocation'],
            ['g1.configure', 'G1', 'Configure consent purposes'],
            ['g2.receive', 'G2', 'Receive a data-subject request'],
            ['g2.decide', 'G2', 'Decide a data-subject request'],

            // H1 — AI-Powered Barangay Assistant (SDD Table 5.2)
            ['assistant.use', 'H1', 'Use the AI-Powered Barangay Assistant'],
            ['assistant.configure', 'H1', 'Configure assistant access per role and review assistant logs'],
        ];

        foreach ($permissions as [$name, $module, $description]) {
            Permission::updateOrCreate(['name' => $name], ['module' => $module, 'description' => $description]);
        }
    }
}
