<?php

namespace App\Services;

use App\Models\ClassificationType;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\ReliefBeneficiary;
use App\Models\ReliefClaim;
use App\Models\ReliefDistribution;
use App\Models\ResidentClassification;
use App\Models\SocialAssistanceRecord;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * B2 — Relief distribution management (SDD §4.9) and
 * B4 — Smart relief allocation (SDD §4.19).
 *
 * Draft (Secretary) → beneficiary list generated with duplicate flags and
 * priority ranking → submitted (pending_approval) → approved by the Captain,
 * which freezes the list and opens claims → closed by the Captain.
 */
class ReliefService
{
    /** Documented priority factors (SDD §4.19 step 3); shown with every plan. */
    public const DEFAULT_WEIGHTS = [
        'Senior Citizen' => 3,
        'Person with Disability' => 3,
        'Solo Parent' => 2,
        'Infant or Child' => 1,
        'Pregnant' => 2,
        'Indigent' => 3,
        'household_member' => 0.5,
        'assistance_already_received' => -2,
    ];

    /** Sectors that make a household eligible under the "vulnerable members" rule. */
    public const PRIORITY_SECTORS = ['Senior Citizen', 'Person with Disability', 'Solo Parent', 'Infant or Child', 'Pregnant', 'Indigent'];

    /** Look-back period for duplicate detection across programs, in days. */
    public const DUPLICATE_LOOKBACK_DAYS = 90;

    public function generateBeneficiaries(ReliefDistribution $distribution): void
    {
        $this->assertStatus($distribution, ['draft']);
        $weights = $distribution->priority_weights ?: self::DEFAULT_WEIGHTS;
        $rule = $distribution->target_rule;

        $households = Household::query()->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'));
        if (($rule['type'] ?? 'all') === 'puroks') {
            $households->whereIn('purok_id', $rule['purok_ids'] ?? []);
        }
        $households = $households->get();

        if (($rule['type'] ?? 'all') === 'classification') {
            $typeId = (int) ($rule['classification_type_id'] ?? 0);
            $households = $households->filter(fn (Household $h) => ResidentClassification::where('classification_type_id', $typeId)->where('status', 'active')
                ->whereIn('resident_id', $this->activeMemberIds($h))->exists());
        }

        // Eligible = households with at least one member in a priority sector
        // (the weighted sectors of SDD §4.19: senior citizen, PWD, solo parent,
        // infant or child, pregnant, indigent).
        if (($rule['type'] ?? 'all') === 'priority') {
            $priorityTypeIds = ClassificationType::whereIn('name', self::PRIORITY_SECTORS)->pluck('id');
            $households = $households->filter(fn (Household $h) => ResidentClassification::whereIn('classification_type_id', $priorityTypeIds)->where('status', 'active')
                ->whereIn('resident_id', $this->activeMemberIds($h))->exists());
        }

        $typeNames = ClassificationType::pluck('name', 'id');
        $since = $distribution->start_date->copy()->subDays(self::DUPLICATE_LOOKBACK_DAYS);

        DB::transaction(function () use ($distribution, $households, $weights, $typeNames, $since) {
            $distribution->beneficiaries()->where('inclusion', 'rule')->delete();

            foreach ($households as $household) {
                $memberIds = $this->activeMemberIds($household);
                if ($memberIds === []) {
                    continue;
                }

                // Priority score from documented factors.
                $breakdown = [];
                $classifications = ResidentClassification::whereIn('resident_id', $memberIds)->where('status', 'active')->pluck('classification_type_id');
                foreach ($classifications->countBy() as $typeId => $count) {
                    $name = $typeNames[$typeId] ?? null;
                    if ($name && isset($weights[$name])) {
                        $breakdown[$name] = $count * $weights[$name];
                    }
                }
                $breakdown['Household members'] = count($memberIds) * ($weights['household_member'] ?? 0);

                $assistance = SocialAssistanceRecord::whereIn('resident_id', $memberIds)->where('date_received', '>=', $since)->get();
                if ($assistance->isNotEmpty()) {
                    $breakdown['Assistance already received'] = $assistance->count() * ($weights['assistance_already_received'] ?? 0);
                }

                // Duplicate detection: listed in another distribution or received assistance recently — flagged, not excluded.
                $duplicates = ReliefBeneficiary::where('household_id', $household->id)
                    ->where('relief_distribution_id', '!=', $distribution->id)
                    ->whereHas('distribution', fn ($q) => $q->whereIn('status', ['open', 'closed'])->where('start_date', '>=', $since))
                    ->with('distribution:id,title')->get()->toBase()
                    ->map(fn ($b) => 'Relief: '.$b->distribution->title.($b->status === 'claimed' ? ' (claimed)' : ''))
                    ->merge($assistance->map(fn ($a) => ucfirst($a->assistance_type).' assistance: '.$a->program_name.' ('.$a->date_received->toDateString().')'))
                    ->values()->all();

                $head = HouseholdMember::where('household_id', $household->id)->whereNull('date_left')->where('is_head', true)->value('resident_id');

                ReliefBeneficiary::updateOrCreate(
                    ['relief_distribution_id' => $distribution->id, 'household_id' => $household->id],
                    [
                        'resident_id' => $head ?? $memberIds[0],
                        'purok_id' => $household->purok_id,
                        'entitlement_quantity' => $distribution->quantity_per_beneficiary,
                        'priority_score' => round(array_sum($breakdown), 2),
                        'score_breakdown' => $breakdown,
                        'duplicate_flag' => $duplicates ?: null,
                        'inclusion' => 'rule',
                        'status' => 'listed',
                    ]
                );
            }

            $distribution->update(['priority_weights' => $weights]);
            $this->rank($distribution);
        });
    }

    /** Ranks by priority and marks who fits the available quantity (SDD §4.19 step 3). */
    public function rank(ReliefDistribution $distribution): void
    {
        $capacity = intdiv($distribution->quantity_available, max(1, $distribution->quantity_per_beneficiary));
        $all = $distribution->beneficiaries()->orderByDesc('priority_score')->orderBy('household_id')->get();
        $included = $all->where('is_excluded', false)->values();

        // Barangay-wide rank, always computed (the plan shows it).
        $rankById = $included->pluck('id')->flip()->map(fn ($i) => $i + 1);

        // Purok rank and each purok's share of a limited supply.
        $quotas = $distribution->allocation_mode === 'per_purok'
            ? $this->purokQuotas($included->countBy(fn ($b) => $b->purok_id ?? 0)->all(), $capacity)
            : null;

        $purokRank = [];
        DB::transaction(function () use ($all, $rankById, $capacity, $quotas, &$purokRank) {
            foreach ($all as $b) {
                if ($b->is_excluded) {
                    $b->update(['rank' => null, 'purok_rank' => null, 'within_allocation' => false]);

                    continue;
                }
                $key = $b->purok_id ?? 0;
                $purokRank[$key] = ($purokRank[$key] ?? 0) + 1;
                $within = $quotas === null ? $rankById[$b->id] <= $capacity : $purokRank[$key] <= ($quotas[$key] ?? 0);
                $b->update(['rank' => $rankById[$b->id], 'purok_rank' => $purokRank[$key], 'within_allocation' => $within]);
            }
        });
    }

    /**
     * Fair share per purok. When the supply covers at least one household per
     * purok, every purok with eligible households first gets one; the rest is
     * split in proportion to each purok's remaining eligible households
     * (largest-remainder method). A purok never gets more than it has eligible.
     *
     * @param  array<int,int>  $eligible  purok id => eligible households
     * @return array<int,int> purok id => households that receive relief
     */
    public function purokQuotas(array $eligible, int $capacity): array
    {
        $eligible = array_filter($eligible, fn ($n) => $n > 0);
        $total = array_sum($eligible);
        if ($total === 0) {
            return [];
        }
        if ($capacity >= $total) {
            return $eligible;
        }

        // Guaranteed minimum of one per purok when there is enough for every purok.
        $base = $capacity >= count($eligible) ? array_map(fn () => 1, $eligible) : array_map(fn () => 0, $eligible);
        $remaining = array_map(fn ($n, $b) => $n - $b, $eligible, $base);
        $remaining = array_combine(array_keys($eligible), $remaining);

        return $this->largestRemainder($remaining, $capacity - array_sum($base), $base);
    }

    /** Splits $capacity over $weights proportionally, added on top of $quotas, never above each weight. */
    private function largestRemainder(array $weights, int $capacity, array $quotas): array
    {
        $total = array_sum($weights);
        if ($capacity <= 0 || $total === 0) {
            return $quotas;
        }

        $remainders = [];
        foreach ($weights as $purok => $count) {
            $exact = $capacity * $count / $total;
            $quotas[$purok] += (int) floor($exact);
            // Ties go to the purok with more eligible households left.
            $remainders[$purok] = [$exact - floor($exact), $count];
        }
        uasort($remainders, fn ($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        // Fractional parts sum to exactly the households still to assign, so each
        // gets at most one extra and never more than the purok has eligible.
        $left = $capacity - array_sum(array_map(fn ($p) => (int) floor($capacity * $weights[$p] / $total), array_keys($weights)));
        foreach (array_keys($remainders) as $purok) {
            if ($left <= 0) {
                break;
            }
            $quotas[$purok]++;
            $left--;
        }

        return $quotas;
    }

    /** Per-purok summary: eligible, served, goods needed, claimed (for the distribution team of each purok). */
    public function purokSummary(ReliefDistribution $distribution): array
    {
        $names = \App\Models\Purok::pluck('name', 'id');

        return $distribution->beneficiaries()->get()->groupBy(fn ($b) => $b->purok_id ?? 0)
            ->map(function ($group, $purokId) use ($names) {
                $served = $group->where('is_excluded', false)->where('within_allocation', true);

                return [
                    'purok_id' => $purokId ?: null,
                    'purok' => $names[$purokId] ?? 'No purok',
                    'eligible' => $group->where('is_excluded', false)->count(),
                    'served' => $served->count(),
                    'waitlisted' => $group->where('is_excluded', false)->where('within_allocation', false)->count(),
                    'excluded' => $group->where('is_excluded', true)->count(),
                    'quantity_needed' => $served->sum('entitlement_quantity'),
                    'claimed' => $group->where('status', 'claimed')->count(),
                ];
            })
            ->sortBy(fn ($row) => $row['purok_id'] === null ? "\u{FFFF}" : $row['purok'], SORT_NATURAL)
            ->values()->all();
    }

    public function setExclusion(ReliefBeneficiary $beneficiary, bool $excluded, string $reason): ReliefBeneficiary
    {
        $this->assertStatus($beneficiary->distribution, ['draft']);
        $beneficiary->update(['is_excluded' => $excluded, 'override_reason' => $reason]);
        $this->rank($beneficiary->distribution);

        return $beneficiary->fresh();
    }

    public function addHousehold(ReliefDistribution $distribution, int $householdId, string $reason): ReliefBeneficiary
    {
        $this->assertStatus($distribution, ['draft']);
        $household = Household::findOrFail($householdId);
        $memberIds = $this->activeMemberIds($household);
        $head = HouseholdMember::where('household_id', $household->id)->whereNull('date_left')->where('is_head', true)->value('resident_id');

        $beneficiary = ReliefBeneficiary::updateOrCreate(
            ['relief_distribution_id' => $distribution->id, 'household_id' => $household->id],
            ['resident_id' => $head ?? ($memberIds[0] ?? null), 'purok_id' => $household->purok_id, 'entitlement_quantity' => $distribution->quantity_per_beneficiary,
                'inclusion' => 'manual', 'override_reason' => $reason, 'is_excluded' => false, 'status' => 'listed']
        );
        $this->rank($distribution);

        return $beneficiary;
    }

    public function submit(ReliefDistribution $distribution): ReliefDistribution
    {
        $this->assertStatus($distribution, ['draft']);
        if (! $distribution->beneficiaries()->where('is_excluded', false)->exists()) {
            throw ValidationException::withMessages(['beneficiaries' => 'Generate the beneficiary list before submitting.']);
        }
        $distribution->update(['status' => 'pending_approval']);
        Audit::log('relief_plan_submitted', 'B4', 'relief_distributions', $distribution->id);

        return $distribution;
    }

    /** Captain's decision. Approval freezes the list and opens claims (SDD §4.19 step 5). */
    public function decide(ReliefDistribution $distribution, bool $approve, ?string $reason): ReliefDistribution
    {
        $this->assertStatus($distribution, ['pending_approval']);
        $distribution->update($approve
            ? ['status' => 'open', 'approved_by' => Auth::id(), 'approved_at' => now()]
            : ['status' => 'draft']);
        Audit::log($approve ? 'relief_plan_approved' : 'relief_plan_returned', 'B4', 'relief_distributions', $distribution->id, ['reason' => $reason]);

        return $distribution;
    }

    public function recordClaim(ReliefBeneficiary $beneficiary, array $data): ReliefClaim
    {
        $distribution = $beneficiary->distribution;
        $this->assertStatus($distribution, ['open']);
        $today = now()->startOfDay();
        if ($today->lt($distribution->start_date) || $today->gt($distribution->end_date)) {
            throw ValidationException::withMessages(['claim' => 'Claims can only be recorded between the distribution start and end dates.']);
        }
        if ($beneficiary->is_excluded || ! $beneficiary->within_allocation) {
            throw ValidationException::withMessages(['claim' => 'This household is not in the approved allocation.']);
        }

        return DB::transaction(function () use ($beneficiary, $data) {
            $locked = ReliefBeneficiary::whereKey($beneficiary->id)->lockForUpdate()->first();
            $existing = $locked->claims()->whereNull('reversed_at')->with('releaser:id,full_name')->first();
            if ($existing) {
                throw ValidationException::withMessages(['claim' => "This household already claimed on {$existing->claimed_at->format('M j, Y g:i A')} (released by ".($existing->releaser?->full_name ?? 'unknown').').']);
            }

            $claim = ReliefClaim::create([
                'relief_beneficiary_id' => $locked->id,
                'claimed_by_resident_id' => $data['claimed_by_resident_id'] ?? null,
                'claimant_name' => $data['claimant_name'],
                'relationship_to_head' => $data['relationship_to_head'] ?? null,
                'quantity_released' => $locked->entitlement_quantity,
                'claimed_at' => now(),
                'released_by' => Auth::id(),
            ]);
            $locked->update(['status' => 'claimed']);

            return $claim;
        });
    }

    public function reverseClaim(ReliefClaim $claim, string $reason): ReliefClaim
    {
        $this->assertStatus($claim->beneficiary->distribution, ['open']);
        if ($claim->reversed_at) {
            throw ValidationException::withMessages(['claim' => 'This claim was already reversed.']);
        }
        $claim->update(['reversal_reason' => $reason, 'reversed_by' => Auth::id(), 'reversed_at' => now()]);
        $claim->beneficiary->update(['status' => 'listed']);

        return $claim;
    }

    public function close(ReliefDistribution $distribution): ReliefDistribution
    {
        $this->assertStatus($distribution, ['open']);
        DB::transaction(function () use ($distribution) {
            $distribution->beneficiaries()->where('status', 'listed')->where('is_excluded', false)->where('within_allocation', true)->update(['status' => 'unclaimed']);
            $distribution->update(['status' => 'closed', 'closed_by' => Auth::id(), 'closed_at' => now()]);
        });
        Audit::log('relief_distribution_closed', 'B2', 'relief_distributions', $distribution->id);

        return $distribution;
    }

    private function activeMemberIds(Household $household): array
    {
        return HouseholdMember::where('household_id', $household->id)->whereNull('date_left')
            ->whereHas('resident', fn ($q) => $q->where('status', 'active'))->pluck('resident_id')->all();
    }

    private function assertStatus(ReliefDistribution $distribution, array $allowed): void
    {
        if (! in_array($distribution->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'This action is not allowed while the distribution is '.str_replace('_', ' ', $distribution->status).'.']);
        }
    }
}
