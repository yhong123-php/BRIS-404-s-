<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('requires_reference_number')->default(false);
            $table->boolean('requires_document')->default(false);
            $table->boolean('has_expiry')->default(false);
            $table->boolean('is_age_derived')->default(false);
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_types');
    }
};
