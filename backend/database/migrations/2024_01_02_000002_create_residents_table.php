<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->string('resident_number')->unique();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('suffix')->nullable();
            $table->enum('sex', ['male', 'female']);
            $table->date('birth_date');
            $table->string('birth_place')->nullable();
            $table->enum('civil_status', ['single', 'married', 'widowed', 'separated', 'divorced', 'annulled']);
            $table->string('citizenship')->default('Filipino');
            $table->string('religion')->nullable();
            $table->string('occupation')->nullable();
            $table->string('educational_attainment')->nullable();
            $table->string('monthly_income_bracket')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('email')->nullable();
            $table->foreignId('purok_id')->nullable()->constrained('puroks')->restrictOnDelete();
            $table->string('house_number')->nullable();
            $table->string('street')->nullable();
            $table->enum('voter_status', ['registered', 'not_registered'])->nullable();
            $table->date('residency_start_date')->nullable();
            $table->string('photo_path')->nullable();
            $table->enum('status', ['active', 'deceased', 'transferred_out', 'archived'])->default('active');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
            $table->index(['purok_id', 'status']);
            $table->index('birth_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('residents');
    }
};
