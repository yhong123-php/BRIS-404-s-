<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with the reference data the SDD names
     * explicitly (roles, permissions, classification types, skill types), the
     * barangay's actual purok list, and one initial Secretary account. Fake
     * test data is not fabricated here — see DevSampleDataSeeder for
     * throwaway placeholder puroks, and ResidentSeeder for fake residents
     * (100 per existing purok). Both stay opt-in via --class= rather than
     * called below, so a plain `db:seed` never fabricates fake data by
     * surprise; PurokSeeder is real data, so it's safe to run by default.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            AdminUserSeeder::class,
            AssistantAccessSeeder::class,
            ClassificationTypeSeeder::class,
            SkillTypeSeeder::class,
            PurokSeeder::class,
            ReferenceDataSeeder::class,
        ]);
    }
}
