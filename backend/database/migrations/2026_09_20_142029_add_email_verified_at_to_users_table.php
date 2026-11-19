<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('password');
        });

        // Accounts that already exist (the seeded secretary, anyone an admin
        // creates directly) never went through the registration/verify-link
        // flow this column gates. Backfill them as verified so this new
        // column doesn't retroactively lock anyone already active out of
        // login — only accounts self-registered from now on start null.
        DB::table('users')->whereNull('email_verified_at')->update([
            'email_verified_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });
    }
};
