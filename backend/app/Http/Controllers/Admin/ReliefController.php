<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReliefBeneficiary;
use App\Models\ReliefClaim;
use App\Models\ReliefDistribution;
use App\Services\ReliefService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** B2 — Relief distribution management and B4 — Smart relief allocation (SDD §4.9, §4.19). */
class ReliefController extends Controller
{
    public function __construct(private readonly ReliefService $relief) {}

    public function index()
    {
        return response()->json(['data' => ReliefDistribution::withCount([
            'beneficiaries as listed_count' => fn ($q) => $q->where('is_excluded', false)->where('within_allocation', true),
            'beneficiaries as claimed_count' => fn ($q) => $q->where('status', 'claimed'),
        ])->latest('id')->get()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $distribution = ReliefDistribution::create($data + ['status' => 'draft', 'created_by' => $request->user()->id, 'priority_weights' => ReliefService::DEFAULT_WEIGHTS]);

        // Automatic: the eligible households are listed, ranked, and segregated by
        // purok as soon as the distribution is created. Still a draft until the
        // Captain approves (SDD §4.19).
        $this->relief->generateBeneficiaries($distribution);

        return response()->json(['data' => $distribution->fresh()], 201);
    }

    public function update(Request $request, ReliefDistribution $distribution)
    {
        abort_unless($distribution->status === 'draft', 422, 'Only a draft distribution can be edited.');
        $distribution->update($this->validated($request, true));
        $this->relief->generateBeneficiaries($distribution->fresh());

        return $this->show($distribution->fresh());
    }

    public function show(ReliefDistribution $distribution)
    {
        $distribution->load('creator:id,full_name', 'approver:id,full_name');
        $beneficiaries = $distribution->beneficiaries()
            ->with(['household:id,household_number', 'purok:id,name', 'resident:id,first_name,middle_name,last_name,suffix', 'claims' => fn ($q) => $q->with('releaser:id,full_name')->latest('id')])
            ->orderByRaw('rank is null')->orderBy('rank')->get()
            ->sortBy(fn ($b) => [$b->purok?->name ?? "\u{FFFF}", $b->rank ?? PHP_INT_MAX])->values();

        return response()->json(['data' => [
            'distribution' => $distribution,
            'capacity' => intdiv($distribution->quantity_available, max(1, $distribution->quantity_per_beneficiary)),
            'by_purok' => $this->relief->purokSummary($distribution),
            'beneficiaries' => $beneficiaries->map(fn (ReliefBeneficiary $b) => [
                'id' => $b->id,
                'household_id' => $b->household_id,
                'household_number' => $b->household?->household_number,
                'purok_id' => $b->purok_id,
                'purok' => $b->purok?->name,
                'purok_rank' => $b->purok_rank,
                'representative' => $b->resident?->full_name,
                'priority_score' => $b->priority_score,
                'score_breakdown' => $b->score_breakdown,
                'rank' => $b->rank,
                'duplicate_flag' => $b->duplicate_flag,
                'inclusion' => $b->inclusion,
                'is_excluded' => $b->is_excluded,
                'within_allocation' => $b->within_allocation,
                'override_reason' => $b->override_reason,
                'entitlement_quantity' => $b->entitlement_quantity,
                'status' => $b->status,
                'claims' => $b->claims->map(fn (ReliefClaim $c) => [
                    'id' => $c->id, 'claimant_name' => $c->claimant_name, 'relationship_to_head' => $c->relationship_to_head,
                    'claimed_at' => $c->claimed_at, 'released_by' => $c->releaser?->full_name,
                    'reversed_at' => $c->reversed_at, 'reversal_reason' => $c->reversal_reason,
                ]),
            ]),
        ]]);
    }

    public function generate(ReliefDistribution $distribution)
    {
        $this->relief->generateBeneficiaries($distribution);

        return $this->show($distribution->fresh());
    }

    public function exclude(Request $request, ReliefBeneficiary $beneficiary)
    {
        $data = $request->validate(['is_excluded' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:255']]);
        $this->relief->setExclusion($beneficiary, $data['is_excluded'], $data['reason']);

        return $this->show($beneficiary->distribution->fresh());
    }

    public function addHousehold(Request $request, ReliefDistribution $distribution)
    {
        $data = $request->validate(['household_id' => ['required', 'exists:households,id'], 'reason' => ['required', 'string', 'max:255']]);
        $this->relief->addHousehold($distribution, $data['household_id'], $data['reason']);

        return $this->show($distribution->fresh());
    }

    public function submit(ReliefDistribution $distribution)
    {
        $this->relief->submit($distribution);

        return $this->show($distribution->fresh());
    }

    public function decide(Request $request, ReliefDistribution $distribution)
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']]);
        $this->relief->decide($distribution, $data['approve'], $data['reason'] ?? null);

        return $this->show($distribution->fresh());
    }

    public function claim(Request $request, ReliefBeneficiary $beneficiary)
    {
        $data = $request->validate([
            'claimant_name' => ['required', 'string', 'max:255'],
            'claimed_by_resident_id' => ['nullable', 'exists:residents,id'],
            'relationship_to_head' => ['nullable', 'string', 'max:100'],
        ]);
        $this->relief->recordClaim($beneficiary, $data);

        return $this->show($beneficiary->distribution->fresh());
    }

    public function reverseClaim(Request $request, ReliefClaim $claim)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->relief->reverseClaim($claim, $data['reason']);

        return $this->show($claim->beneficiary->distribution->fresh());
    }

    public function close(ReliefDistribution $distribution)
    {
        $this->relief->close($distribution);

        return $this->show($distribution->fresh());
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$req, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'source_of_funds' => ['nullable', 'string', 'max:255'],
            'goods_description' => [$req, 'string', 'max:255'],
            'quantity_available' => [$req, 'integer', 'min:1'],
            'quantity_per_beneficiary' => [$req, 'integer', 'min:1'],
            'target_rule' => [$req, 'array'],
            'target_rule.type' => [$req, Rule::in(['all', 'priority', 'puroks', 'classification'])],
            'allocation_mode' => ['sometimes', Rule::in(['priority', 'per_purok'])],
            'target_rule.purok_ids' => ['nullable', 'array'],
            'target_rule.purok_ids.*' => ['integer', 'exists:puroks,id'],
            'target_rule.classification_type_id' => ['nullable', 'integer', 'exists:classification_types,id'],
            'start_date' => [$req, 'date'],
            'end_date' => [$req, 'date', 'after_or_equal:start_date'],
            'venue' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
