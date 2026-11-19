<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aligns the schema with the revised SRS / SDD / SPMP:
 *
 * 1. Two user roles only (SRS §2.3, SDD Table 5.1): the Barangay Captain
 *    (System Administrator) and the Barangay Secretary. The earlier staff,
 *    treasurer and health_worker roles are removed. Any account still on one
 *    of them is moved to secretary and deactivated so the Captain re-approves it.
 * 2. residents.employment_status (SDD Table 3.1) — needed by the employment
 *    queries of the AI-Powered Barangay Assistant (SRS §3.2.3.4, §3.2.3.10).
 * 3. social_assistance_records (SDD Table 3.2, SRS §3.2.3.10) — medical,
 *    educational, livelihood, or other assistance received by a resident.
 */
return new class extends Migration
{
    private const REMOVED_ROLES = ['staff', 'treasurer', 'health_worker'];

    private const REMOVED_PERMISSIONS = [
        'residents.edit_health_fields',
        'a6.manage_health_linked',
        'clearance.approve',
        'certificates.prepare',
        'certificates.release',
        'reports.generate.operational',
        'reports.generate.fees',
        'reports.generate.health',
    ];

    public function up(): void
    {
        $secretaryId = DB::table('roles')->where('name', 'secretary')->value('id');
        $removedRoleIds = DB::table('roles')->whereIn('name', self::REMOVED_ROLES)->pluck('id');

        if ($removedRoleIds->isNotEmpty()) {
            if ($secretaryId) {
                DB::table('users')->whereIn('role_id', $removedRoleIds)
                    ->update(['role_id' => $secretaryId, 'is_active' => false]);
            }
            DB::table('role_permissions')->whereIn('role_id', $removedRoleIds)->delete();
            DB::table('roles')->whereIn('id', $removedRoleIds)->delete();
        }

        $removedPermissionIds = DB::table('permissions')->whereIn('name', self::REMOVED_PERMISSIONS)->pluck('id');
        if ($removedPermissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $removedPermissionIds)->delete();
            DB::table('permissions')->whereIn('id', $removedPermissionIds)->delete();
        }

        Schema::table('residents', function (Blueprint $table) {
            $table->enum('employment_status', ['employed', 'self_employed', 'unemployed', 'not_in_labor_force'])
                ->nullable()
                ->after('occupation');
            $table->index('employment_status');
            $table->index('educational_attainment');
        });

        Schema::create('social_assistance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('residents')->restrictOnDelete();
            $table->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();
            $table->enum('assistance_type', ['medical', 'educational', 'livelihood', 'other']);
            $table->string('program_name');
            $table->string('description_or_amount')->nullable();
            $table->date('date_received');
            $table->string('source')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['assistance_type', 'date_received']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_assistance_records');

        Schema::table('residents', function (Blueprint $table) {
            $table->dropIndex(['employment_status']);
            $table->dropIndex(['educational_attainment']);
            $table->dropColumn('employment_status');
        });
        // Removed roles and permissions are re-created by re-running an older
        // seeder if ever needed; they are not restored here.
    }
};
