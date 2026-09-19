<?php

namespace Database\Seeders;

use App\Models\SkillType;
use Illuminate\Database\Seeder;

/**
 * The skill taxonomy named explicitly in the Design Addendum §2.1 (A6).
 */
class SkillTypeSeeder extends Seeder
{
    public function run(): void
    {
        $skills = [
            'Carpentry', 'Tailoring', 'Driving', 'Cooking and Catering',
            'Electrical Work', 'Masonry', 'Caregiving', 'Computer Literacy',
        ];

        foreach ($skills as $name) {
            SkillType::updateOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
