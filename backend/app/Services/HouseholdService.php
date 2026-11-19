<?php

namespace App\Services;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Resident;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A2 — Household Management (SDD §4.4).
 *
 * Business rules enforced here because they cannot be expressed as plain DB
 * constraints (SDD §3.6): a household must have exactly one active head, and
 * a resident belongs to at most one household at a time.
 */
class HouseholdService
{
    public function create(array $data, array $members = []): Household
    {
        return DB::transaction(function () use ($data, $members) {
            $data['created_by'] = Auth::id();
            $data['updated_by'] = Auth::id();
            $data['status'] = $data['status'] ?? 'active';

            $household = Household::create($data);

            if (! empty($members)) {
                $this->assertExactlyOneHead($members);

                foreach ($members as $member) {
                    $this->addMember($household, $member['resident_id'], $member['relationship_to_head'] ?? null, (bool) ($member['is_head'] ?? false));
                }
            }

            return $household->fresh('members');
        });
    }

    public function update(Household $household, array $data): Household
    {
        $data['updated_by'] = Auth::id();
        $household->update($data);

        return $household;
    }

    /**
     * Adds a resident to a household, closing any existing open membership
     * elsewhere first (SDD §4.3: "a resident belongs to at most one
     * household at a time; reassignment closes the previous membership with
     * an effective date").
     */
    public function addMember(Household $household, int $residentId, ?string $relationshipToHead, bool $isHead = false): HouseholdMember
    {
        return DB::transaction(function () use ($household, $residentId, $relationshipToHead, $isHead) {
            HouseholdMember::where('resident_id', $residentId)
                ->whereNull('date_left')
                ->where('household_id', '!=', $household->id)
                ->update(['date_left' => now()->toDateString()]);

            if ($isHead) {
                $this->demoteCurrentHead($household);
            }

            // Resident already has an open membership in this same household
            // (re-add, or a double-submitted request) — update it in place
            // instead of inserting a second row for today, which would
            // collide with the (household_id, resident_id, date_joined)
            // unique constraint.
            $existing = HouseholdMember::where('household_id', $household->id)
                ->where('resident_id', $residentId)
                ->whereNull('date_left')
                ->first();

            if ($existing) {
                $existing->update([
                    'relationship_to_head' => $isHead ? 'head' : $relationshipToHead,
                    'is_head' => $isHead,
                ]);

                return $existing;
            }

            return HouseholdMember::create([
                'household_id' => $household->id,
                'resident_id' => $residentId,
                'relationship_to_head' => $isHead ? 'head' : $relationshipToHead,
                'is_head' => $isHead,
                'date_joined' => now()->toDateString(),
            ]);
        });
    }

    /**
     * Promotes a new head, demoting the previous one in the same
     * transaction (SDD §4.4).
     */
    public function promoteHead(Household $household, int $residentId): HouseholdMember
    {
        return DB::transaction(function () use ($household, $residentId) {
            $this->demoteCurrentHead($household);

            $membership = HouseholdMember::where('household_id', $household->id)
                ->where('resident_id', $residentId)
                ->whereNull('date_left')
                ->firstOrFail();

            $membership->update(['is_head' => true, 'relationship_to_head' => 'head']);

            return $membership;
        });
    }

    /**
     * Splits a household: moves the selected members into a newly created
     * household with an effective date, leaving historical membership rows
     * intact (SDD §4.4).
     */
    public function split(Household $source, array $newHouseholdData, array $residentIdsToMove): Household
    {
        return DB::transaction(function () use ($source, $newHouseholdData, $residentIdsToMove) {
            $newHouseholdData['purok_id'] = $newHouseholdData['purok_id'] ?? $source->purok_id;
            $newHousehold = $this->create($newHouseholdData);

            $today = now()->toDateString();

            foreach ($residentIdsToMove as $residentId) {
                HouseholdMember::where('household_id', $source->id)
                    ->where('resident_id', $residentId)
                    ->whereNull('date_left')
                    ->update(['date_left' => $today]);

                HouseholdMember::create([
                    'household_id' => $newHousehold->id,
                    'resident_id' => $residentId,
                    'relationship_to_head' => null,
                    'is_head' => false,
                    'date_joined' => $today,
                ]);
            }

            return $newHousehold->fresh('members');
        });
    }

    private function demoteCurrentHead(Household $household): void
    {
        HouseholdMember::where('household_id', $household->id)
            ->where('is_head', true)
            ->whereNull('date_left')
            ->update(['is_head' => false]);
    }

    private function assertExactlyOneHead(array $members): void
    {
        $headCount = collect($members)->filter(fn ($m) => ! empty($m['is_head']))->count();

        if ($headCount !== 1) {
            throw ValidationException::withMessages([
                'members' => 'A household must have exactly one head.',
            ]);
        }
    }
}
