<?php

namespace App\Services;

use App\Models\Relationship;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A3 — Family Relationship Management (SDD §4.5).
 */
class RelationshipService
{
    private const INVERSE_TYPE = [
        'parent' => 'child',
        'child' => 'parent',
        'spouse' => 'spouse',
        'sibling' => 'sibling',
        'guardian' => null, // no defined inverse term in the SDD's enum; recorded one-directional
        'other' => 'other',
    ];

    /**
     * Records "X is <type> of Y" and, per SDD §4.5, also writes the inverse
     * pair "Y is <inverse type> of X" so traversal in either direction is one
     * indexed lookup.
     */
    public function create(int $residentId, int $relatedResidentId, string $type, ?int $familyId, ?string $startDate): Relationship
    {
        if ($residentId === $relatedResidentId) {
            throw ValidationException::withMessages([
                'related_resident_id' => 'A resident cannot be related to themselves.',
            ]);
        }

        if (in_array($type, ['parent', 'child'], true)) {
            $this->assertNoCycle($residentId, $relatedResidentId, $type);
        }

        if ($type === 'spouse') {
            $this->assertNoActiveSpouse($residentId);
            $this->assertNoActiveSpouse($relatedResidentId);
        }

        return DB::transaction(function () use ($residentId, $relatedResidentId, $type, $familyId, $startDate) {
            $relationship = Relationship::create([
                'resident_id' => $residentId,
                'related_resident_id' => $relatedResidentId,
                'relationship_type' => $type,
                'family_id' => $familyId,
                'start_date' => $startDate,
            ]);

            $inverseType = self::INVERSE_TYPE[$type] ?? null;

            if ($inverseType !== null) {
                Relationship::firstOrCreate([
                    'resident_id' => $relatedResidentId,
                    'related_resident_id' => $residentId,
                    'relationship_type' => $inverseType,
                ], [
                    'family_id' => $familyId,
                    'start_date' => $startDate,
                ]);
            }

            return $relationship;
        });
    }

    /**
     * SDD §4.5: "an existing spouse relation must be closed with an end date
     * before a new one is recorded."
     */
    public function endSpouseRelation(Relationship $relationship, string $endDate): Relationship
    {
        if ($relationship->relationship_type !== 'spouse') {
            throw ValidationException::withMessages(['relationship' => 'Not a spouse relationship.']);
        }

        return DB::transaction(function () use ($relationship, $endDate) {
            $relationship->update(['end_date' => $endDate]);

            Relationship::where('resident_id', $relationship->related_resident_id)
                ->where('related_resident_id', $relationship->resident_id)
                ->where('relationship_type', 'spouse')
                ->whereNull('end_date')
                ->update(['end_date' => $endDate]);

            return $relationship;
        });
    }

    private function assertNoActiveSpouse(int $residentId): void
    {
        $hasActiveSpouse = Relationship::where('resident_id', $residentId)
            ->where('relationship_type', 'spouse')
            ->whereNull('end_date')
            ->exists();

        if ($hasActiveSpouse) {
            throw ValidationException::withMessages([
                'relationship_type' => 'This resident already has an active spouse relation; end it before recording a new one.',
            ]);
        }
    }

    /**
     * Rejects a parent/child link that would create a cycle (SDD §4.5:
     * "Parent-child cycles are rejected"), by walking the ancestor chain of
     * the proposed parent to make sure the proposed child isn't already an
     * ancestor of it.
     */
    private function assertNoCycle(int $residentId, int $relatedResidentId, string $type): void
    {
        [$parentId, $childId] = $type === 'parent'
            ? [$residentId, $relatedResidentId]
            : [$relatedResidentId, $residentId];

        if ($this->isAncestor($childId, $parentId)) {
            throw ValidationException::withMessages([
                'relationship_type' => 'This would create a parent-child cycle.',
            ]);
        }
    }

    private function isAncestor(int $candidateAncestorId, int $residentId, array $visited = []): bool
    {
        if (in_array($residentId, $visited, true)) {
            return false;
        }
        $visited[] = $residentId;

        $parentIds = Relationship::where('resident_id', $residentId)
            ->where('relationship_type', 'child') // resident is child of these related_resident_ids
            ->pluck('related_resident_id');

        foreach ($parentIds as $parentId) {
            if ($parentId === $candidateAncestorId) {
                return true;
            }
            if ($this->isAncestor($candidateAncestorId, $parentId, $visited)) {
                return true;
            }
        }

        return false;
    }
}
