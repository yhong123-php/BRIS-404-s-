<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with the reference data the SDD names
     * explicitly (roles, permissions, classification types, skill types) and
     * one initial Secretary account. Barangay-specific operational data
     * (actual puroks, households, residents) is not fabricated here — see
     * DevSampleDataSeeder for local-only placeholder data to exercise the UI.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            AdminUserSeeder::class,
            ClassificationTypeSeeder::class,
            SkillTypeSeeder::class,
        ]);
    }
}
