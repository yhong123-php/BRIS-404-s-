<?php

namespace Database\Seeders;

use App\Models\Purok;
use App\Models\Resident;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fake residents for LOCAL DEVELOPMENT/TESTING ONLY (e.g. exercising the A2
 * household UI, A4 purok population screens, or pagination at real volume).
 * Each resident_number is "TEST-########" (see ResidentFactory) so fake rows
 * never collide with, or get confused for, real registrations made through
 * ResidentService::nextResidentNumber()'s RN-YYYY-##### sequence.
 */
class ResidentSeeder extends Seeder
{
    /** Residents created per purok. */
    private const PER_PUROK = 100;

    public function run(): void
    {
        $puroks = Purok::all();

        if ($puroks->isEmpty()) {
            $this->command?->warn(
                'No puroks found — nothing to seed. Create at least one purok first '
                .'(via the app, or `php artisan db:seed --class=DevSampleDataSeeder`).'
            );

            return;
        }

        foreach ($puroks as $purok) {
            DB::transaction(function () use ($purok) {
                Resident::factory()
                    ->count(self::PER_PUROK)
                    ->create(['purok_id' => $purok->id]);
            });

            $this->command?->info("Seeded ".self::PER_PUROK." residents into \"{$purok->name}\".");
        }
    }
}
