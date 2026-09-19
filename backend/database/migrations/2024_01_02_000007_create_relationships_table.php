<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('residents')->restrictOnDelete();
            $table->foreignId('related_resident_id')->constrained('residents')->restrictOnDelete();
            $table->enum('relationship_type', ['parent', 'child', 'spouse', 'sibling', 'guardian', 'other']);
            $table->foreignId('family_id')->nullable()->constrained('families')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();

            $table->unique(['resident_id', 'related_resident_id', 'relationship_type'], 'relationships_pair_type_unique');
        });

        DB::statement('ALTER TABLE relationships ADD CONSTRAINT relationships_distinct_residents_check CHECK (resident_id <> related_resident_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('relationships');
    }
};
