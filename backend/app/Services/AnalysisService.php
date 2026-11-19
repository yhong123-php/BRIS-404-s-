<?php

namespace App\Services;

use App\Models\AiSuggestion;
use App\Models\ClassificationType;
use App\Models\DemographicForecast;
use App\Models\Household;
use App\Models\Opportunity;
use App\Models\OpportunityMatch;
use App\Models\PopulationEvent;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentClassification;
use App\Models\Skill;
use App\Models\SkillType;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F1 — AI Resident Analysis Engine (SDD §4.17), F2 — Livelihood and job
 * opportunity matching (SDD §4.18), and C3 — Predictive and demographic
 * dashboard projections (SDD §4.20).
 *
 * The engine only writes suggestions; it never edits the registry. Every
 * suggestion carries its rationale and engine version, and only suggestions
 * the Secretary confirms change the registry.
 */
class AnalysisService
{
    public const ENGINE_VERSION = 'bris-rules-1.0';

    /** Occupation keywords → skill category of the A6 reference list. */
    private const SKILL_KEYWORDS = [
        'Carpentry' => ['carpenter', 'karpintero', 'furniture'],
        'Welding' => ['welder', 'welding'],
        'Electrical Work' => ['electrician', 'electrical', 'lineman'],
        'Plumbing' => ['plumber', 'plumbing', 'tubero'],
        'Driving' => ['driver', 'drayber', 'tricycle', 'jeepney', 'habal'],
        'Cooking and Catering' => ['cook', 'chef', 'catering', 'baker', 'kusinero'],
        'Masonry' => ['mason', 'construction worker', 'panday'],
        'Tailoring' => ['tailor', 'seamstress', 'dressmaker', 'sastre'],
        'Caregiving' => ['caregiver', 'nurse aide', 'yaya', 'midwife'],
        'Computer Literacy' => ['encoder', 'computer', 'clerk', 'it ', 'programmer', 'call center'],
    ];

    private const SELF_EMPLOYED_KEYWORDS = ['owner', 'vendor', 'farmer', 'fisherman', 'fisher', 'sari-sari', 'entrepreneur', 'business', 'tricycle', 'freelance'];

    // ================================================================ F1

    /** @return array<string,int> suggestions created per type */
    public function runResidentAnalysis(): array
    {
        $created = ['eligibility' => 0, 'employment_status' => 0, 'skill_category' => 0];
        $optionalConsent = RecordRequestService::consentingResidentIds('optional_analytics')->flip();
        $senior = ClassificationType::where('name', 'Senior Citizen')->first();
        $skillTypes = SkillType::pluck('id', 'name');

        Resident::where('status', 'active')->with('purok')->chunkById(200, function ($residents) use (&$created, $optionalConsent, $senior, $skillTypes) {
            foreach ($residents as $resident) {
                // Mandatory eligibility check — does not depend on consent (SDD §4.17 business rules).
                if ($senior && $resident->age !== null && $resident->age >= ($senior->min_age ?? 60)
                    && ! ResidentClassification::where('resident_id', $resident->id)->where('classification_type_id', $senior->id)->where('status', 'active')->exists()) {
                    $created['eligibility'] += $this->suggest($resident, 'eligibility', ['classification_type_id' => $senior->id, 'classification' => 'Senior Citizen'],
                        "Age {$resident->age} meets the Senior Citizen minimum age of ".($senior->min_age ?? 60).'. Confirm after checking the OSCA ID.', 'Senior Citizen');
                }

                // Optional analysis — only for residents who consented (SRS §3.5.6).
                if (! isset($optionalConsent[$resident->id])) {
                    continue;
                }

                if ($resident->employment_status === null) {
                    [$status, $why] = $this->employmentFor($resident);
                    if ($status) {
                        $created['employment_status'] += $this->suggest($resident, 'employment_status', ['employment_status' => $status], $why);
                    }
                }

                $occupation = mb_strtolower((string) $resident->occupation);
                foreach (self::SKILL_KEYWORDS as $skill => $keywords) {
                    if ($occupation === '' || ! isset($skillTypes[$skill])) {
                        continue;
                    }
                    $matched = collect($keywords)->first(fn ($k) => str_contains($occupation.' ', $k));
                    if ($matched && ! Skill::where('resident_id', $resident->id)->where('skill_type_id', $skillTypes[$skill])->where('status', 'active')->exists()) {
                        $created['skill_category'] += $this->suggest($resident, 'skill_category', ['skill_type_id' => $skillTypes[$skill], 'skill' => $skill],
                            "Declared occupation \"{$resident->occupation}\" matches the {$skill} category (keyword \"".trim($matched).'"). Verify with a supporting document.');
                    }
                }
            }
        });

        Audit::log('ai_resident_analysis_run', 'F1', 'ai_suggestions', null, $created);

        return $created;
    }

    private function employmentFor(Resident $resident): array
    {
        $occupation = trim(mb_strtolower((string) $resident->occupation));
        $age = $resident->age;
        $unemployedClass = ResidentClassification::where('resident_id', $resident->id)->where('status', 'active')
            ->whereHas('classificationType', fn ($q) => $q->where('name', 'Unemployed'))->exists();

        if ($unemployedClass) {
            return ['unemployed', 'The resident has an active "Unemployed" classification.'];
        }
        if ($occupation !== '' && ! in_array($occupation, ['none', 'n/a', 'unemployed', 'student', 'retired', 'housewife', 'homemaker'], true)) {
            $self = collect(self::SELF_EMPLOYED_KEYWORDS)->first(fn ($k) => str_contains($occupation, $k));

            return $self
                ? ['self_employed', "Declared occupation \"{$resident->occupation}\" is usually self-employment (keyword \"{$self}\")."]
                : ['employed', "Declared occupation \"{$resident->occupation}\"."];
        }
        if (in_array($occupation, ['student', 'retired', 'housewife', 'homemaker'], true) || ($age !== null && ($age < 15 || $age >= 65))) {
            return ['not_in_labor_force', $occupation !== '' ? "Declared occupation \"{$resident->occupation}\"." : "Age {$age} is outside the working-age range (15–64)."];
        }

        return [null, null];
    }

    private function suggest(Resident $resident, string $type, array $payload, string $rationale, ?string $program = null): int
    {
        $exists = AiSuggestion::where('resident_id', $resident->id)->where('suggestion_type', $type)
            ->whereIn('status', ['pending', 'rejected'])->get()->contains(fn ($s) => $s->payload == $payload);
        if ($exists) {
            return 0;
        }

        AiSuggestion::create([
            'resident_id' => $resident->id, 'suggestion_type' => $type, 'program_name' => $program,
            'payload' => $payload, 'rationale' => $rationale, 'engine_version' => self::ENGINE_VERSION, 'status' => 'pending',
        ]);

        return 1;
    }

    /** Only confirmed suggestions change the registry; each decision is audited. */
    public function decideSuggestion(AiSuggestion $suggestion, bool $confirm, ?array $extra = []): AiSuggestion
    {
        if ($suggestion->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'This suggestion has already been reviewed.']);
        }

        DB::transaction(function () use ($suggestion, $confirm, $extra) {
            if ($confirm) {
                $resident = $suggestion->resident;
                match ($suggestion->suggestion_type) {
                    'employment_status' => $resident->update(['employment_status' => $suggestion->payload['employment_status'], 'updated_by' => Auth::id()]),
                    'skill_category' => Skill::create([
                        'resident_id' => $resident->id, 'skill_type_id' => $suggestion->payload['skill_type_id'],
                        'is_verified' => false, 'source' => 'staff_encoded', 'status' => 'active',
                    ]),
                    'eligibility' => app(ClassificationService::class)->assign($resident->id, $suggestion->payload['classification_type_id'], $extra ?? []),
                    default => null,
                };
            }
            $suggestion->update(['status' => $confirm ? 'confirmed' : 'rejected', 'reviewed_by' => Auth::id(), 'reviewed_at' => now()]);
        });

        return $suggestion;
    }

    // ================================================================ F2

    /**
     * Ranks verified-skill, active, consenting residents by the number of
     * required skills they hold (SDD §4.18).
     */
    public function matchOpportunity(Opportunity $opportunity): int
    {
        if ($opportunity->status !== 'open') {
            throw ValidationException::withMessages(['status' => 'Only open opportunities can be matched.']);
        }
        $required = array_map('intval', $opportunity->required_skills);
        $consenting = RecordRequestService::consentingResidentIds('opportunity_matching');
        $puroks = $opportunity->target_purok_ids ? array_map('intval', $opportunity->target_purok_ids) : null;

        // Capable = active, within the working-age range, in a target purok, consenting,
        // and holding at least one required skill that has been verified (SDD §4.18).
        $counts = Skill::whereIn('skill_type_id', $required)->where('status', 'active')->where('is_verified', true)
            ->whereIn('resident_id', $consenting)
            ->whereHas('resident', fn ($q) => $q->where('status', 'active')
                ->where('birth_date', '<=', now()->subYears($opportunity->min_age ?? 18)->toDateString())
                ->where('birth_date', '>', now()->subYears(($opportunity->max_age ?? 64) + 1)->toDateString())
                ->when($puroks, fn ($r) => $r->whereIn('purok_id', $puroks)))
            ->select('resident_id', DB::raw('count(distinct skill_type_id) as held'))
            ->groupBy('resident_id')->get();

        // Most required skills first; among equals, unemployed residents first.
        $residents = Resident::whereIn('id', $counts->pluck('resident_id'))->get(['id', 'purok_id', 'employment_status'])->keyBy('id');
        $need = ['unemployed' => 0, 'not_in_labor_force' => 1, null => 2, 'self_employed' => 3, 'employed' => 4];
        $ranked = $counts->sortBy(fn ($row) => [
            -$row->held,
            $need[$residents[$row->resident_id]->employment_status] ?? 2,
            $row->resident_id,
        ])->values();

        // Slots: barangay-wide best first, or a fair share per purok.
        $slots = max(1, (int) $opportunity->slots);
        $quotas = $opportunity->allocation_mode === 'per_purok'
            ? app(ReliefService::class)->purokQuotas($ranked->countBy(fn ($row) => $residents[$row->resident_id]->purok_id ?? 0)->all(), $slots)
            : null;

        DB::transaction(function () use ($opportunity, $ranked, $required, $residents, $slots, $quotas) {
            $opportunity->matches()->where('outcome', 'shortlisted')->whereNotIn('resident_id', $ranked->pluck('resident_id'))->delete();
            $purokRank = [];
            foreach ($ranked as $i => $row) {
                $purokId = $residents[$row->resident_id]->purok_id;
                $key = $purokId ?? 0;
                $purokRank[$key] = ($purokRank[$key] ?? 0) + 1;
                OpportunityMatch::updateOrCreate(
                    ['opportunity_id' => $opportunity->id, 'resident_id' => $row->resident_id],
                    [
                        'purok_id' => $purokId,
                        'score' => round($row->held / max(1, count($required)) * 100, 2),
                        'rank' => $i + 1,
                        'purok_rank' => $purokRank[$key],
                        'within_slots' => $quotas === null ? $i < $slots : $purokRank[$key] <= ($quotas[$key] ?? 0),
                        'generated_at' => now(),
                    ]
                );
            }
        });

        return $ranked->count();
    }

    /** Per-purok summary of an opportunity's shortlist. */
    public function opportunityPurokSummary(Opportunity $opportunity): array
    {
        $names = Purok::pluck('name', 'id');

        return $opportunity->matches()->get()->groupBy(fn ($m) => $m->purok_id ?? 0)
            ->map(fn ($group, $purokId) => [
                'purok_id' => $purokId ?: null,
                'purok' => $names[$purokId] ?? 'No purok',
                'capable' => $group->count(),
                'within_slots' => $group->where('within_slots', true)->count(),
                'contacted' => $group->whereIn('outcome', ['contacted', 'interested', 'hired'])->count(),
                'hired' => $group->where('outcome', 'hired')->count(),
            ])
            ->sortBy(fn ($row) => $row['purok_id'] === null ? "\u{FFFF}" : $row['purok'], SORT_NATURAL)
            ->values()->all();
    }

    // ================================================================ C3

    /**
     * Projections per purok from the last 12 months of population events
     * (SDD §4.20): next 12 months = current + 12-month net change (linear trend).
     * Stored with method, period, and input range; always labelled as projections.
     */
    public function generateForecasts(): int
    {
        $to = now()->toDateString();
        $from = now()->subYear()->toDateString();
        $period = now()->addMonth()->format('Y-m').' to '.now()->addYear()->format('Y-m');
        $method = 'Linear trend: current count + net change over the previous 12 months of recorded births, deaths, and transfers';
        $rows = 0;

        DB::transaction(function () use ($from, $to, $period, $method, &$rows) {
            DemographicForecast::query()->delete();
            foreach (Purok::all()->concat([null]) as $purok) {
                $residents = Resident::where('status', 'active')->when($purok, fn ($q) => $q->where('purok_id', $purok->id))->count();
                $households = Household::when($purok, fn ($q) => $q->where('purok_id', $purok->id))->count();
                $events = PopulationEvent::whereBetween('event_date', [$from, $to])->when($purok, fn ($q) => $q->where('purok_id', $purok->id))
                    ->select('event_type', DB::raw('count(*) as c'))->groupBy('event_type')->pluck('c', 'event_type');
                $net = ($events['birth'] ?? 0) + ($events['transfer_in'] ?? 0) - ($events['death'] ?? 0) - ($events['transfer_out'] ?? 0);
                $perHousehold = $households > 0 ? $residents / $households : null;
                $projectedPopulation = max(0, $residents + $net);
                $projectedHouseholds = $perHousehold ? round($projectedPopulation / $perHousehold, 1) : $households;

                foreach ([
                    'population' => $projectedPopulation,
                    'households' => $projectedHouseholds,
                    'births' => $events['birth'] ?? 0,
                    'deaths' => $events['death'] ?? 0,
                    // Relief demand: projected households × 1 unit per household (B2 default).
                    'relief_demand' => $projectedHouseholds,
                ] as $metric => $value) {
                    DemographicForecast::create([
                        'purok_id' => $purok?->id, 'metric' => $metric, 'period' => $period, 'projected_value' => $value,
                        'method' => $metric === 'relief_demand' ? 'Projected households × 1 relief unit per household' : $method,
                        'input_from' => $from, 'input_to' => $to, 'generated_at' => now(),
                    ]);
                    $rows++;
                }
            }
        });

        return $rows;
    }
}
