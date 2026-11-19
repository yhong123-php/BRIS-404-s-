<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2 opportunities segregated by purok:
 *  - opportunities: target puroks (null = all), working-age range, and how the
 *    slots are shared ('priority' barangay-wide, or 'per_purok' fair share).
 *  - opportunity_matches: the resident's purok, rank within the purok, and
 *    whether the match falls within the available slots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->json('target_purok_ids')->nullable()->after('required_skills');
            $table->unsignedTinyInteger('min_age')->default(18)->after('target_purok_ids');
            $table->unsignedTinyInteger('max_age')->default(64)->after('min_age');
            $table->enum('allocation_mode', ['priority', 'per_purok'])->default('per_purok')->after('slots');
        });

        Schema::table('opportunity_matches', function (Blueprint $table) {
            $table->foreignId('purok_id')->nullable()->after('resident_id')->constrained('puroks')->nullOnDelete();
            $table->unsignedInteger('purok_rank')->nullable()->after('rank');
            $table->boolean('within_slots')->default(true)->after('purok_rank');
        });

        DB::statement('UPDATE opportunity_matches m JOIN residents r ON r.id = m.resident_id SET m.purok_id = r.purok_id');
    }

    public function down(): void
    {
        Schema::table('opportunity_matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purok_id');
            $table->dropColumn(['purok_rank', 'within_slots']);
        });
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn(['target_purok_ids', 'min_age', 'max_age', 'allocation_mode']);
        });
    }
};
