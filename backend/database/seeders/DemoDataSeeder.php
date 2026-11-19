<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Models\ClassificationType;
use App\Models\ConsentPurpose;
use App\Models\Family;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Purok;
use App\Models\Relationship;
use App\Models\Resident;
use App\Models\ResidentClassification;
use App\Models\ResidentConsent;
use App\Models\Skill;
use App\Models\SkillType;
use App\Models\SocialAssistanceRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Realistic FAKE data for demonstrations, screenshots, and testing — never for a
 * database holding real residents. Run only against an empty demo database:
 *
 *   php artisan migrate:fresh --seed --seeder=DemoDataSeeder   (with DB_DATABASE=bris_demo)
 *
 * Creates households of 2–6 members in every purok, with heads, spouses, and
 * children; sector classifications; verified and unverified skills; consents;
 * social assistance; achievements; and a family record.
 */
class DemoDataSeeder extends Seeder
{
    private const HOUSEHOLDS_PER_PUROK = 9;

    private const SURNAMES = ['Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Bautista', 'Castillo', 'Villanueva', 'Ramos', 'Flores',
        'Aquino', 'Cabrera', 'Torres', 'Navarro', 'Gonzales', 'Lim', 'Tan', 'Alvarez', 'Pacquiao', 'Maglasang', 'Bohol', 'Cagas', 'Abella', 'Lumayag'];

    private const MALE = ['Juan', 'Jose', 'Pedro', 'Mark', 'John Paul', 'Rogelio', 'Ernesto', 'Ramon', 'Carlo', 'Jericho', 'Danilo', 'Arnel', 'Kevin', 'Joel'];

    private const FEMALE = ['Maria', 'Ana', 'Rosa', 'Liza', 'Grace', 'Joy', 'Cristina', 'Mae', 'Shiela', 'Rowena', 'Angelica', 'Nenita', 'Lorna', 'Divina'];

    private const OCCUPATIONS = ['Farmer', 'Fisherman', 'Vendor', 'Tricycle Driver', 'Carpenter', 'Teacher', 'Housekeeper', 'Construction Worker',
        'Sari-sari Store Owner', 'Welder', 'Electrician', 'Seamstress', 'Barangay Health Worker', 'Cook'];

    public function run(): void
    {
        $this->call([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, AdminUserSeeder::class, AssistantAccessSeeder::class,
            ClassificationTypeSeeder::class, SkillTypeSeeder::class, PurokSeeder::class, ReferenceDataSeeder::class]);

        mt_srand(2026);
        $types = ClassificationType::pluck('id', 'name');
        $skills = SkillType::pluck('id', 'name');
        $purposes = ConsentPurpose::pluck('id', 'code');
        $seq = 0;

        DB::transaction(function () use ($types, $skills, $purposes, &$seq) {
            foreach (Purok::orderBy('name')->get() as $p => $purok) {
                for ($h = 1; $h <= self::HOUSEHOLDS_PER_PUROK; $h++) {
                    $surname = self::SURNAMES[mt_rand(0, count(self::SURNAMES) - 1)];
                    $household = Household::create([
                        'household_number' => sprintf('HH-%s-%02d', str_replace('Purok ', '', $purok->name), $h),
                        'purok_id' => $purok->id,
                        'house_number' => (string) mt_rand(1, 250),
                        'street' => ['Sitio Mabuhay', 'Sitio Ilaya', 'Sitio Ubos', 'Riverside', 'Centro'][mt_rand(0, 4)],
                        'dwelling_ownership' => ['owned', 'owned', 'rented', 'shared'][mt_rand(0, 3)],
                        'construction_type' => ['concrete', 'semi_concrete', 'wood', 'light_materials'][mt_rand(0, 3)],
                        'toilet_type' => ['Water-sealed', 'Water-sealed', 'Shared'][mt_rand(0, 2)],
                        'water_source' => ['Level III (piped)', 'Level II (communal)', 'Deep well'][mt_rand(0, 2)],
                        'electricity_access' => mt_rand(0, 9) > 0,
                        'status' => 'active',
                    ]);

                    $size = mt_rand(2, 6);
                    $members = [];
                    for ($m = 0; $m < $size; $m++) {
                        $role = $m === 0 ? 'head' : ($m === 1 ? 'spouse' : (mt_rand(0, 6) ? 'child' : 'parent'));
                        $sex = $m === 0 ? 'male' : ($m === 1 ? 'female' : (mt_rand(0, 1) ? 'male' : 'female'));
                        $age = match ($role) {
                            'head' => mt_rand(28, 72), 'spouse' => mt_rand(25, 70), 'parent' => mt_rand(66, 88), default => mt_rand(0, 24),
                        };
                        $working = $age >= 18 && $age < 65;
                        $occupation = $working && mt_rand(0, 3) ? self::OCCUPATIONS[mt_rand(0, count(self::OCCUPATIONS) - 1)] : null;
                        $resident = Resident::create([
                            'resident_number' => sprintf('DEMO-%05d', ++$seq),
                            'last_name' => $surname,
                            'first_name' => ($sex === 'male' ? self::MALE : self::FEMALE)[mt_rand(0, 13)],
                            'middle_name' => self::SURNAMES[mt_rand(0, count(self::SURNAMES) - 1)],
                            'sex' => $sex,
                            'birth_date' => now()->subYears($age)->subDays(mt_rand(0, 364))->toDateString(),
                            'birth_place' => mt_rand(0, 3) ? 'Pilar, Bohol' : 'Tagbilaran City, Bohol',
                            'civil_status' => in_array($role, ['head', 'spouse'], true) ? 'married' : ($role === 'parent' ? 'widowed' : 'single'),
                            'citizenship' => 'Filipino',
                            'religion' => mt_rand(0, 5) ? 'Roman Catholic' : 'Iglesia ni Cristo',
                            'occupation' => $occupation,
                            'employment_status' => $age < 15 || $age >= 65 ? 'not_in_labor_force'
                                : ($occupation ? (in_array($occupation, ['Farmer', 'Fisherman', 'Vendor', 'Sari-sari Store Owner', 'Tricycle Driver'], true) ? 'self_employed' : 'employed')
                                : ($age >= 18 && mt_rand(0, 2) ? 'unemployed' : 'not_in_labor_force')),
                            'educational_attainment' => $age < 6 ? null : array_keys(Resident::EDUCATION_LEVELS)[min(8, mt_rand(1, $age < 13 ? 1 : ($age < 17 ? 3 : 8)))],
                            'monthly_income_bracket' => $role === 'head' ? Resident::INCOME_BRACKETS[mt_rand(0, 5)] : null,
                            'contact_number' => $age >= 18 ? '09'.mt_rand(10, 99).mt_rand(1000000, 9999999) : null,
                            'purok_id' => $purok->id,
                            'house_number' => $household->house_number,
                            'street' => $household->street,
                            'voter_status' => $age >= 18 ? 'registered' : null,
                            'residency_start_date' => now()->subYears(min($age, mt_rand(3, 30)))->toDateString(),
                            'status' => 'active',
                        ]);
                        HouseholdMember::create([
                            'household_id' => $household->id, 'resident_id' => $resident->id, 'is_head' => $m === 0,
                            'relationship_to_head' => $role, 'date_joined' => $resident->residency_start_date,
                        ]);
                        $members[] = [$resident, $age, $role];

                        // Sectors
                        if ($age >= 60 && mt_rand(0, 4)) {
                            $this->classify($resident, $types['Senior Citizen'], 'OSCA-'.mt_rand(10000, 99999));
                        }
                        if (mt_rand(0, 24) === 0) {
                            $this->classify($resident, $types['Person with Disability'], 'PWD-'.mt_rand(10000, 99999));
                        }
                        if ($age < 5 && mt_rand(0, 1)) {
                            $this->classify($resident, $types['Infant or Child'], null);
                        }
                        // Skills (most verified) for working-age residents
                        $skillFor = ['Carpenter' => 'Carpentry', 'Welder' => 'Welding', 'Electrician' => 'Electrical Work', 'Seamstress' => 'Tailoring',
                            'Cook' => 'Cooking and Catering', 'Construction Worker' => 'Masonry', 'Tricycle Driver' => 'Driving'];
                        $skill = $skillFor[$occupation] ?? ($working && mt_rand(0, 4) === 0 ? array_keys($skills->all())[mt_rand(0, $skills->count() - 1)] : null);
                        if ($skill) {
                            Skill::create(['resident_id' => $resident->id, 'skill_type_id' => $skills[$skill], 'proficiency_level' => ['beginner', 'intermediate', 'advanced', 'expert'][mt_rand(1, 3)],
                                'is_verified' => mt_rand(0, 4) > 0, 'verified_at' => now(), 'source' => 'staff_encoded', 'status' => 'active']);
                        }
                        // Consent from adults (most opt in)
                        if ($age >= 18) {
                            foreach ($purposes as $purposeId) {
                                if (mt_rand(0, 5)) {
                                    ResidentConsent::create(['resident_id' => $resident->id, 'consent_purpose_id' => $purposeId, 'status' => 'granted',
                                        'form_path' => 'Consent form No. '.mt_rand(1000, 9999), 'recorded_at' => now()->subDays(mt_rand(1, 200))]);
                                }
                            }
                        }
                    }

                    [$head] = $members[0];
                    // Household-level sectors and assistance
                    if (mt_rand(0, 4) === 0) {
                        $this->classify($head, $types['4Ps Member'], '4PS-'.mt_rand(100000, 999999));
                    }
                    if (mt_rand(0, 5) === 0) {
                        $this->classify($head, $types['Indigent'], null, 'Certificate of indigency on file');
                    }
                    if (mt_rand(0, 6) === 0) {
                        $this->classify($head, $types['Solo Parent'], 'SP-'.mt_rand(1000, 9999));
                    }
                    if (mt_rand(0, 3) === 0) {
                        SocialAssistanceRecord::create([
                            'resident_id' => $head->id, 'household_id' => $household->id,
                            'assistance_type' => ['medical', 'educational', 'livelihood'][mt_rand(0, 2)],
                            'program_name' => ['Medical Aid', 'Educational Assistance', 'Livelihood Starter Kit'][mt_rand(0, 2)],
                            'description_or_amount' => '₱'.number_format(mt_rand(10, 50) * 100), 'date_received' => now()->subDays(mt_rand(5, 300))->toDateString(),
                            'source' => ['DSWD', 'Municipal LGU', 'Barangay'][mt_rand(0, 2)],
                        ]);
                    }
                    if (isset($members[1]) && $members[1][2] === 'spouse') {
                        Relationship::create(['resident_id' => $head->id, 'related_resident_id' => $members[1][0]->id, 'relationship_type' => 'spouse', 'start_date' => now()->subYears(10)->toDateString()]);
                        Relationship::create(['resident_id' => $members[1][0]->id, 'related_resident_id' => $head->id, 'relationship_type' => 'spouse', 'start_date' => now()->subYears(10)->toDateString()]);
                    }
                    if ($h === 1) {
                        $family = Family::create(['family_name' => $surname.' Family', 'family_head_id' => $head->id]);
                        foreach (array_slice($members, 2) as [$child, , $role]) {
                            if ($role === 'child') {
                                Relationship::create(['resident_id' => $child->id, 'related_resident_id' => $head->id, 'relationship_type' => 'child', 'family_id' => $family->id]);
                                Relationship::create(['resident_id' => $head->id, 'related_resident_id' => $child->id, 'relationship_type' => 'parent', 'family_id' => $family->id]);
                            }
                        }
                    }
                    if (mt_rand(0, 5) === 0) {
                        Achievement::create(['resident_id' => $head->id, 'title' => ['NC II in Welding', 'Most Outstanding Farmer', 'TESDA Bread and Pastry', 'Barangay Service Award'][mt_rand(0, 3)],
                            'category' => ['Certification', 'Award', 'Training', 'Award'][mt_rand(0, 3)], 'issuing_body' => ['TESDA', 'Municipality of Pilar', 'DepEd'][mt_rand(0, 2)],
                            'date_achieved' => now()->subDays(mt_rand(30, 900))->toDateString()]);
                    }
                }
            }
        });

        $this->command?->info('Demo data created: '.Resident::count().' residents in '.Household::count().' households. Log in as captain / secretary with ChangeMe123!.');
    }

    private function classify(Resident $resident, int $typeId, ?string $ref, ?string $doc = 'ID on file'): void
    {
        ResidentClassification::firstOrCreate(
            ['resident_id' => $resident->id, 'classification_type_id' => $typeId, 'status' => 'active'],
            ['reference_number' => $ref, 'document_path' => $doc, 'date_granted' => now()->subDays(mt_rand(30, 900))->toDateString()]
        );
    }
}
