<?php

namespace Database\Factories;

use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Resident>
 */
class ResidentFactory extends Factory
{
    protected $model = Resident::class;

    /**
     * Default state. purok_id is intentionally left unset here — the caller
     * (typically ResidentSeeder) sets it per purok via ->for() or a raw
     * ['purok_id' => ...] override, since a resident's purok is the whole
     * point of a seeder that loops puroks.
     *
     * resident_number uses a "TEST-" prefix rather than the real RN-YYYY-#####
     * scheme ResidentService::nextResidentNumber() generates for actual
     * registrations, so fake seed rows stay visually distinguishable from
     * real ones (and a cleanup script can safely target `WHERE resident_number
     * LIKE 'TEST-%'`) instead of risking collisions with the real sequence.
     */
    public function definition(): array
    {
        $sex = fake()->randomElement(['male', 'female']);

        return [
            'resident_number' => 'TEST-'.fake()->unique()->numerify('########'),
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName($sex),
            'middle_name' => fake()->optional(0.8)->lastName(),
            'suffix' => fake()->optional(0.1)->randomElement(['Jr.', 'Sr.', 'II', 'III']),
            'sex' => $sex,
            'birth_date' => fake()->dateTimeBetween('-90 years', '-1 year')->format('Y-m-d'),
            'birth_place' => fake()->city(),
            'civil_status' => fake()->randomElement(['single', 'married', 'widowed', 'separated', 'divorced', 'annulled']),
            'citizenship' => 'Filipino',
            'religion' => fake()->optional()->randomElement(['Roman Catholic', 'Iglesia ni Cristo', 'Baptist', 'Islam', 'Other']),
            'occupation' => fake()->optional()->randomElement([
                'Farmer', 'Fisherman', 'Vendor', 'Driver', 'Carpenter', 'Teacher', 'Housekeeper', 'Construction Worker', 'Sari-sari Store Owner',
            ]),
            'employment_status' => fake()->optional(0.85)->randomElement(Resident::EMPLOYMENT_STATUSES),
            'educational_attainment' => fake()->optional(0.9)->randomElement(array_keys(Resident::EDUCATION_LEVELS)),
            'monthly_income_bracket' => fake()->optional()->randomElement(Resident::INCOME_BRACKETS),
            'contact_number' => fake()->optional()->numerify('09#########'),
            'email' => fake()->optional()->safeEmail(),
            'house_number' => fake()->optional()->buildingNumber(),
            'street' => fake()->optional()->streetName(),
            'voter_status' => fake()->optional()->randomElement(['registered', 'not_registered']),
            'residency_start_date' => fake()->optional()->dateTimeBetween('-20 years', 'now')?->format('Y-m-d'),
            'status' => 'active',
        ];
    }
}
