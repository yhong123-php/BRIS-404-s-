<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('residents')->restrictOnDelete();
            $table->foreignId('skill_type_id')->constrained('skill_types')->restrictOnDelete();
            $table->enum('proficiency_level', ['beginner', 'intermediate', 'advanced', 'expert'])->nullable();
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('document_path')->nullable();
            $table->enum('source', ['self_declared', 'staff_encoded'])->default('self_declared');
            $table->enum('status', ['active', 'revoked'])->default('active');
            $table->timestamps();

            $table->index(['skill_type_id', 'is_verified']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
