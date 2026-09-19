<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained('households')->cascadeOnDelete();
            $table->foreignId('resident_id')->constrained('residents')->restrictOnDelete();
            $table->string('relationship_to_head')->nullable();
            $table->boolean('is_head')->default(false);
            $table->date('date_joined');
            $table->date('date_left')->nullable();
            $table->timestamps();

            $table->unique(['household_id', 'resident_id', 'date_joined']);
            $table->index(['household_id', 'date_left']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_members');
    }
};
