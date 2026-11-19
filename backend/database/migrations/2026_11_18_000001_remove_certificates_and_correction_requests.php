<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes B1 (certificate and clearance issuance) and E2 (information-correction
 * requests) from the system at the barangay's request (2026-11-18).
 * Their tables were empty apart from the seeded certificate types.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['fee_payments', 'certificates', 'certificate_requests', 'certificate_sequences', 'certificate_types', 'correction_requests'] as $table) {
            Schema::dropIfExists($table);
        }

        $ids = DB::table('permissions')->whereIn('name', [
            'certificates.view', 'certificates.issue', 'fees.record', 'corrections.record', 'corrections.approve',
        ])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    public function down(): void
    {
        // Not restored automatically. The removed code is kept in the BRIS-removed-modules folder next to BRIS.
    }
};
