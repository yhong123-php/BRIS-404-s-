<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('residents')->restrictOnDelete();
            $table->foreignId('classification_type_id')->constrained('classification_types')->restrictOnDelete();
            $table->string('reference_number')->nullable();
            $table->date('date_granted')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('document_path')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['active', 'expired', 'revoked'])->default('active');
            $table->timestamps();

            $table->index(['classification_type_id', 'status']);
        });

        // MySQL/MariaDB have no native partial unique index, so a generated column
        // stands in for "unique on (resident_id, classification_type_id) where status is active"
        // (SDD Table 4.0): the column is null for any non-active row, and a unique index
        // treats nulls as distinct, so only one active row per resident+type can exist.
        DB::statement(
            'ALTER TABLE resident_classifications
             ADD COLUMN active_classification_type_id BIGINT UNSIGNED
             GENERATED ALWAYS AS (CASE WHEN status = \'active\' THEN classification_type_id ELSE NULL END) STORED'
        );
        Schema::table('resident_classifications', function (Blueprint $table) {
            $table->unique(['resident_id', 'active_classification_type_id'], 'resident_classifications_active_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_classifications');
    }
};
