<?php

namespace App\Services\Assistant;

use App\Models\ClassificationType;
use App\Models\Household;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentClassification;
use App\Models\Skill;
use App\Models\SocialAssistanceRecord;
use App\Models\User;
use App\Services\RecordRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The approved query functions of the AI-Powered Barangay Assistant
 * (SDD §4.22, Table 4.3). They are the assistant's ONLY path to data:
 *
 *  - read-only: every function only SELECTs through Eloquent with bound
 *    parameters; nothing the model writes is ever executed as SQL;
 *  - parameterized: arguments are validated against fixed types and allowed
 *    values before a function runs (validate());
 *  - permission-aware: each function names the permission and the assistant
 *    query category it requires (SRS §3.2.3.8).
 *
 * Each function returns a QueryResult that separates what the USER sees
 * (tables with names, straight from the database) from what the MODEL sees
 * (aggregated facts with no names or contact details) — the privacy layer of
 * SDD §4.22 step 6 and SPMP §5.5.3.
 */
class ApprovedQueryFunctions
{
    public const LIST_LIMIT = 200;

    /** Sector keyword → classification type name (A5). */
    public const SECTORS = [
        '4ps' => '4Ps Member',
        'senior_citizen' => 'Senior Citizen',
        'pwd' => 'Person with Disability',
        'solo_parent' => 'Solo Parent',
        'indigent' => 'Indigent',
        'indigenous_person' => 'Indigenous Person',
        'out_of_school_youth' => 'Out-of-School Youth',
        'pregnant' => 'Pregnant',
    ];

    /** When set, analysis is limited to these residents (consent filter, SRS §3.5.6). */
    private ?Collection $onlyResidents = null;

    public const FOUR_PS_NOTE = '4Ps information is based on barangay records encoded in BRIS, not on the official DSWD 4Ps database.';

    /**
     * Definitions in a model-neutral shape: name, category, permission,
     * description, and parameter specs. AssistantService turns these into
     * the tool list sent to the language model (names and types only — no
     * resident data, SDD §4.22 step 3).
     *
     * @return array<string, array{category: string, permission: string, description: string, params: array<string, array>}>
     */
    public static function definitions(): array
    {
        $purok = ['type' => 'string', 'description' => 'Purok name or number, e.g. "Purok 3" or "3". Omit for the whole barangay.'];
        $education = ['type' => 'string', 'enum' => array_keys(Resident::EDUCATION_LEVELS)];

        return [
            'countResidents' => [
                'category' => 'residents', 'permission' => 'residents.view',
                'description' => 'Count registered residents, optionally in one purok or with a given record status (default active).',
                'params' => ['purok' => $purok, 'status' => ['type' => 'string', 'enum' => ['active', 'deceased', 'transferred_out', 'archived']]],
            ],
            'listResidents' => [
                'category' => 'residents', 'permission' => 'residents.view',
                'description' => 'List registered (active) residents, optionally in one purok; also used to show residents grouped by purok.',
                'params' => ['purok' => $purok],
            ],
            'countHouseholds' => [
                'category' => 'residents', 'permission' => 'households.view',
                'description' => 'Count registered households, optionally in one purok.',
                'params' => ['purok' => $purok],
            ],
            'findResidentsByProfile' => [
                'category' => 'residents', 'permission' => 'residents.view',
                'description' => 'Find active residents matching profile information such as sex, civil status, age range, occupation, voter status, or purok.',
                'params' => [
                    'purok' => $purok,
                    'sex' => ['type' => 'string', 'enum' => ['male', 'female']],
                    'civil_status' => ['type' => 'string', 'enum' => ['single', 'married', 'widowed', 'separated', 'divorced', 'annulled']],
                    'min_age' => ['type' => 'integer'],
                    'max_age' => ['type' => 'integer'],
                    'occupation' => ['type' => 'string', 'description' => 'Part of the occupation, e.g. "farmer".'],
                    'voter_status' => ['type' => 'string', 'enum' => ['registered', 'not_registered']],
                ],
            ],
            'listSectorMembers' => [
                'category' => 'social_assistance', 'permission' => 'classifications.view',
                'description' => 'List and count residents in a sector recorded by the barangay: 4Ps beneficiaries, senior citizens, PWDs, solo parents, indigent families, and others.',
                'params' => ['sector' => ['type' => 'string', 'enum' => array_keys(self::SECTORS), 'required' => true], 'purok' => $purok],
            ],
            'listAssistanceRecipients' => [
                'category' => 'social_assistance', 'permission' => 'assistance.view',
                'description' => 'List residents who received medical, educational, livelihood, or other assistance, optionally in a given year.',
                'params' => [
                    'assistance_type' => ['type' => 'string', 'enum' => SocialAssistanceRecord::TYPES],
                    'year' => ['type' => 'integer'],
                ],
            ],
            'summarizeAssistance' => [
                'category' => 'social_assistance', 'permission' => 'assistance.view',
                'description' => 'Summarize the distribution of social assistance beneficiaries by type of assistance and by sector.',
                'params' => ['year' => ['type' => 'integer']],
            ],
            'employmentSummary' => [
                'category' => 'employment_skills', 'permission' => 'residents.view',
                'description' => 'Employed and unemployed counts and the unemployment rate per purok; answers which purok has the highest unemployment rate.',
                'params' => ['purok' => $purok],
            ],
            'listUnemployed' => [
                'category' => 'employment_skills', 'permission' => 'residents.view',
                'description' => 'List unemployed residents, optionally in one purok.',
                'params' => ['purok' => $purok],
            ],
            'findResidentsBySkill' => [
                'category' => 'employment_skills', 'permission' => 'a6.view',
                'description' => 'Find residents with a verified skill such as carpentry, welding, electrical work, or plumbing.',
                'params' => ['skill' => ['type' => 'string', 'required' => true, 'description' => 'Skill name, e.g. "carpentry".']],
            ],
            'topOccupationsAndSkills' => [
                'category' => 'employment_skills', 'permission' => 'a6.view',
                'description' => 'The most common occupations and skills in the barangay.',
                'params' => ['limit' => ['type' => 'integer', 'description' => 'How many to show, 1–20. Default 5.']],
            ],
            'educationSummary' => [
                'category' => 'education', 'permission' => 'residents.view',
                'description' => 'Distribution of residents by highest educational attainment; answers how many completed elementary, high school, or college.',
                'params' => ['purok' => $purok],
            ],
            'listByEducation' => [
                'category' => 'education', 'permission' => 'residents.view',
                'description' => 'List residents at a given highest educational attainment.',
                'params' => ['level' => $education + ['required' => true], 'purok' => $purok],
            ],
            'communityProfile' => [
                'category' => 'community_analysis', 'permission' => 'reports.generate.all',
                'description' => 'Aggregated community statistics (population, employment, skills, education, sectors, assistance) used for community analysis, needs, priorities, and recommendations for livelihood, skills training, and educational support. No names.',
                'params' => ['purok' => $purok],
            ],
        ];
    }

    /**
     * Validates model-supplied arguments against the function's parameter
     * specs. Unknown arguments are dropped; wrong types or values not in the
     * allowed list raise InvalidQueryArguments (the request is refused).
     */
    public function validate(string $function, array $args): array
    {
        $specs = self::definitions()[$function]['params'];
        $clean = [];

        foreach ($specs as $name => $spec) {
            $value = $args[$name] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === null || $value === '' || (is_string($value) && in_array(strtolower($value), ['null', 'none', 'all', 'any'], true))) {
                if (! empty($spec['required'])) {
                    throw new InvalidQueryArguments("Missing required value: {$name}.");
                }
                continue;
            }

            if ($spec['type'] === 'integer') {
                if (! is_numeric($value)) {
                    throw new InvalidQueryArguments("{$name} must be a number.");
                }
                $value = (int) $value;
            } elseif (! is_string($value) || mb_strlen($value) > 100) {
                throw new InvalidQueryArguments("{$name} has an invalid value.");
            }

            if (isset($spec['enum'])) {
                $value = strtolower(str_replace([' ', '-'], '_', (string) $value));
                if (! in_array($value, $spec['enum'], true)) {
                    throw new InvalidQueryArguments("{$name} must be one of: ".implode(', ', $spec['enum']).'.');
                }
            }

            $clean[$name] = $value;
        }

        if (array_key_exists('purok', $clean)) {
            $clean['purok'] = $this->resolvePurok($clean['purok']);
        }

        return $clean;
    }

    public function run(string $function, array $params, User $user): QueryResult
    {
        return $this->{$function}($params, $user);
    }

    // ---------------------------------------------------------------- residents

    private function countResidents(array $p): QueryResult
    {
        $status = $p['status'] ?? 'active';
        $count = $this->residentScope($p['purok'] ?? null, $status)->count();

        return QueryResult::make('countResidents', 'residents', $p)
            ->figure($this->scopeLabel('Registered residents', $p).($status !== 'active' ? " ({$status})" : ''), $count)
            ->fact('resident_count', $count)
            ->dataTypes('counts only');
    }

    private function listResidents(array $p): QueryResult
    {
        $query = $this->residentScope($p['purok'] ?? null)->with('purok');
        $total = (clone $query)->count();
        $rows = $query->orderBy('purok_id')->orderBy('last_name')->orderBy('first_name')->limit(self::LIST_LIMIT)->get();

        $byPurok = $this->countByPurok($this->residentScope($p['purok'] ?? null));

        return QueryResult::make('listResidents', 'residents', $p)
            ->figure($this->scopeLabel('Registered residents', $p), $total)
            ->when(! isset($p['purok']) && ($top = $this->topPurok($byPurok)) !== null, fn (QueryResult $r) => $r->figure('Purok with the most residents', $top))
            ->table('Residents', ['Name', 'Purok', 'Sex', 'Age'], $rows->map(fn (Resident $r) => [
                $r->full_name, $r->purok?->name ?? '—', ucfirst($r->sex), $r->age,
            ])->all(), $total)
            ->table('Residents per purok', ['Purok', 'Residents'], $byPurok->map(fn ($c, $name) => [$name, $c])->values()->all())
            ->fact('resident_count', $total)
            ->fact('residents_per_purok', $byPurok->all())
            ->dataTypes('name, purok, sex, age');
    }

    private function countHouseholds(array $p): QueryResult
    {
        $query = Household::query();
        if ($purok = $p['purok'] ?? null) {
            $query->where('purok_id', $purok->id);
        }
        if (\Schema::hasColumn('households', 'status')) {
            $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'));
        }
        $count = $query->count();

        return QueryResult::make('countHouseholds', 'residents', $p)
            ->figure($this->scopeLabel('Registered households', $p), $count)
            ->fact('household_count', $count)
            ->dataTypes('counts only');
    }

    private function findResidentsByProfile(array $p): QueryResult
    {
        $query = $this->residentScope($p['purok'] ?? null)->with('purok');

        foreach (['sex', 'civil_status', 'voter_status'] as $field) {
            if (isset($p[$field])) {
                $query->where($field, $p[$field]);
            }
        }
        if (isset($p['occupation'])) {
            $query->where('occupation', 'like', '%'.addcslashes($p['occupation'], '%_\\').'%');
        }
        if (isset($p['min_age'])) {
            $query->where('birth_date', '<=', now()->subYears($p['min_age'])->toDateString());
        }
        if (isset($p['max_age'])) {
            $query->where('birth_date', '>', now()->subYears($p['max_age'] + 1)->toDateString());
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('last_name')->limit(self::LIST_LIMIT)->get();

        return QueryResult::make('findResidentsByProfile', 'residents', $p)
            ->figure($this->scopeLabel($this->profileLabel($p), $p), $total)
            ->table('Matching residents', ['Name', 'Purok', 'Sex', 'Age', 'Civil status', 'Occupation'], $rows->map(fn (Resident $r) => [
                $r->full_name, $r->purok?->name ?? '—', ucfirst($r->sex), $r->age, ucfirst($r->civil_status), $r->occupation ?: '—',
            ])->all(), $total)
            ->fact('matching_count', $total)
            ->fact('filters', $this->describeFilters($p))
            ->dataTypes('name, purok, sex, age, civil status, occupation');
    }

    // -------------------------------------------------------- social assistance

    private function listSectorMembers(array $p): QueryResult
    {
        $typeName = self::SECTORS[$p['sector']];
        $type = ClassificationType::where('name', $typeName)->first();

        $result = QueryResult::make('listSectorMembers', 'social_assistance', $p);
        if ($p['sector'] === '4ps') {
            $result->note(self::FOUR_PS_NOTE);
        }

        if (! $type) {
            return $result->missing("The classification \"{$typeName}\" is not set up in BRIS.");
        }

        $query = $this->residentScope($p['purok'] ?? null)
            ->with(['purok', 'currentHouseholdMembership'])
            ->whereHas('classifications', fn ($q) => $q->where('classification_type_id', $type->id)->where('status', 'active'));

        $total = (clone $query)->count();
        $rows = $query->orderBy('purok_id')->orderBy('last_name')->limit(self::LIST_LIMIT)->get();
        $households = (clone $query)->get()->pluck('currentHouseholdMembership.household_id')->filter()->unique()->count();
        $byPurok = $this->countByPurok((clone $query));

        $label = $p['sector'] === 'indigent' ? 'Residents classified as indigent' : "{$typeName} records";

        return $result
            ->figure($this->scopeLabel($label, $p), $total)
            ->when($households > 0, fn (QueryResult $r) => $r->figure('Households these residents belong to', $households))
            ->when(! isset($p['purok']) && ($top = $this->topPurok($byPurok)) !== null, fn (QueryResult $r) => $r->figure("Purok with the most {$typeName} records", $top))
            ->table($typeName, ['Name', 'Purok', 'Age'], $rows->map(fn (Resident $r) => [
                $r->full_name, $r->purok?->name ?? '—', $r->age,
            ])->all(), $total)
            ->fact('sector', $typeName)
            ->fact('resident_count', $total)
            ->fact('household_count', $households)
            ->fact('count_per_purok', $byPurok->all())
            ->dataTypes("name, purok, age, sector classification ({$typeName})");
    }

    private function listAssistanceRecipients(array $p): QueryResult
    {
        $query = SocialAssistanceRecord::query()
            ->with('resident.purok')
            ->whereHas('resident', fn ($q) => $q->whereNull('deleted_at'));
        if (isset($p['assistance_type'])) {
            $query->where('assistance_type', $p['assistance_type']);
        }
        if (isset($p['year'])) {
            $query->whereYear('date_received', $p['year']);
        }

        $total = (clone $query)->count();
        $recipients = (clone $query)->distinct()->count('resident_id');
        $rows = $query->orderByDesc('date_received')->limit(self::LIST_LIMIT)->get();

        $result = QueryResult::make('listAssistanceRecipients', 'social_assistance', $p);
        if ($total === 0) {
            return $result->missing('No social assistance records'.(isset($p['assistance_type']) ? " of type {$p['assistance_type']}" : '').(isset($p['year']) ? " in {$p['year']}" : '').' have been encoded in BRIS.');
        }

        return $result
            ->figure('Assistance records', $total)
            ->figure('Residents who received assistance', $recipients)
            ->table('Assistance received', ['Name', 'Purok', 'Type', 'Program', 'Date received'], $rows->map(fn (SocialAssistanceRecord $r) => [
                $r->resident?->full_name ?? '—', $r->resident?->purok?->name ?? '—', ucfirst($r->assistance_type), $r->program_name, $r->date_received->toDateString(),
            ])->all(), $total)
            ->fact('record_count', $total)
            ->fact('recipient_count', $recipients)
            ->dataTypes('name, purok, assistance received');
    }

    private function summarizeAssistance(array $p): QueryResult
    {
        $query = SocialAssistanceRecord::query()->when($this->onlyResidents !== null, fn ($q) => $q->whereIn('resident_id', $this->onlyResidents));
        if (isset($p['year'])) {
            $query->whereYear('date_received', $p['year']);
        }

        $byType = (clone $query)->select('assistance_type', DB::raw('count(*) as records'), DB::raw('count(distinct resident_id) as residents'))
            ->groupBy('assistance_type')->get();

        $sectorCounts = collect(self::SECTORS)->mapWithKeys(function ($typeName) {
            $type = ClassificationType::where('name', $typeName)->first();

            return [$typeName => $type ? ResidentClassification::where('classification_type_id', $type->id)->where('status', 'active')
                ->whereHas('resident', fn ($q) => $q->where('status', 'active'))
                ->when($this->onlyResidents !== null, fn ($q) => $q->whereIn('resident_id', $this->onlyResidents))->count() : 0];
        });

        $result = QueryResult::make('summarizeAssistance', 'social_assistance', $p)
            ->note(self::FOUR_PS_NOTE)
            ->table('Sector beneficiaries (recorded classifications)', ['Sector', 'Residents'], $sectorCounts->map(fn ($c, $n) => [$n, $c])->values()->all())
            ->fact('sector_counts', $sectorCounts->all())
            ->dataTypes('aggregated counts only');

        if ($byType->isEmpty()) {
            $result->note('No medical, educational, or livelihood assistance has been encoded in BRIS yet'.(isset($p['year']) ? " for {$p['year']}" : '').'.');
        } else {
            $result->table('Assistance by type', ['Type', 'Records', 'Residents'], $byType->map(fn ($r) => [ucfirst($r->assistance_type), $r->records, $r->residents])->all())
                ->fact('assistance_by_type', $byType->mapWithKeys(fn ($r) => [$r->assistance_type => ['records' => $r->records, 'residents' => $r->residents]])->all());
        }

        return $result;
    }

    // -------------------------------------------------------- employment / skills

    private function employmentSummary(array $p): QueryResult
    {
        $puroks = isset($p['purok']) ? collect([$p['purok']]) : Purok::orderBy('purok_number')->orderBy('name')->get();

        $rows = $puroks->map(function (Purok $purok) {
            $counts = $this->residentScope($purok)
                ->select('employment_status', DB::raw('count(*) as c'))->groupBy('employment_status')->pluck('c', 'employment_status');
            $employed = (int) ($counts['employed'] ?? 0) + (int) ($counts['self_employed'] ?? 0);
            $unemployed = (int) ($counts['unemployed'] ?? 0);
            $laborForce = $employed + $unemployed;

            return [
                'purok' => $purok->name,
                'employed' => $employed,
                'unemployed' => $unemployed,
                'not_in_labor_force' => (int) ($counts['not_in_labor_force'] ?? 0),
                'not_recorded' => (int) ($counts[''] ?? 0) /* a NULL key is stored as '' */,
                'unemployment_rate_percent' => $laborForce > 0 ? round($unemployed / $laborForce * 100, 1) : null,
            ];
        });

        $totals = [
            'employed' => $rows->sum('employed'),
            'unemployed' => $rows->sum('unemployed'),
            'not_in_labor_force' => $rows->sum('not_in_labor_force'),
            'not_recorded' => $rows->sum('not_recorded'),
        ];
        $laborForce = $totals['employed'] + $totals['unemployed'];
        $totals['unemployment_rate_percent'] = $laborForce > 0 ? round($totals['unemployed'] / $laborForce * 100, 1) : null;

        $ranked = $rows->filter(fn ($r) => $r['unemployment_rate_percent'] !== null)->sortByDesc('unemployment_rate_percent');
        $highest = $ranked->first();

        $result = QueryResult::make('employmentSummary', 'employment_skills', $p)
            ->figure('Employed (incl. self-employed)', $totals['employed'])
            ->figure('Unemployed', $totals['unemployed'])
            ->figure('Unemployment rate', $totals['unemployment_rate_percent'] !== null ? $totals['unemployment_rate_percent'].'%' : 'not computable')
            ->table('Employment by purok', ['Purok', 'Employed', 'Unemployed', 'Not in labor force', 'Not recorded', 'Unemployment rate'], $rows->map(fn ($r) => [
                $r['purok'], $r['employed'], $r['unemployed'], $r['not_in_labor_force'], $r['not_recorded'],
                $r['unemployment_rate_percent'] !== null ? $r['unemployment_rate_percent'].'%' : '—',
            ])->all())
            ->fact('totals', $totals)
            ->fact('per_purok', $rows->all())
            ->fact('purok_with_highest_unemployment_rate', $highest ? ['purok' => $highest['purok'], 'rate_percent' => $highest['unemployment_rate_percent']] : null)
            ->dataTypes('aggregated counts only');

        if ($highest && ! isset($p['purok'])) {
            $result->figure('Highest unemployment rate', "{$highest['purok']} ({$highest['unemployment_rate_percent']}%)");
        }
        if ($totals['not_recorded'] > 0) {
            $result->note("{$totals['not_recorded']} active resident(s) have no employment status recorded; they are not counted in the rate.");
        }
        if ($laborForce === 0) {
            $result->missing('No employment status has been recorded for any active resident yet. Encode employment status on the resident records first.');
        }

        return $result;
    }

    private function listUnemployed(array $p): QueryResult
    {
        $query = $this->residentScope($p['purok'] ?? null)->with('purok')->where('employment_status', 'unemployed');
        $total = (clone $query)->count();
        $rows = $query->orderBy('purok_id')->orderBy('last_name')->limit(self::LIST_LIMIT)->get();

        $result = QueryResult::make('listUnemployed', 'employment_skills', $p);
        if ($total === 0) {
            $recorded = $this->residentScope($p['purok'] ?? null)->whereNotNull('employment_status')->count();

            return $recorded === 0
                ? $result->missing('No employment status has been recorded yet, so unemployed residents cannot be listed.')
                : $result->figure($this->scopeLabel('Unemployed residents', $p), 0)->fact('unemployed_count', 0);
        }

        return $result
            ->figure($this->scopeLabel('Unemployed residents', $p), $total)
            ->table('Unemployed residents', ['Name', 'Purok', 'Age', 'Last occupation'], $rows->map(fn (Resident $r) => [
                $r->full_name, $r->purok?->name ?? '—', $r->age, $r->occupation ?: '—',
            ])->all(), $total)
            ->fact('unemployed_count', $total)
            ->fact('count_per_purok', $this->countByPurok($this->residentScope($p['purok'] ?? null)->where('employment_status', 'unemployed'))->all())
            ->dataTypes('name, purok, age, employment status');
    }

    private function findResidentsBySkill(array $p): QueryResult
    {
        $needle = '%'.addcslashes($p['skill'], '%_\\').'%';
        $query = Skill::query()
            ->with(['resident.purok', 'skillType'])
            ->where('status', 'active')
            ->where('is_verified', true)
            ->whereHas('skillType', fn ($q) => $q->where('name', 'like', $needle))
            ->whereHas('resident', fn ($q) => $q->where('status', 'active'));

        $total = (clone $query)->count();
        $rows = $query->limit(self::LIST_LIMIT)->get();

        $result = QueryResult::make('findResidentsBySkill', 'employment_skills', $p)->note('Only verified skills are included (SDD §4.16).');
        if ($total === 0) {
            $unverified = Skill::where('status', 'active')->where('is_verified', false)
                ->whereHas('skillType', fn ($q) => $q->where('name', 'like', $needle))->count();

            return $result->missing("No resident has a verified \"{$p['skill']}\" skill recorded in BRIS.".($unverified ? " {$unverified} unverified record(s) are waiting for verification." : ''));
        }

        return $result
            ->figure("Residents with verified {$p['skill']} skill", $total)
            ->table('Skilled residents', ['Name', 'Purok', 'Skill', 'Proficiency'], $rows->map(fn (Skill $s) => [
                $s->resident->full_name, $s->resident->purok?->name ?? '—', $s->skillType->name, ucfirst((string) $s->proficiency_level),
            ])->all(), $total)
            ->fact('skilled_resident_count', $total)
            ->dataTypes('name, purok, verified skill');
    }

    private function topOccupationsAndSkills(array $p): QueryResult
    {
        $limit = max(1, min(20, $p['limit'] ?? 5));

        $occupations = $this->residentScope(null)->whereNotNull('occupation')->where('occupation', '!=', '')
            ->select(DB::raw('LOWER(TRIM(occupation)) as occ'), DB::raw('count(*) as c'))
            ->groupBy('occ')->orderByDesc('c')->limit($limit)->pluck('c', 'occ');

        $skills = Skill::where('skills.status', 'active')->where('is_verified', true)
            ->whereHas('resident', fn ($q) => $q->where('status', 'active'))
            ->when($this->onlyResidents !== null, fn ($q) => $q->whereIn('skills.resident_id', $this->onlyResidents))
            ->join('skill_types', 'skill_types.id', '=', 'skills.skill_type_id')
            ->select('skill_types.name', DB::raw('count(distinct skills.resident_id) as c'))
            ->groupBy('skill_types.name')->orderByDesc('c')->limit($limit)->pluck('c', 'name');

        $result = QueryResult::make('topOccupationsAndSkills', 'employment_skills', ['limit' => $limit])
            ->fact('top_occupations', $occupations->all())
            ->fact('top_verified_skills', $skills->all())
            ->dataTypes('aggregated counts only');

        if ($occupations->isEmpty() && $skills->isEmpty()) {
            return $result->missing('No occupations or verified skills have been recorded yet.');
        }
        if ($occupations->isNotEmpty()) {
            $result->table('Most common occupations', ['Occupation', 'Residents'], $occupations->map(fn ($c, $o) => [ucwords($o), $c])->values()->all());
        } else {
            $result->note('No occupations have been recorded yet.');
        }
        if ($skills->isNotEmpty()) {
            $result->table('Most common verified skills', ['Skill', 'Residents'], $skills->map(fn ($c, $s) => [$s, $c])->values()->all());
        } else {
            $result->note('No verified skills have been recorded yet.');
        }

        return $result;
    }

    // ---------------------------------------------------------------- education

    private function educationSummary(array $p): QueryResult
    {
        $counts = $this->residentScope($p['purok'] ?? null)
            ->select('educational_attainment', DB::raw('count(*) as c'))->groupBy('educational_attainment')->pluck('c', 'educational_attainment');

        $distribution = collect(Resident::EDUCATION_LEVELS)->map(fn ($label, $key) => (int) ($counts[$key] ?? 0));
        $notRecorded = (int) ($counts[''] ?? 0) /* a NULL key is stored as '' */;

        $completed = [
            'completed_elementary_or_higher' => $distribution->except(['no_formal_education', 'elementary_level'])->sum(),
            'completed_high_school_or_higher' => $distribution->only(['high_school_graduate', 'vocational', 'college_level', 'college_graduate', 'post_graduate'])->sum(),
            'college_graduates_or_higher' => $distribution->only(['college_graduate', 'post_graduate'])->sum(),
        ];

        $result = QueryResult::make('educationSummary', 'education', $p)
            ->figure('Completed elementary (or higher)', $completed['completed_elementary_or_higher'])
            ->figure('Completed high school (or higher)', $completed['completed_high_school_or_higher'])
            ->figure('College graduates (or higher)', $completed['college_graduates_or_higher'])
            ->table($this->scopeLabel('Highest educational attainment', $p), ['Level', 'Residents'],
                $distribution->map(fn ($c, $key) => [Resident::EDUCATION_LEVELS[$key], $c])->values()->push(['Not recorded', $notRecorded])->all())
            ->fact('distribution', $distribution->mapWithKeys(fn ($c, $k) => [Resident::EDUCATION_LEVELS[$k] => $c])->all())
            ->fact('completed', $completed)
            ->fact('not_recorded', $notRecorded)
            ->dataTypes('aggregated counts only');

        if ($distribution->sum() === 0) {
            return $result->missing('No educational attainment has been recorded yet. Encode it on the resident records first.');
        }
        if ($notRecorded > 0) {
            $result->note("{$notRecorded} active resident(s) have no educational attainment recorded.");
        }

        return $result;
    }

    private function listByEducation(array $p): QueryResult
    {
        $query = $this->residentScope($p['purok'] ?? null)->with('purok')->where('educational_attainment', $p['level']);
        $total = (clone $query)->count();
        $rows = $query->orderBy('last_name')->limit(self::LIST_LIMIT)->get();
        $label = Resident::EDUCATION_LEVELS[$p['level']];

        $result = QueryResult::make('listByEducation', 'education', $p);
        if ($total === 0) {
            return $result->figure($this->scopeLabel("Residents — {$label}", $p), 0)->fact('resident_count', 0)
                ->note('No resident with this educational attainment is recorded.');
        }

        return $result
            ->figure($this->scopeLabel("Residents — {$label}", $p), $total)
            ->table("Residents — {$label}", ['Name', 'Purok', 'Age'], $rows->map(fn (Resident $r) => [$r->full_name, $r->purok?->name ?? '—', $r->age])->all(), $total)
            ->fact('resident_count', $total)
            ->dataTypes('name, purok, age, educational attainment');
    }

    // ------------------------------------------------------- community analysis

    /**
     * Aggregated statistics for community analysis — never names (SDD Table 4.3).
     */
    private function communityProfile(array $p, User $user): QueryResult
    {
        // Only residents who consented to inclusion in AI community analysis (G1, SRS §3.5.6).
        $this->onlyResidents = RecordRequestService::consentingResidentIds('ai_community_analysis');
        $excluded = Resident::where('status', 'active')->whereNotIn('id', $this->onlyResidents)->count();
        try {
            $result = $this->buildCommunityProfile($p);
        } finally {
            $this->onlyResidents = null;
        }
        if ($excluded > 0) {
            $result->note("{$excluded} active resident(s) without recorded consent for AI community analysis are excluded (Data Privacy Act; record consent on the resident's page).");
        }

        // Nobody in scope has consented: say so plainly instead of reporting
        // "Active residents: 0" next to a dashboard that shows residents.
        if ($result->missing !== null) {
            $purok = $p['purok'] ?? null;
            $active = $this->residentScope($purok)->count();
            $where = $purok ? " in {$purok->name}" : '';
            $result->figures = [];
            $result->evidence = [];
            $result->notes = [];
            $result->missing($active > 0
                ? "None of the {$active} active resident".($active === 1 ? '' : 's')."{$where} ".($active === 1 ? 'has' : 'have')." consent recorded for AI community analysis yet, so there is nothing to analyse. Record consent on each resident's page (Consent section) and ask again."
                : "No active residents are recorded{$where}.");
        }

        return $result;
    }

    private function buildCommunityProfile(array $p): QueryResult
    {
        $purok = $p['purok'] ?? null;
        $population = $this->residentScope($purok)->count();

        $employment = $this->employmentSummary(['purok' => $purok] + ($purok ? [] : []))->facts;
        $education = $this->educationSummary(['purok' => $purok])->facts;
        $sectors = $this->summarizeAssistance([])->facts;
        $skills = $this->topOccupationsAndSkills(['limit' => 5])->facts;

        $youth = $this->residentScope($purok)->where('birth_date', '>', now()->subYears(25)->toDateString())
            ->where('birth_date', '<=', now()->subYears(15)->toDateString())->count();
        $seniors = $this->residentScope($purok)->where('birth_date', '<=', now()->subYears(60)->toDateString())->count();

        $facts = [
            'scope' => $purok?->name ?? 'whole barangay',
            'active_residents' => $population,
            'youth_15_to_24' => $youth,
            'age_60_and_over' => $seniors,
            'employment' => $employment['totals'] ?? null,
            'purok_with_highest_unemployment_rate' => $employment['purok_with_highest_unemployment_rate'] ?? null,
            'education_completed' => $education['completed'] ?? null,
            'education_not_recorded' => $education['not_recorded'] ?? null,
            'sector_counts' => $sectors['sector_counts'] ?? null,
            'assistance_by_type' => $sectors['assistance_by_type'] ?? 'none recorded',
            'top_occupations' => $skills['top_occupations'] ?? [],
            'top_verified_skills' => $skills['top_verified_skills'] ?? [],
        ];

        $result = QueryResult::make('communityProfile', 'community_analysis', $p)
            ->figure($this->scopeLabel('Active residents', $p), $population)
            ->figure('Youth aged 15–24', $youth)
            ->figure('Aged 60 and over', $seniors)
            ->figure('Unemployed', $facts['employment']['unemployed'] ?? 0)
            ->figure('College graduates (or higher)', $facts['education_completed']['college_graduates_or_higher'] ?? 0)
            ->dataTypes('aggregated counts only');

        foreach ($facts as $key => $value) {
            $result->fact($key, $value);
        }

        // Evidence items a recommendation may cite; the values shown to the user
        // come from here, not from the model (SRS §3.2.3.6).
        $result->evidence('Active residents', $population)
            ->evidence('Youth aged 15–24', $youth)
            ->evidence('Residents aged 60 and over', $seniors);
        // "Not recorded" counts are gaps in the data, not community needs: they
        // are shown as notes, never offered as evidence for a program.
        foreach (['employed' => 'Employed residents', 'unemployed' => 'Unemployed residents'] as $key => $label) {
            if (isset($facts['employment'][$key])) {
                $result->evidence($label, $facts['employment'][$key]);
            }
        }
        if (($facts['employment']['unemployment_rate_percent'] ?? null) !== null) {
            $result->evidence('Unemployment rate', $facts['employment']['unemployment_rate_percent'].'%');
        }
        if ($highest = $facts['purok_with_highest_unemployment_rate']) {
            $result->evidence('Purok with the highest unemployment rate', "{$highest['purok']} ({$highest['rate_percent']}%)");
        }
        foreach (['completed_elementary_or_higher' => 'Completed elementary or higher', 'completed_high_school_or_higher' => 'Completed high school or higher', 'college_graduates_or_higher' => 'College graduates or higher'] as $key => $label) {
            if (isset($facts['education_completed'][$key])) {
                $result->evidence($label, $facts['education_completed'][$key]);
            }
        }
        if ($facts['education_not_recorded']) {
            $result->note("{$facts['education_not_recorded']} of {$population} active resident(s) have no educational attainment recorded. Encode it for a more complete analysis.");
        }
        if ($facts['employment']['not_recorded'] ?? 0) {
            $result->note("{$facts['employment']['not_recorded']} of {$population} active resident(s) have no employment status recorded.");
        }
        // Sector, assistance, and skill figures are barangay-wide even when the
        // analysis is for one purok; their labels say so.
        $wide = $purok ? ' (whole barangay)' : '';
        foreach ($facts['sector_counts'] ?? [] as $sector => $count) {
            if ($count > 0) {
                $result->evidence("Recorded {$sector}{$wide}", $count);
            }
        }
        foreach (is_array($facts['assistance_by_type']) ? $facts['assistance_by_type'] : [] as $type => $row) {
            $result->evidence('Residents who received '.$type.' assistance'.$wide, $row['residents']);
        }
        foreach ($facts['top_occupations'] as $occupation => $count) {
            $result->evidence('Residents working as '.ucwords($occupation).$wide, $count);
        }
        foreach ($facts['top_verified_skills'] as $skill => $count) {
            $result->evidence("Residents with verified {$skill} skill{$wide}", $count);
        }

        if ($population === 0) {
            return $result->missing('No active residents with consent for AI community analysis are recorded'.($purok ? " in {$purok->name}" : '').'.');
        }

        return $this->recommendationOptions($result, $facts, $population, $youth, $seniors, $purok);
    }

    /**
     * Rules that turn the figures into recommendations, each citing the
     * figures that justify it. A 3B model reliably misread several figures at
     * once ("4 residents, all aged 60+"), so it no longer writes these: it only
     * picks the most relevant ones for the question (AssistantService).
     */
    private function recommendationOptions(QueryResult $result, array $facts, int $population, int $youth, int $seniors, ?Purok $purok): QueryResult
    {
        $where = $purok ? " in {$purok->name}" : '';
        $wide = $purok ? ' (whole barangay)' : '';
        $employment = $facts['employment'] ?? [];
        $education = $facts['education_completed'] ?? [];
        $sectors = $facts['sector_counts'] ?? [];

        if (($employment['unemployed'] ?? 0) > 0) {
            $basedOn = ["Unemployed residents: {$employment['unemployed']}"];
            if (($employment['unemployment_rate_percent'] ?? null) !== null) {
                $basedOn[] = "Unemployment rate: {$employment['unemployment_rate_percent']}%";
            }
            if (! $purok && ($highest = $facts['purok_with_highest_unemployment_rate'])) {
                $basedOn[] = "Purok with the highest unemployment rate: {$highest['purok']} ({$highest['rate_percent']}%)";
            }
            $result->option("Run a livelihood or job-placement program for the unemployed residents{$where}, using job matching in the Opportunities module.", $basedOn);
        }

        if (($sectors['Out-of-School Youth'] ?? 0) > 0) {
            $result->option('Enrol out-of-school youth in the Alternative Learning System (ALS) or a TESDA vocational course.', ["Recorded Out-of-School Youth{$wide}: {$sectors['Out-of-School Youth']}"]);
        } elseif ($youth > 0) {
            $result->option("Offer skills training or TESDA courses for the youth aged 15–24{$where}.", ["Youth aged 15–24: {$youth}"]);
        }

        $recorded = $population - (int) ($facts['education_not_recorded'] ?? 0);
        $noHighSchool = $recorded - (int) ($education['completed_high_school_or_higher'] ?? 0);
        if ($recorded > 0 && $noHighSchool > 0) {
            $result->option("Offer educational support, such as ALS or scholarships, to residents{$where} who have not completed high school.", ["Residents with education recorded who have not completed high school: {$noHighSchool}"]);
        }

        if ($seniors > 0) {
            $result->option("Hold a senior citizens' wellness program{$where}: health check-ups and follow-up on social pension.", ["Residents aged 60 and over: {$seniors}"]);
        }

        foreach ([
            'Person with Disability' => 'Help persons with disability get their PWD ID and join accessible livelihood activities.',
            'Solo Parent' => 'Support solo parents with livelihood assistance and solo parent ID processing.',
            '4Ps Member' => "Coordinate with DSWD's Sustainable Livelihood Program for 4Ps member households.",
            'Indigent' => 'Prioritize indigent residents in relief distribution and medical assistance.',
            'Pregnant' => 'Run a maternal health program with the barangay health station for pregnant residents.',
        ] as $sector => $text) {
            if (($sectors[$sector] ?? 0) > 0) {
                $result->option($text, ["Recorded {$sector}{$wide}: {$sectors[$sector]}"]);
            }
        }

        foreach (array_slice($facts['top_verified_skills'] ?? [], 0, 1, true) as $skill => $count) {
            if ($count > 0) {
                $result->option("Involve residents with verified {$skill} skill as trainers or paid workers in barangay projects.", ["Residents with verified {$skill} skill{$wide}: {$count}"]);
            }
        }

        $gaps = [];
        if (($facts['education_not_recorded'] ?? 0) > 0) {
            $gaps[] = "Residents with no educational attainment recorded: {$facts['education_not_recorded']} of {$population}";
        }
        if (($employment['not_recorded'] ?? 0) > 0) {
            $gaps[] = "Residents with no employment status recorded: {$employment['not_recorded']} of {$population}";
        }
        if ($gaps !== []) {
            $result->option('Complete the missing education and employment details on resident records, so this analysis is more reliable.', $gaps);
        }

        return $result;
    }

    // ------------------------------------------------------------------ helpers

    private function residentScope(?Purok $purok, string $status = 'active'): Builder
    {
        $query = Resident::query()->where('status', $status);
        if ($purok) {
            $query->where('purok_id', $purok->id);
        }
        if ($this->onlyResidents !== null) {
            $query->whereIn('id', $this->onlyResidents);
        }

        return $query;
    }

    /** @return Collection<string,int> purok name => count */
    private function countByPurok(Builder $query): Collection
    {
        $counts = (clone $query)->reorder()->select('purok_id', DB::raw('count(*) as c'))->groupBy('purok_id')->pluck('c', 'purok_id');
        $names = Purok::whereIn('id', $counts->keys()->filter())->pluck('name', 'id');

        return $counts->mapWithKeys(fn ($c, $id) => [($names[$id] ?? 'No purok') => (int) $c]);
    }

    private function resolvePurok(string|int $value): Purok
    {
        $value = trim((string) $value);
        $number = preg_replace('/\D+/', '', $value);

        $purok = Purok::where('name', $value)->first()
            ?? ($number !== '' ? Purok::where('purok_number', (int) $number)->first() : null)
            ?? ($number !== '' ? Purok::where('name', 'like', '%'.$number)->first() : null)
            ?? Purok::where('name', 'like', '%'.addcslashes($value, '%_\\').'%')->first();

        if (! $purok) {
            throw new InvalidQueryArguments("There is no purok called \"{$value}\" in BRIS.");
        }

        return $purok;
    }

    /** "Purok 1-A (4)", or "Purok 2 and Purok 3 (5 each)" on a tie; null when there is nothing to rank. */
    private function topPurok(Collection $byPurok): ?string
    {
        $byPurok = $byPurok->except('No purok')->filter(fn ($c) => $c > 0);
        if ($byPurok->isEmpty()) {
            return null;
        }
        $max = $byPurok->max();
        $names = $byPurok->filter(fn ($c) => $c === $max)->keys();

        return $names->count() === 1 ? "{$names->first()} ({$max})" : $names->implode(' and ')." ({$max} each)";
    }

    /** "Female married residents aged 60+" from the profile filters. */
    private function profileLabel(array $p): string
    {
        $words = array_filter([
            isset($p['civil_status']) ? $p['civil_status'] : null,
            isset($p['sex']) ? $p['sex'] : null,
            'residents',
        ]);
        $label = ucfirst(implode(' ', $words));
        $min = $p['min_age'] ?? null;
        $max = $p['max_age'] ?? null;
        // A 0–99 range is no filter at all (the model sometimes sends it).
        if ($min !== null && $max !== null && ($min > 0 || $max < 99)) {
            $label .= " aged {$min}–{$max}";
        } elseif ($min !== null && $min > 0) {
            $label .= " aged {$min}+";
        } elseif ($max !== null && $max < 99) {
            $label .= " aged {$max} or younger";
        }
        if (isset($p['occupation'])) {
            $label .= " working as {$p['occupation']}";
        }
        if (isset($p['voter_status'])) {
            $label .= $p['voter_status'] === 'registered' ? ' (registered voters)' : ' (not registered voters)';
        }

        return $label;
    }

    private function scopeLabel(string $label, array $p): string
    {
        return isset($p['purok']) ? "{$label} in {$p['purok']->name}" : $label;
    }

    private function describeFilters(array $p): array
    {
        return collect($p)->map(fn ($v) => $v instanceof Purok ? $v->name : $v)->all();
    }
}
