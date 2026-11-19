<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiSuggestion;
use App\Models\DemographicForecast;
use App\Models\Opportunity;
use App\Models\OpportunityMatch;
use App\Models\PopulationEvent;
use App\Models\SkillType;
use App\Services\AnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** F1 — AI resident analysis, F2 — Opportunity matching, C3 — Predictive dashboard. */
class AnalysisController extends Controller
{
    public function __construct(private readonly AnalysisService $analysis) {}

    // ------------------------------------------------------------------ F1

    public function suggestions(Request $request)
    {
        $query = AiSuggestion::with('resident:id,first_name,middle_name,last_name,suffix,resident_number,purok_id', 'resident.purok:id,name', 'reviewer:id,full_name')
            ->latest('id');
        $query->where('status', $request->string('status')->toString() ?: 'pending');
        if ($type = $request->string('type')->toString()) {
            $query->where('suggestion_type', $type);
        }

        return response()->json(['data' => $query->limit(300)->get()->map(fn (AiSuggestion $s) => $s->toArray() + [
            'resident_name' => $s->resident?->full_name, 'purok' => $s->resident?->purok?->name,
        ])]);
    }

    public function runAnalysis()
    {
        set_time_limit(300);

        return response()->json(['data' => $this->analysis->runResidentAnalysis()]);
    }

    public function decideSuggestion(Request $request, AiSuggestion $suggestion)
    {
        $data = $request->validate([
            'confirm' => ['required', 'boolean'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'document_path' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->analysis->decideSuggestion($suggestion, $data['confirm'], array_filter([
            'reference_number' => $data['reference_number'] ?? null,
            'document_path' => $data['document_path'] ?? null,
        ]))]);
    }

    // ------------------------------------------------------------------ F2

    public function opportunities()
    {
        $skills = SkillType::pluck('name', 'id');

        return response()->json(['data' => Opportunity::withCount('matches')->latest('id')->get()->map(fn (Opportunity $o) => $o->toArray() + [
            'required_skill_names' => collect($o->required_skills)->map(fn ($id) => $skills[$id] ?? "#{$id}")->values(),
        ])]);
    }

    public function storeOpportunity(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['job', 'livelihood', 'training'])],
            'provider' => ['nullable', 'string', 'max:255'],
            'required_skills' => ['required', 'array', 'min:1'],
            'required_skills.*' => ['integer', 'exists:skill_types,id'],
            'slots' => ['required', 'integer', 'min:1'],
            'closing_date' => ['nullable', 'date'],
            'target_purok_ids' => ['nullable', 'array'],
            'target_purok_ids.*' => ['integer', 'exists:puroks,id'],
            'min_age' => ['nullable', 'integer', 'min:15', 'max:100'],
            'max_age' => ['nullable', 'integer', 'min:15', 'max:100', 'gte:min_age'],
            'allocation_mode' => ['nullable', Rule::in(['priority', 'per_purok'])],
        ]);
        $data['target_purok_ids'] = ($data['target_purok_ids'] ?? []) ?: null;
        $data = array_filter($data, fn ($v) => $v !== null) + ['target_purok_ids' => null];
        // Automatic: the capable residents are shortlisted and segregated by purok on posting.
        $opportunity = Opportunity::create($data + ['status' => 'open', 'posted_by' => $request->user()->id]);
        $this->analysis->matchOpportunity($opportunity);

        return response()->json(['data' => $opportunity], 201);
    }

    public function showOpportunity(Opportunity $opportunity)
    {
        $skills = SkillType::pluck('name', 'id');

        return response()->json(['data' => [
            'opportunity' => $opportunity->toArray() + ['required_skill_names' => collect($opportunity->required_skills)->map(fn ($id) => $skills[$id] ?? "#{$id}")->values()],
            'target_purok_names' => $opportunity->target_purok_ids ? \App\Models\Purok::whereIn('id', $opportunity->target_purok_ids)->orderBy('name')->pluck('name') : [],
            'by_purok' => $this->analysis->opportunityPurokSummary($opportunity),
            'matches' => $opportunity->matches()->with('resident:id,first_name,middle_name,last_name,suffix,contact_number,birth_date,employment_status', 'purok:id,name')
                ->orderBy('rank')->get()
                ->sortBy(fn ($m) => [$m->purok?->name ?? "\u{FFFF}", $m->purok_rank ?? PHP_INT_MAX])->values()
                ->map(fn (OpportunityMatch $m) => [
                    'id' => $m->id, 'rank' => $m->rank, 'purok_rank' => $m->purok_rank, 'within_slots' => $m->within_slots,
                    'score' => $m->score, 'outcome' => $m->outcome,
                    'resident_id' => $m->resident_id, 'resident_name' => $m->resident->full_name, 'age' => $m->resident->age,
                    'employment_status' => $m->resident->employment_status,
                    'purok_id' => $m->purok_id, 'purok' => $m->purok?->name, 'contact_number' => $m->resident->contact_number,
                ]),
        ]]);
    }

    public function rematch(Opportunity $opportunity)
    {
        $this->analysis->matchOpportunity($opportunity);

        return $this->showOpportunity($opportunity);
    }

    public function updateOpportunity(Request $request, Opportunity $opportunity)
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'closed'])],
            'allocation_mode' => ['sometimes', Rule::in(['priority', 'per_purok'])],
            'slots' => ['sometimes', 'integer', 'min:1'],
        ]);
        $opportunity->update($data);
        if ($opportunity->status === 'open' && (isset($data['allocation_mode']) || isset($data['slots']))) {
            $this->analysis->matchOpportunity($opportunity->fresh());
        }

        return $this->showOpportunity($opportunity->fresh());
    }

    public function updateMatch(Request $request, OpportunityMatch $match)
    {
        $match->update($request->validate(['outcome' => ['required', Rule::in(['shortlisted', 'contacted', 'interested', 'declined', 'hired'])]]));

        return $this->showOpportunity($match->opportunity);
    }

    // ------------------------------------------------------------------ C3

    public function forecasts()
    {
        $rows = DemographicForecast::with('purok:id,name')->get();
        $monthly = PopulationEvent::where('event_date', '>=', now()->subMonths(12)->startOfMonth())
            ->select(DB::raw("DATE_FORMAT(event_date, '%Y-%m') as month"), 'event_type', DB::raw('count(*) as c'))
            ->groupBy('month', 'event_type')->orderBy('month')->get();

        return response()->json(['data' => [
            'generated_at' => $rows->max('generated_at'),
            'period' => $rows->first()?->period,
            'input_from' => $rows->first()?->input_from?->toDateString(),
            'input_to' => $rows->first()?->input_to?->toDateString(),
            'method' => $rows->firstWhere('metric', 'population')?->method,
            'puroks' => $rows->groupBy(fn ($r) => $r->purok?->name ?? 'Whole barangay')->map(fn ($g) => $g->pluck('projected_value', 'metric')),
            'monthly_events' => $monthly,
        ]]);
    }

    public function generateForecasts()
    {
        $this->analysis->generateForecasts();

        return $this->forecasts();
    }
}
