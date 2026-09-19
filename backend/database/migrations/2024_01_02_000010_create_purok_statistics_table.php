<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purok_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purok_id')->constrained('puroks')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->unsignedInteger('total_residents')->default(0);
            $table->unsignedInteger('total_households')->default(0);
            $table->unsignedInteger('male_count')->default(0);
            $table->unsignedInteger('female_count')->default(0);
            // Age-band counts, e.g. {"0-6":12,"7-12":9,"13-17":15,"18-59":120,"60+":18}
            $table->json('age_band_counts')->nullable();
            $table->unsignedInteger('senior_count')->default(0);
            $table->unsignedInteger('pwd_count')->default(0);
            $table->unsignedInteger('solo_parent_count')->default(0);
            $table->timestamps();

            $table->unique(['purok_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purok_statistics');
    }
};
