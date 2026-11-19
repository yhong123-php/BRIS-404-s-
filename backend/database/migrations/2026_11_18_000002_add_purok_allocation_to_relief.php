<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic relief lists segregated by purok:
 *  - relief_distributions.allocation_mode: 'priority' ranks households
 *    barangay-wide; 'per_purok' gives each purok a fair share of a limited
 *    supply (proportional to its eligible households) and ranks within it.
 *  - relief_beneficiaries.purok_id / purok_rank: the purok is frozen with the
 *    list (SDD §4.9: later registry changes cannot alter a distribution).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relief_distributions', function (Blueprint $table) {
            $table->enum('allocation_mode', ['priority', 'per_purok'])->default('priority')->after('priority_weights');
        });

        Schema::table('relief_beneficiaries', function (Blueprint $table) {
            $table->foreignId('purok_id')->nullable()->after('household_id')->constrained('puroks')->nullOnDelete();
            $table->unsignedInteger('purok_rank')->nullable()->after('rank');
            $table->index(['relief_distribution_id', 'purok_id']);
        });

        // Existing lists: take the purok from the household.
        DB::statement('UPDATE relief_beneficiaries rb JOIN households h ON h.id = rb.household_id SET rb.purok_id = h.purok_id');
    }

    public function down(): void
    {
        Schema::table('relief_beneficiaries', function (Blueprint $table) {
            $table->dropIndex(['relief_distribution_id', 'purok_id']);
            $table->dropConstrainedForeignId('purok_id');
            $table->dropColumn('purok_rank');
        });
        Schema::table('relief_distributions', function (Blueprint $table) {
            $table->dropColumn('allocation_mode');
        });
    }
};
