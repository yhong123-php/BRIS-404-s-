<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('household_number');
            $table->foreignId('purok_id')->constrained('puroks')->restrictOnDelete();
            $table->string('house_number')->nullable();
            $table->string('street')->nullable();
            $table->enum('dwelling_ownership', ['owned', 'rented', 'shared', 'informal_settler', 'other'])->nullable();
            $table->enum('construction_type', ['concrete', 'semi_concrete', 'wood', 'light_materials', 'other'])->nullable();
            $table->string('water_source')->nullable();
            $table->boolean('electricity_access')->default(false);
            $table->string('toilet_type')->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['purok_id', 'household_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('households');
    }
};
