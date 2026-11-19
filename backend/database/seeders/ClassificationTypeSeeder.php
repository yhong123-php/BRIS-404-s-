<?php

namespace Database\Seeders;

use App\Models\ClassificationType;
use Illuminate\Database\Seeder;

/**
 * The sectoral categories named explicitly in SDD §4.7. Flags (reference
 * number / document / expiry / age-derivation) are reasonable defaults based
 * on how each program actually works, not specified column-by-column in the
 * SDD — the barangay can adjust them later since this is reference data, not
 * code (SDD §3.1).
 */
class ClassificationTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Senior Citizen', 'requires_reference_number' => true, 'requires_document' => true, 'has_expiry' => false, 'is_age_derived' => true, 'min_age' => 60],
            ['name' => 'Person with Disability', 'requires_reference_number' => true, 'requires_document' => true, 'has_expiry' => false, 'is_age_derived' => false, 'min_age' => null],
            ['name' => 'Solo Parent', 'requires_reference_number' => true, 'requires_document' => true, 'has_expiry' => true, 'is_age_derived' => false, 'min_age' => null],
            ['name' => 'Pregnant', 'requires_reference_number' => false, 'requires_document' => true, 'has_expiry' => true, 'is_age_derived' => false, 'min_age' => null],
            // Not marked is_age_derived: the SDD's classification_types schema only has
            // min_age (no upper bound), so an age-suggestion rule here would incorrectly
            // fire for every older resident too. Kept as a declared-only classification.
            ['name' => 'Infant or Child', 'requires_reference_number' => false, 'requires_document' => false, 'has_expiry' => false, 'is_age_derived' => false, 'min_age' => null],
            ['name' => 'Out-of-School Youth', 'requires_reference_number' => false, 'requires_document' => false, 'has_expiry' => false, 'is_age_derived' => false, 'min_age' => null],
            ['name' => 'Indigenous Person', 'requires_reference_number' => true, 'requires_document' => true, 'has_expiry' => false, 'is_age_derived' => false, 'min_age' => null],
            ['name' => '4Ps Member', 'requires_reference_number' => true, 'requires_document' => true, 'has_expiry' => false, 'is_age_derived' => false, 'min_age' => null],
            ['name' => 'Unemployed', 'requires_reference_number' => false, 'requires_document' => false, 'has_expiry' => false, 'is_age_derived' => false, 'min_age' => null],
            // SRS §3.2.3.3: indigent families.
            ['name' => 'Indigent', 'requires_reference_number' => false, 'requires_document' => true, 'has_expiry' => true, 'is_age_derived' => false, 'min_age' => null],
        ];

        foreach ($types as $type) {
            ClassificationType::updateOrCreate(['name' => $type['name']], $type + ['is_active' => true]);
        }
    }
}
