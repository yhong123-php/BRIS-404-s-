<?php

namespace Database\Seeders;

use App\Models\Purok;
use Illuminate\Database\Seeder;

/**
 * Barangay San Isidro's actual purok list. Unlike DevSampleDataSeeder (fake
 * placeholders, opt-in only), this is real reference data for this specific
 * barangay, so it runs as part of the default `db:seed`, alongside
 * RoleSeeder/PermissionSeeder/etc. updateOrCreate keyed on name makes it
 * safe to re-run without duplicating rows already created through the app.
 */
class PurokSeeder extends Seeder
{
    public function run(): void
    {
        $puroks = ['1-A', '1-B', '2', '3', '4', '5', '6'];

        foreach ($puroks as $number) {
            Purok::updateOrCreate(
                ['name' => "Purok {$number}"],
                ['purok_number' => $number]
            );
        }
    }
}
