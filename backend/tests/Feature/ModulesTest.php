<?php

namespace Tests\Feature;

use App\Models\AiSuggestion;
use App\Models\Backup;
use App\Models\ClassificationType;
use App\Models\ConsentPurpose;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentClassification;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillType;
use App\Models\User;
use Database\Seeders\AssistantAccessSeeder;
use Database\Seeders\ClassificationTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SkillTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verification of the SRS/SDD modules B2–B4, C1–C3, D1, D3, E1, F1–F2, G1–G2
 * (SPMP §5.7 verification methods: functional and role-based tests).
 */
class ModulesTest extends TestCase
{
    use RefreshDatabase;

    private User $secretary;

    private User $captain;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, AssistantAccessSeeder::class,
            ClassificationTypeSeeder::class, SkillTypeSeeder::class, ReferenceDataSeeder::class]);

        $make = fn (string $role) => User::create([
            'full_name' => ucfirst($role), 'username' => $role, 'email' => "{$role}@test.local", 'password' => 'Password123!',
            'role_id' => Role::where('name', $role)->value('id'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $this->secretary = $make('secretary');
        $this->captain = $make('captain');
        $this->purok = Purok::create(['name' => 'Purok 1', 'purok_number' => 1]);
    }

    private function as(User $user): self
    {
        Sanctum::actingAs($user, ['staff']);

        return $this;
    }

    private function resident(array $attrs = []): Resident
    {
        return Resident::factory()->create(['purok_id' => $this->purok->id, 'status' => 'active'] + $attrs);
    }

    private function household(array $residents): Household
    {
        $h = Household::create(['household_number' => 'H-'.uniqid(), 'purok_id' => $this->purok->id]);
        foreach ($residents as $i => $r) {
            HouseholdMember::create(['household_id' => $h->id, 'resident_id' => $r->id, 'is_head' => $i === 0, 'relationship_to_head' => $i === 0 ? 'head' : 'child', 'date_joined' => now()->subYear()]);
        }

        return $h;
    }

    // ------------------------------------------------------------- B2 / B4

    public function test_relief_plan_is_ranked_flagged_approved_by_the_captain_and_claimed_once(): void
    {
        $senior = ClassificationType::where('name', 'Senior Citizen')->first();
        $priority = $this->resident(['birth_date' => now()->subYears(70)]);
        ResidentClassification::create(['resident_id' => $priority->id, 'classification_type_id' => $senior->id, 'status' => 'active', 'date_granted' => now()]);
        $hPriority = $this->household([$priority]);
        $hOther = $this->household([$this->resident()]);
        \App\Models\SocialAssistanceRecord::create(['resident_id' => $hOther->members()->first()->resident_id, 'assistance_type' => 'livelihood', 'program_name' => 'Livelihood Aid', 'date_received' => now()->subDays(10)]);

        $this->as($this->secretary);
        $id = $this->postJson('/api/admin/relief', [
            'title' => 'Typhoon response', 'goods_description' => 'Food pack', 'quantity_available' => 1, 'quantity_per_beneficiary' => 1,
            'target_rule' => ['type' => 'all'], 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
        ])->assertCreated()->json('data.id');
        $list = $this->postJson("/api/admin/relief/{$id}/generate")->assertOk()->json('data.beneficiaries');

        $this->assertSame($hPriority->id, $list[0]['household_id']);   // senior household ranked first
        $this->assertTrue($list[0]['within_allocation']);
        $this->assertFalse($list[1]['within_allocation']);              // supply of 1
        $this->assertNotEmpty($list[1]['duplicate_flag']);               // flagged, not excluded

        $this->postJson("/api/admin/relief/{$id}/submit")->assertOk();
        $this->postJson("/api/admin/relief/{$id}/decide", ['approve' => true])->assertForbidden(); // Secretary cannot approve
        $this->as($this->captain)->postJson("/api/admin/relief/{$id}/decide", ['approve' => true])->assertOk()->assertJsonPath('data.distribution.status', 'open');

        $beneficiaryId = $list[0]['id'];
        $this->as($this->secretary)->postJson("/api/admin/relief-beneficiaries/{$beneficiaryId}/claim", ['claimant_name' => 'Head'])->assertOk();
        $this->postJson("/api/admin/relief-beneficiaries/{$beneficiaryId}/claim", ['claimant_name' => 'Head'])->assertStatus(422); // one claim per household
        $this->postJson("/api/admin/relief-beneficiaries/{$list[1]['id']}/claim", ['claimant_name' => 'X'])->assertStatus(422);   // outside allocation
    }

    public function test_relief_list_is_automatic_eligible_only_and_segregated_by_purok(): void
    {
        $purok2 = Purok::create(['name' => 'Purok 2', 'purok_number' => 2]);
        $senior = ClassificationType::where('name', 'Senior Citizen')->value('id');
        $vulnerable = function (Purok $purok) use ($senior) {
            $r = Resident::factory()->create(['purok_id' => $purok->id, 'status' => 'active']);
            ResidentClassification::create(['resident_id' => $r->id, 'classification_type_id' => $senior, 'status' => 'active', 'date_granted' => now()]);
            $h = Household::create(['household_number' => 'H-'.uniqid(), 'purok_id' => $purok->id]);
            HouseholdMember::create(['household_id' => $h->id, 'resident_id' => $r->id, 'is_head' => true, 'relationship_to_head' => 'head', 'date_joined' => now()->subYear()]);
        };
        // Purok 1: 6 eligible households; Purok 2: 2 eligible; plus 1 household with no vulnerable member.
        for ($i = 0; $i < 6; $i++) {
            $vulnerable($this->purok);
        }
        $vulnerable($purok2);
        $vulnerable($purok2);
        $this->household([$this->resident()]);

        $this->as($this->secretary);
        $id = $this->postJson('/api/admin/relief', [
            'title' => 'Food packs', 'goods_description' => 'Food pack', 'quantity_available' => 4, 'quantity_per_beneficiary' => 1,
            'target_rule' => ['type' => 'priority'], 'allocation_mode' => 'per_purok',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
        ])->assertCreated()->json('data.id');

        // Automatic: the list exists without pressing "generate".
        $data = $this->getJson("/api/admin/relief/{$id}")->assertOk()->json('data');
        $this->assertCount(8, $data['beneficiaries']); // the household without a vulnerable member is not eligible

        // Fair share of 4 packs: Purok 1 has 6/8 of eligible households → 3, Purok 2 has 2/8 → 1.
        $summary = collect($data['by_purok'])->keyBy('purok');
        $this->assertSame(['eligible' => 6, 'served' => 3, 'quantity_needed' => 3], collect($summary['Purok 1'])->only('eligible', 'served', 'quantity_needed')->all());
        $this->assertSame(['eligible' => 2, 'served' => 1, 'quantity_needed' => 1], collect($summary['Purok 2'])->only('eligible', 'served', 'quantity_needed')->all());

        // Barangay-wide priority mode would not guarantee Purok 2 a share; switching re-ranks the draft.
        $this->patchJson("/api/admin/relief/{$id}", ['allocation_mode' => 'priority'])->assertOk();
        $this->assertSame(4, collect($this->getJson("/api/admin/relief/{$id}")->json('data.by_purok'))->sum('served'));
    }

    public function test_purok_quotas_use_largest_remainders_and_never_exceed_a_purok(): void
    {
        $service = app(\App\Services\ReliefService::class);
        $this->assertSame([1 => 3, 2 => 1], $service->purokQuotas([1 => 6, 2 => 2], 4));
        $this->assertSame([1 => 2, 2 => 2, 3 => 1], $service->purokQuotas([1 => 5, 2 => 4, 3 => 1], 5));
        $this->assertSame([1 => 6, 2 => 2], $service->purokQuotas([1 => 6, 2 => 2], 100)); // supply covers everyone
        $this->assertSame(5, array_sum($service->purokQuotas([1 => 1, 2 => 1, 3 => 10], 5)));
        // Every purok gets at least one when there is enough for all of them.
        $this->assertSame([1 => 1, 2 => 1, 3 => 1], $service->purokQuotas([1 => 50, 2 => 1, 3 => 1], 3));
        // Fewer packs than puroks: shares follow size, no purok exceeds its eligible count.
        $q = $service->purokQuotas([1 => 50, 2 => 1, 3 => 1], 2);
        $this->assertSame(2, array_sum($q));
        $this->assertSame(2, $q[1]);
        // Random cases: total always equals the supply (or all eligible) and never exceeds a purok.
        mt_srand(7);
        for ($i = 0; $i < 200; $i++) {
            $eligible = [];
            foreach (range(1, mt_rand(1, 9)) as $p) {
                $eligible[$p] = mt_rand(0, 40);
            }
            $cap = mt_rand(0, 120);
            $quotas = $service->purokQuotas($eligible, $cap);
            $this->assertSame(min($cap, array_sum($eligible)), array_sum($quotas));
            foreach ($quotas as $p => $n) {
                $this->assertLessThanOrEqual($eligible[$p], $n);
            }
        }
    }

    // ------------------------------------------------------------------ A1

    public function test_detailed_registration_saves_every_field_and_validates_them(): void
    {
        $this->as($this->secretary);
        $body = [
            'last_name' => 'Bautista', 'first_name' => 'Ana', 'middle_name' => 'Lim', 'suffix' => null, 'sex' => 'female',
            'birth_date' => '1990-05-12', 'birth_place' => 'Tagbilaran City, Bohol', 'civil_status' => 'married', 'citizenship' => 'Filipino',
            'religion' => 'Roman Catholic', 'purok_id' => $this->purok->id, 'house_number' => '12', 'street' => 'Sitio Mabuhay',
            'residency_start_date' => '2015-01-01', 'voter_status' => 'registered', 'contact_number' => '09171234567', 'email' => 'ana@example.com',
            'occupation' => 'Teacher', 'employment_status' => 'employed', 'monthly_income_bracket' => '10,000–20,000',
            'educational_attainment' => 'college_graduate', 'remarks' => 'Walk-in registration',
        ];

        $bad = $this->postJson('/api/admin/residents', ['contact_number' => '12345', 'residency_start_date' => '1980-01-01', 'monthly_income_bracket' => 'lots'] + $body)
            ->assertStatus(422)->json('errors');
        $this->assertArrayHasKey('contact_number', $bad);
        $this->assertArrayHasKey('residency_start_date', $bad);
        $this->assertArrayHasKey('monthly_income_bracket', $bad);

        $saved = $this->postJson('/api/admin/residents', $body)->assertCreated()->json('data');
        foreach (['birth_place', 'religion', 'citizenship', 'house_number', 'street', 'residency_start_date', 'voter_status', 'contact_number', 'email', 'monthly_income_bracket', 'educational_attainment', 'remarks'] as $field) {
            $this->assertSame($body[$field], $saved[$field], $field);
        }
    }

    // ------------------------------------------------------------------ B3

    public function test_population_events_update_the_registry(): void
    {
        $mother = $this->resident(['sex' => 'female']);
        $household = $this->household([$mother]);
        $this->as($this->secretary);

        $this->postJson('/api/admin/population-events', ['event_type' => 'birth', 'event_date' => now()->toDateString(), 'last_name' => 'Cruz', 'first_name' => 'Baby', 'sex' => 'male', 'mother_resident_id' => $mother->id])->assertCreated();
        $baby = Resident::where('first_name', 'Baby')->firstOrFail();
        $this->assertTrue(HouseholdMember::where('household_id', $household->id)->where('resident_id', $baby->id)->whereNull('date_left')->exists());
        $this->assertDatabaseHas('relationships', ['resident_id' => $baby->id, 'related_resident_id' => $mother->id, 'relationship_type' => 'child']);

        $response = $this->postJson('/api/admin/population-events', ['event_type' => 'death', 'event_date' => now()->toDateString(), 'resident_id' => $mother->id])->assertCreated();
        $this->assertSame('deceased', $mother->fresh()->status);
        $this->assertStringContainsString('Promote a new household head', $response->json('warning'));
        $this->assertNotNull(HouseholdMember::where('resident_id', $mother->id)->value('date_left'));
    }

    // ------------------------------------------------------------------ E1

    public function test_record_copies_are_issued_and_logged(): void
    {
        $resident = $this->resident();
        $this->as($this->captain)->postJson("/api/admin/residents/{$resident->id}/record-copies", ['requested_by_name' => 'Self', 'id_presented' => 'PhilSys 1234', 'purpose' => 'Bank'])->assertCreated()
            ->assertJsonPath('data.resident.full_name', $resident->full_name);
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'record_copies', 'action' => 'create']);
    }

    public function test_certificates_and_correction_requests_were_removed(): void
    {
        $this->as($this->captain);
        $this->getJson('/api/admin/certificate-requests')->assertNotFound();
        $this->getJson('/api/admin/corrections')->assertNotFound();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('certificates'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('correction_requests'));
    }

    // ------------------------------------------------- G1 / G2 / F1 / F2

    public function test_consent_controls_analysis_and_matching_and_erasure_removes_derived_data(): void
    {
        $carpenter = $this->resident(['occupation' => 'Carpenter', 'employment_status' => null, 'birth_date' => now()->subYears(30)]);
        $other = $this->resident(['occupation' => 'Carpenter', 'employment_status' => null, 'birth_date' => now()->subYears(30)]);
        $optional = ConsentPurpose::where('code', 'optional_analytics')->value('id');
        $matching = ConsentPurpose::where('code', 'opportunity_matching')->value('id');

        $this->as($this->secretary);
        $this->postJson("/api/admin/residents/{$carpenter->id}/consents", ['consent_purpose_id' => $optional, 'status' => 'granted'])->assertOk();
        $this->postJson("/api/admin/residents/{$carpenter->id}/consents", ['consent_purpose_id' => $matching, 'status' => 'granted'])->assertOk();

        // F1: suggestions only for the consenting resident; confirmation changes the registry.
        $this->postJson('/api/admin/ai-suggestions/run')->assertOk();
        $this->assertSame(0, AiSuggestion::where('resident_id', $other->id)->count());
        $employment = AiSuggestion::where('resident_id', $carpenter->id)->where('suggestion_type', 'employment_status')->firstOrFail();
        $this->assertNull($carpenter->fresh()->employment_status);
        $this->postJson("/api/admin/ai-suggestions/{$employment->id}/decide", ['confirm' => true])->assertOk();
        $this->assertSame('employed', $carpenter->fresh()->employment_status);

        // F2: only verified skills of consenting residents are shortlisted.
        $carpentry = SkillType::where('name', 'Carpentry')->value('id');
        foreach ([$carpenter, $other] as $r) {
            Skill::create(['resident_id' => $r->id, 'skill_type_id' => $carpentry, 'is_verified' => true, 'source' => 'staff_encoded', 'status' => 'active']);
        }
        $opp = $this->postJson('/api/admin/opportunities', ['title' => 'Furniture shop helper', 'type' => 'job', 'required_skills' => [$carpentry], 'slots' => 2])->assertCreated()->json('data.id');
        $matches = $this->getJson("/api/admin/opportunities/{$opp}")->json('data.matches');
        $this->assertSame([$carpenter->id], array_column($matches, 'resident_id'));

        // G2: Secretary receives, Captain decides; erasure removes analytics-derived data.
        $dsr = $this->postJson("/api/admin/residents/{$carpenter->id}/data-subject-requests", ['request_type' => 'erasure', 'details' => 'Remove my analytics data'])->assertCreated()->json('data.id');
        $this->as($this->captain)->postJson("/api/admin/data-subject-requests/{$dsr}/decide", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(0, AiSuggestion::where('resident_id', $carpenter->id)->count());
        $this->assertSame(0, \App\Models\OpportunityMatch::where('resident_id', $carpenter->id)->count());
        $this->assertFalse(\App\Services\RecordRequestService::hasConsent($carpenter->id, 'opportunity_matching'));
        $this->assertSame('active', $carpenter->fresh()->status); // registry record kept
    }

    public function test_opportunity_shortlists_capable_residents_segregated_by_purok(): void
    {
        $purok2 = Purok::create(['name' => 'Purok 2', 'purok_number' => 2]);
        $purok3 = Purok::create(['name' => 'Purok 3', 'purok_number' => 3]);
        $welding = SkillType::where('name', 'Welding')->value('id');
        $consent = ConsentPurpose::where('code', 'opportunity_matching')->value('id');
        $make = function (Purok $purok, array $attrs = [], bool $verified = true, bool $consents = true) use ($welding, $consent) {
            $r = Resident::factory()->create($attrs + ['purok_id' => $purok->id, 'status' => 'active', 'birth_date' => now()->subYears(30), 'employment_status' => 'employed']);
            Skill::create(['resident_id' => $r->id, 'skill_type_id' => $welding, 'is_verified' => $verified, 'source' => 'staff_encoded', 'status' => 'active']);
            if ($consents) {
                \App\Models\ResidentConsent::create(['resident_id' => $r->id, 'consent_purpose_id' => $consent, 'status' => 'granted', 'recorded_at' => now()]);
            }

            return $r;
        };
        // Purok 1: 3 capable (one unemployed); Purok 2: 1 capable; plus residents who are not capable.
        $make($this->purok);
        $unemployed = $make($this->purok, ['employment_status' => 'unemployed']);
        $make($this->purok);
        $make($purok2);
        $make($this->purok, [], false);                              // skill not verified
        $make($this->purok, [], true, false);                        // no consent
        $make($this->purok, ['birth_date' => now()->subYears(16)]);  // under working age
        $make($purok3);                                              // outside the target puroks

        $this->as($this->secretary);
        $id = $this->postJson('/api/admin/opportunities', [
            'title' => 'Welder for barangay hall repair', 'type' => 'job', 'required_skills' => [$welding], 'slots' => 2,
            'target_purok_ids' => [$this->purok->id, $purok2->id], 'allocation_mode' => 'per_purok',
        ])->assertCreated()->json('data.id');

        $data = $this->getJson("/api/admin/opportunities/{$id}")->assertOk()->json('data');
        $this->assertCount(4, $data['matches']);
        $summary = collect($data['by_purok'])->keyBy('purok');
        $this->assertSame(['capable' => 3, 'within_slots' => 1], collect($summary['Purok 1'])->only('capable', 'within_slots')->all());
        $this->assertSame(['capable' => 1, 'within_slots' => 1], collect($summary['Purok 2'])->only('capable', 'within_slots')->all());
        $this->assertArrayNotHasKey('Purok 3', $summary->all());
        // Within Purok 1, the unemployed resident is ranked first and gets the purok's slot.
        $first = collect($data['matches'])->firstWhere('purok_rank', 1);
        $this->assertSame($unemployed->id, collect($data['matches'])->where('purok', 'Purok 1')->firstWhere('purok_rank', 1)['resident_id']);
        $this->assertTrue($first['within_slots']);

        // Barangay-wide mode gives both slots to the best-ranked residents overall.
        $this->patchJson("/api/admin/opportunities/{$id}", ['allocation_mode' => 'priority'])->assertOk();
        $this->assertSame(2, collect($this->getJson("/api/admin/opportunities/{$id}")->json('data.matches'))->where('within_slots', true)->count());
    }

    // ------------------------------------------------------------- C1–C3

    public function test_reports_forecasts_and_dashboard_action_list(): void
    {
        $this->resident();
        $this->as($this->captain);
        foreach (array_keys(\App\Http\Controllers\Admin\ReportEngineController::REPORTS) as $report) {
            $this->getJson("/api/admin/report-engine/{$report}")->assertOk()->assertJsonStructure(['data' => ['header', 'title', 'parameters', 'generated_at', 'generated_by', 'columns', 'rows']]);
        }
        $this->get('/api/admin/report-engine/masterlist?format=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertDatabaseHas('audit_logs', ['action' => 'report_exported']);

        // The four report tabs of the Reports page, including achievements with mixed categories.
        $resident = Resident::first();
        foreach ([null, '', 'Sports', 'Sports'] as $category) {
            \App\Models\Achievement::create(['resident_id' => $resident->id, 'title' => 'Award', 'category' => $category]);
        }
        foreach (['skills', 'employment', 'beneficiaries', 'achievements'] as $tab) {
            $this->getJson("/api/admin/reports/{$tab}")->assertOk();
        }
        $this->getJson('/api/admin/reports/achievements')->assertJsonPath('data.by_category', [
            ['category' => 'Uncategorized', 'count' => 2], ['category' => 'Sports', 'count' => 2],
        ]);

        $this->postJson('/api/admin/forecasts/generate')->assertOk()->assertJsonPath('data.puroks.Purok 1.population', 1);
        $this->getJson('/api/admin/dashboard/summary')->assertOk()->assertJsonStructure(['data' => ['action_list']]);
    }

    // ------------------------------------------------------------- D1 / D3

    public function test_captain_manages_accounts_and_backups(): void
    {
        $this->as($this->secretary)->getJson('/api/admin/users')->assertForbidden();
        $this->getJson('/api/admin/backups')->assertForbidden();

        $this->as($this->captain);
        $created = $this->postJson('/api/admin/users', ['full_name' => 'New Secretary', 'username' => 'sec2', 'email' => 'sec2@test.local', 'role_id' => $this->secretary->role_id])->assertCreated();
        $this->assertTrue(User::where('username', 'sec2')->value('must_change_password'));
        $this->assertNotEmpty($created->json('data.temporary_password'));
        $this->patchJson("/api/admin/users/{$this->captain->id}", ['is_active' => false])->assertStatus(422); // cannot lock yourself out

        $backup = $this->postJson('/api/admin/backups')->json('data');
        $this->assertSame('success', $backup['status'], $backup['error_message'] ?? '');
        $this->assertSame(64, strlen($backup['checksum']));
        $sql = gzdecode($this->get("/api/admin/backups/{$backup['id']}/download")->assertOk()->getContent());
        $this->assertStringContainsString('CREATE TABLE `residents`', $sql);
        @unlink(Backup::find($backup['id'])->storage_path);
    }
}
