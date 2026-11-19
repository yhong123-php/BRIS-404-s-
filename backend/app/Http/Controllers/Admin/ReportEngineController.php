<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClassificationType;
use App\Models\Household;
use App\Models\PopulationEvent;
use App\Models\Purok;
use App\Models\ReliefDistribution;
use App\Models\Resident;
use App\Models\Skill;
use App\Models\SocialAssistanceRecord;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * C2 — Report generation (SDD §4.12). One report engine serves every report:
 * the user picks a report and parameters, previews the table, and exports it.
 * Every report carries the barangay header, parameters, timestamp, and
 * generating user. Runs and exports are written to the audit log because
 * reports contain personal data.
 */
class ReportEngineController extends Controller
{
    public const REPORTS = [
        'masterlist' => ['Masterlist of residents', ['purok_id', 'status']],
        'households_by_purok' => ['Household list by purok', ['purok_id']],
        'purok_population' => ['Purok population summary', []],
        'age_sex' => ['Age and sex distribution', ['purok_id']],
        'sectoral_masterlist' => ['Sectoral masterlist', ['classification_type_id', 'purok_id']],
        'relief_summary' => ['Relief distribution summary', ['date_from', 'date_to']],
        'vital_statistics' => ['Vital statistics by month', ['date_from', 'date_to']],
        'movement_log' => ['Resident movement log', ['date_from', 'date_to']],
        'skilled_residents' => ['Skilled-residents list (verified skills)', ['purok_id']],
        'education' => ['Educational attainment summary', ['purok_id']],
        'assistance_summary' => ['Social assistance summary', ['date_from', 'date_to']],
        'beneficiary_list' => ['Beneficiary list (sector, ranked by household priority)', ['classification_type_id']],
    ];

    public function catalog()
    {
        return response()->json(['data' => [
            'reports' => collect(self::REPORTS)->map(fn ($r, $key) => ['key' => $key, 'title' => $r[0], 'parameters' => $r[1]])->values(),
            'puroks' => Purok::orderBy('purok_number')->orderBy('name')->get(['id', 'name']),
            'classification_types' => ClassificationType::orderBy('name')->get(['id', 'name']),
        ]]);
    }

    public function run(Request $request, string $report)
    {
        abort_unless(isset(self::REPORTS[$report]), 404);
        $p = $request->validate([
            'purok_id' => ['nullable', 'exists:puroks,id'],
            'status' => ['nullable', Rule::in(['active', 'deceased', 'transferred_out', 'archived'])],
            'classification_type_id' => ['nullable', 'exists:classification_types,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);
        $p['date_from'] ??= now()->startOfYear()->toDateString();
        $p['date_to'] ??= now()->toDateString();

        [$columns, $rows] = $this->{lcfirst(str_replace('_', '', ucwords($report, '_')))}($p);

        $result = [
            'header' => 'Republic of the Philippines · Province of Bohol · Municipality of Pilar · Barangay San Isidro',
            'title' => self::REPORTS[$report][0],
            'parameters' => $this->describe($p, self::REPORTS[$report][1]),
            'generated_at' => now()->toDateTimeString(),
            'generated_by' => $request->user()->full_name,
            'columns' => $columns,
            'rows' => $rows,
        ];

        $csv = ($p['format'] ?? 'json') === 'csv';
        Audit::log($csv ? 'report_exported' : 'report_generated', 'C2', null, null, ['report' => $report, 'parameters' => $result['parameters'], 'rows' => count($rows)]);

        if ($csv) {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, [$result['header']]);
            fputcsv($out, [$result['title']]);
            foreach ($result['parameters'] as $k => $v) {
                fputcsv($out, [$k, $v]);
            }
            fputcsv($out, ['Generated', $result['generated_at'].' by '.$result['generated_by']]);
            fputcsv($out, []);
            fputcsv($out, $columns);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            rewind($out);

            return response("\xEF\xBB\xBF".stream_get_contents($out), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="bris-'.$report.'-'.now()->format('Ymd-His').'.csv"',
            ]);
        }

        return response()->json(['data' => $result]);
    }

    // ---------------------------------------------------------------- reports

    private function residents(array $p, string $status = 'active')
    {
        return Resident::with('purok')->where('status', $p['status'] ?? $status)
            ->when($p['purok_id'] ?? null, fn ($q, $id) => $q->where('purok_id', $id));
    }

    private function masterlist(array $p): array
    {
        return [['Resident #', 'Name', 'Sex', 'Birth date', 'Age', 'Civil status', 'Purok', 'Address', 'Occupation', 'Voter status'],
            $this->residents($p)->orderBy('last_name')->orderBy('first_name')->get()->map(fn ($r) => [
                $r->resident_number, $r->full_name, ucfirst($r->sex), $r->birth_date?->toDateString(), $r->age, ucfirst($r->civil_status),
                $r->purok?->name, trim(($r->house_number ?? '').' '.($r->street ?? '')), $r->occupation, $r->voter_status,
            ])->all()];
    }

    private function householdsByPurok(array $p): array
    {
        return [['Purok', 'Household #', 'Head', 'Members', 'Address', 'Dwelling ownership', 'Water source', 'Electricity'],
            Household::with(['purok', 'members' => fn ($q) => $q->whereNull('date_left')->with('resident')])
                ->when($p['purok_id'] ?? null, fn ($q, $id) => $q->where('purok_id', $id))
                ->orderBy('purok_id')->orderBy('household_number')->get()->map(fn ($h) => [
                    $h->purok?->name, $h->household_number, $h->members->firstWhere('is_head', true)?->resident?->full_name ?? '—',
                    $h->members->count(), trim(($h->house_number ?? '').' '.($h->street ?? '')), $h->dwelling_ownership, $h->water_source, $h->electricity_access,
                ])->all()];
    }

    private function purokPopulation(array $p): array
    {
        return [['Purok', 'Residents', 'Male', 'Female', 'Households', 'Average household size', 'Senior citizens (60+)'],
            Purok::orderBy('purok_number')->orderBy('name')->get()->map(function ($purok) {
                $base = Resident::where('status', 'active')->where('purok_id', $purok->id);
                $residents = (clone $base)->count();
                $households = Household::where('purok_id', $purok->id)->count();

                return [$purok->name, $residents, (clone $base)->where('sex', 'male')->count(), (clone $base)->where('sex', 'female')->count(),
                    $households, $households ? round($residents / $households, 1) : '—', (clone $base)->where('birth_date', '<=', now()->subYears(60))->count()];
            })->all()];
    }

    private function ageSex(array $p): array
    {
        $bands = [[0, 4], [5, 14], [15, 24], [25, 39], [40, 59], [60, 200]];

        return [['Age band', 'Male', 'Female', 'Total'], collect($bands)->map(function ($band) use ($p) {
            $q = $this->residents($p)->where('birth_date', '<=', now()->subYears($band[0]))->where('birth_date', '>', now()->subYears($band[1] + 1));
            $m = (clone $q)->where('sex', 'male')->count();
            $f = (clone $q)->where('sex', 'female')->count();

            return [$band[1] >= 200 ? "{$band[0]}+" : "{$band[0]}–{$band[1]}", $m, $f, $m + $f];
        })->all()];
    }

    private function sectoralMasterlist(array $p): array
    {
        return [['Sector', 'Name', 'Purok', 'Age', 'Reference #', 'Date granted', 'Expiry'],
            DB::table('resident_classifications as rc')->join('residents as r', 'r.id', '=', 'rc.resident_id')
                ->join('classification_types as t', 't.id', '=', 'rc.classification_type_id')->leftJoin('puroks as pk', 'pk.id', '=', 'r.purok_id')
                ->where('rc.status', 'active')->where('r.status', 'active')->whereNull('r.deleted_at')
                ->when($p['classification_type_id'] ?? null, fn ($q, $id) => $q->where('rc.classification_type_id', $id))
                ->when($p['purok_id'] ?? null, fn ($q, $id) => $q->where('r.purok_id', $id))
                ->orderBy('t.name')->orderBy('r.last_name')
                ->get(['t.name as sector', 'r.last_name', 'r.first_name', 'r.middle_name', 'pk.name as purok', 'r.birth_date', 'rc.reference_number', 'rc.date_granted', 'rc.expiry_date'])
                ->map(fn ($r) => [$r->sector, trim("{$r->last_name}, {$r->first_name} {$r->middle_name}"), $r->purok, \Carbon\Carbon::parse($r->birth_date)->age, $r->reference_number, $r->date_granted, $r->expiry_date])->all()];
    }

    private function reliefSummary(array $p): array
    {
        return [['Distribution', 'Goods', 'Start', 'End', 'Status', 'Households allocated', 'Claimed', 'Unclaimed'],
            ReliefDistribution::whereBetween('start_date', [$p['date_from'], $p['date_to']])->orderBy('start_date')->get()->map(function ($d) {
                $allocated = $d->beneficiaries()->where('is_excluded', false)->where('within_allocation', true);

                return [$d->title, $d->goods_description, $d->start_date->toDateString(), $d->end_date->toDateString(), $d->status,
                    (clone $allocated)->count(), (clone $allocated)->where('status', 'claimed')->count(), (clone $allocated)->whereIn('status', ['listed', 'unclaimed'])->count()];
            })->all()];
    }

    private function vitalStatistics(array $p): array
    {
        $rows = PopulationEvent::whereBetween('event_date', [$p['date_from'], $p['date_to']])
            ->select(DB::raw("DATE_FORMAT(event_date, '%Y-%m') as month"), 'event_type', DB::raw('count(*) as c'))
            ->groupBy('month', 'event_type')->get()->groupBy('month');

        return [['Month', 'Births', 'Deaths', 'Transfers in', 'Transfers out', 'Net change'], $rows->map(function ($g, $month) {
            $c = $g->pluck('c', 'event_type');

            return [$month, $c['birth'] ?? 0, $c['death'] ?? 0, $c['transfer_in'] ?? 0, $c['transfer_out'] ?? 0,
                ($c['birth'] ?? 0) + ($c['transfer_in'] ?? 0) - ($c['death'] ?? 0) - ($c['transfer_out'] ?? 0)];
        })->sortKeys()->values()->all()];
    }

    private function movementLog(array $p): array
    {
        return [['Date', 'Event', 'Resident', 'Purok', 'From / to', 'Document', 'Recorded by'],
            PopulationEvent::with('resident', 'purok', 'recorder')->whereBetween('event_date', [$p['date_from'], $p['date_to']])->orderBy('event_date')->get()
                ->map(fn ($e) => [$e->event_date->toDateString(), str_replace('_', ' ', $e->event_type), $e->resident->full_name, $e->purok?->name,
                    $e->previous_address ?? $e->destination_address, trim(($e->document_type ?? '').' '.($e->document_number ?? '')), $e->recorder?->full_name])->all()];
    }

    private function skilledResidents(array $p): array
    {
        return [['Skill', 'Name', 'Purok', 'Proficiency', 'Verified on'],
            Skill::with('skillType', 'resident.purok')->where('status', 'active')->where('is_verified', true)
                ->whereHas('resident', fn ($q) => $q->where('status', 'active')->when($p['purok_id'] ?? null, fn ($r, $id) => $r->where('purok_id', $id)))
                ->get()->sortBy(fn ($s) => $s->skillType->name.$s->resident->last_name)
                ->map(fn ($s) => [$s->skillType->name, $s->resident->full_name, $s->resident->purok?->name, $s->proficiency_level, $s->verified_at?->toDateString()])->values()->all()];
    }

    private function education(array $p): array
    {
        $counts = $this->residents($p)->selectRaw('educational_attainment, count(*) as c')->groupBy('educational_attainment')->pluck('c', 'educational_attainment');
        $rows = collect(Resident::EDUCATION_LEVELS)->map(fn ($label, $key) => [$label, (int) ($counts[$key] ?? 0)])->values();
        $rows->push(['Not recorded', (int) ($counts[''] ?? 0)]);

        return [['Highest educational attainment', 'Residents'], $rows->all()];
    }

    private function assistanceSummary(array $p): array
    {
        return [['Type', 'Program', 'Records', 'Residents', 'First', 'Last'],
            SocialAssistanceRecord::whereBetween('date_received', [$p['date_from'], $p['date_to']])
                ->select('assistance_type', 'program_name', DB::raw('count(*) as records'), DB::raw('count(distinct resident_id) as residents'), DB::raw('min(date_received) as first'), DB::raw('max(date_received) as last'))
                ->groupBy('assistance_type', 'program_name')->orderBy('assistance_type')->get()
                ->map(fn ($r) => [ucfirst($r->assistance_type), $r->program_name, $r->records, $r->residents, $r->first, $r->last])->all()];
    }

    /** F1 beneficiary list: residents in the sector, with their household's documented priority factors. */
    private function beneficiaryList(array $p): array
    {
        $typeId = $p['classification_type_id'] ?? ClassificationType::where('name', '4Ps Member')->value('id');
        $rows = Resident::with('purok', 'currentHouseholdMembership')->where('status', 'active')
            ->whereHas('classifications', fn ($q) => $q->where('classification_type_id', $typeId)->where('status', 'active'))->get()
            ->map(function ($r) {
                $age = $r->age ?? 0;
                $sectors = $r->classifications()->where('status', 'active')->count();
                $score = $sectors * 2 + ($age >= 60 ? 2 : 0) + ($r->employment_status === 'unemployed' ? 2 : 0);

                return [$r->full_name, $r->purok?->name, $age, $sectors, $r->employment_status ?? 'not recorded', $score];
            })->sortByDesc(fn ($row) => $row[5])->values()->map(fn ($row, $i) => array_merge([$i + 1], $row))->all();

        return [['Rank', 'Name', 'Purok', 'Age', 'Active sector classifications', 'Employment status', 'Priority score'], $rows];
    }

    private function describe(array $p, array $used): array
    {
        $out = [];
        foreach ($used as $key) {
            $out[match ($key) {
                'purok_id' => 'Purok', 'status' => 'Record status', 'classification_type_id' => 'Sector',
                'date_from' => 'From', 'date_to' => 'To', default => $key,
            }] = match ($key) {
                'purok_id' => isset($p['purok_id']) ? Purok::find($p['purok_id'])?->name : 'All puroks',
                'classification_type_id' => isset($p['classification_type_id']) ? ClassificationType::find($p['classification_type_id'])?->name : ($key === 'classification_type_id' ? 'All / default' : null),
                'status' => $p['status'] ?? 'active',
                default => $p[$key] ?? '—',
            };
        }

        return $out;
    }
}
