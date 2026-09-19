<?php

namespace Database\Seeders;

use App\Models\Purok;
use Illuminate\Database\Seeder;

/**
 * Placeholder puroks for LOCAL DEVELOPMENT ONLY, so households and residents
 * can be created while testing. These are not Barangay San Isidro's actual
 * purok list — replace or remove before any real deployment. Run explicitly
 * with `php artisan db:seed --class=DevSampleDataSeeder`; not part of the
 * default DatabaseSeeder run.
 */
class DevSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach (range(1, 3) as $number) {
            Purok::updateOrCreate(
                ['name' => "Purok {$number}"],
                ['purok_number' => (string) $number, 'description' => 'Sample purok for local development.']
            );
        }
    }
}
