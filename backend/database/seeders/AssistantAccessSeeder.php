<?php

namespace Database\Seeders;

use App\Models\AssistantAccess;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Default assistant access per SDD Table 5.2 / SPMP Table 10.0: both roles may
 * use every query category. The Captain can narrow this later from the
 * Assistant Access screen; re-seeding never overrides the Captain's choices.
 */
class AssistantAccessSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Role::whereIn('name', ['captain', 'secretary'])->get() as $role) {
            foreach (AssistantAccess::CATEGORIES as $category) {
                AssistantAccess::firstOrCreate(
                    ['role_id' => $role->id, 'query_category' => $category],
                    ['is_enabled' => true]
                );
            }
        }
    }
}
