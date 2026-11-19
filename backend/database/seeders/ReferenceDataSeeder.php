<?php

namespace Database\Seeders;

use App\Models\ConsentPurpose;
use Illuminate\Database\Seeder;

/**
 * Reference data kept in tables so the barangay can change it without code
 * (SDD §3.1): the consent purposes of SDD §4.21. Existing rows are never
 * overwritten.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $purposes = [
            ['code' => 'optional_analytics', 'name' => 'Analytics beyond mandatory eligibility', 'description' => 'AI resident analysis (employment-status and skill suggestions) beyond the eligibility checks of barangay programs.'],
            ['code' => 'opportunity_matching', 'name' => 'Livelihood and job opportunity matching', 'description' => 'Inclusion in shortlists for jobs, livelihood programs, and trainings based on verified skills.'],
            ['code' => 'ai_community_analysis', 'name' => 'Inclusion in AI community analysis', 'description' => 'Inclusion of the resident\'s data in the AI-Powered Barangay Assistant\'s community analysis and recommendations.'],
        ];
        foreach ($purposes as $purpose) {
            ConsentPurpose::firstOrCreate(['code' => $purpose['code']], $purpose + ['is_optional' => true, 'is_active' => true]);
        }
    }
}
