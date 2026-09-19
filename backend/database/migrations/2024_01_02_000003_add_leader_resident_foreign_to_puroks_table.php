<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puroks', function (Blueprint $table) {
            $table->foreign('leader_resident_id')->references('id')->on('residents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('puroks', function (Blueprint $table) {
            $table->dropForeign(['leader_resident_id']);
        });
    }
};
