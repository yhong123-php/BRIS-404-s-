<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\HouseholdMember;
use App\Models\Relationship;
use App\Models\Resident;
use App\Models\ResidentClassification;
use App\Models\Skill;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A1 — Resident Registration and Updates (SDD §4.3).
 */
class ResidentService
{
    /**
     * Duplicate detection against last name, first name, and date of birth
     * (SDD §4.3 step 3), returning candidates with a similarity indication.
     */
    public function findDuplicateCandidates(string $lastName, string $firstName, string $birthDate): array
    {
        $sameBirthDate = Resident::query()
            ->where('birth_date', $birthDate)
            ->where(function ($q) use ($lastName, $firstName) {
                $q->whereRaw('SOUNDEX(last_name) = SOUNDEX(?)', [$lastName])
                    ->orWhereRaw('SOUNDEX(first_name) = SOUNDEX(?)', [$firstName]);
            })
            ->get();

        $sameName = Resident::query()
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($lastName)])
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($firstName)])
            ->get();

        $candidates = $sameBirthDate->concat($sameName)->unique('id');

        return $candidates
            ->map(function (Resident $candidate) use ($lastName, $firstName, $birthDate) {
                $nameSimilarity = 0;
                similar_text(
                    mb_strtolower("{$lastName} {$firstName}"),
                    mb_strtolower("{$candidate->last_name} {$candidate->first_name}"),
                    $nameSimilarity
                );
                $sameDob = (string) $candidate->birth_date->toDateString() === $birthDate;

                $score = round(($nameSimilarity + ($sameDob ? 100 : 0)) / 2, 1);

                return [
                    'resident' => $candidate,
                    'similarity_percent' => $score,
                    'same_birth_date' => $sameDob,
                ];
            })
            ->sortByDesc('similarity_percent')
            ->values()
            ->all();
    }

    /**
     * Create a resident once staff has confirmed it is not a duplicate.
     */
    public function create(array $data): Resident
    {
        return DB::transaction(function () use ($data) {
            $data['resident_number'] = $this->nextResidentNumber();
            $data['status'] = 'active';
            $data['created_by'] = Auth::id();
            $data['updated_by'] = Auth::id();

            return Resident::create($data);
        });
    }

    public function update(Resident $resident, array $data): Resident
    {
        $data['updated_by'] = Auth::id();
        $resident->update($data);

        return $resident;
    }

    /**
     * SDD §4.3 step 4: merge rewrites dependent foreign keys inside one
     * transaction. The duplicate is archived and soft-deleted rather than
     * hard-deleted, since it may already be referenced elsewhere and the
     * design forbids physical deletion of registry data (SDD §3.1, §4.3).
     */
    public function merge(Resident $duplicate, Resident $survivor): Resident
    {
        if ($duplicate->is($survivor)) {
            throw new \InvalidArgumentException('Cannot merge a resident into itself.');
        }

        return DB::transaction(function () use ($duplicate, $survivor) {
            $this->reassignHouseholdMemberships($duplicate, $survivor);
            $this->reassignRelationships($duplicate, $survivor);
            $this->reassignClassifications($duplicate, $survivor);
            Skill::where('resident_id', $duplicate->id)->update(['resident_id' => $survivor->id]);
            Achievement::where('resident_id', $duplicate->id)->update(['resident_id' => $survivor->id]);

            $duplicate->update([
                'status' => 'archived',
                'remarks' => trim(($duplicate->remarks ?? '')."\nMerged into resident #{$survivor->resident_number}."),
                'updated_by' => Auth::id(),
            ]);
            $duplicate->delete();

            return $survivor->fresh();
        });
    }

    /**
     * Two duplicate records of the same person are the common case a merge
     * exists for, so they very plausibly already share a household (or a
     * relationship, or a classification) that was entered independently
     * against each duplicate before anyone noticed. A blind bulk reassign of
     * resident_id onto the survivor would then collide with the unique
     * constraints below and throw a raw QueryException from inside a
     * transaction that staff can't recover from through the UI. Each
     * reassignment method here walks rows individually and drops a
     * duplicate's row instead of reassigning it whenever the survivor
     * already holds the equivalent record.
     */
    private function reassignHouseholdMemberships(Resident $duplicate, Resident $survivor): void
    {
        HouseholdMember::where('resident_id', $duplicate->id)->get()->each(function (HouseholdMember $member) use ($survivor) {
            $collides = HouseholdMember::where('household_id', $member->household_id)
                ->where('resident_id', $survivor->id)
                ->where('date_joined', $member->date_joined)
                ->exists();

            // Also guard the "at most one open household" invariant
            // (SDD §4.3) HouseholdService::addMember() otherwise enforces:
            // don't leave the survivor with two simultaneously-open rows.
            $survivorHasOpenElsewhere = is_null($member->date_left)
                && HouseholdMember::where('resident_id', $survivor->id)
                    ->where('household_id', '!=', $member->household_id)
                    ->whereNull('date_left')
                    ->exists();

            if ($collides) {
                $member->delete();
            } elseif ($survivorHasOpenElsewhere) {
                $member->update(['resident_id' => $survivor->id, 'date_left' => now()->toDateString()]);
            } else {
                $member->update(['resident_id' => $survivor->id]);
            }
        });
    }

    private function reassignRelationships(Resident $duplicate, Resident $survivor): void
    {
        Relationship::where('resident_id', $duplicate->id)->get()->each(function (Relationship $rel) use ($survivor) {
            if ($rel->related_resident_id === $survivor->id) {
                $rel->delete(); // the duplicate and survivor were linked to each other

                return;
            }

            $collides = Relationship::where('resident_id', $survivor->id)
                ->where('related_resident_id', $rel->related_resident_id)
                ->where('relationship_type', $rel->relationship_type)
                ->exists();

            $collides ? $rel->delete() : $rel->update(['resident_id' => $survivor->id]);
        });

        Relationship::where('related_resident_id', $duplicate->id)->get()->each(function (Relationship $rel) use ($survivor) {
            if ($rel->resident_id === $survivor->id) {
                $rel->delete();

                return;
            }

            $collides = Relationship::where('related_resident_id', $survivor->id)
                ->where('resident_id', $rel->resident_id)
                ->where('relationship_type', $rel->relationship_type)
                ->exists();

            $collides ? $rel->delete() : $rel->update(['related_resident_id' => $survivor->id]);
        });
    }

    private function reassignClassifications(Resident $duplicate, Resident $survivor): void
    {
        ResidentClassification::where('resident_id', $duplicate->id)->get()->each(function (ResidentClassification $classification) use ($survivor) {
            $collides = $classification->status === 'active'
                && ResidentClassification::where('resident_id', $survivor->id)
                    ->where('classification_type_id', $classification->classification_type_id)
                    ->where('status', 'active')
                    ->exists();

            $collides
                ? $classification->delete()
                : $classification->update(['resident_id' => $survivor->id]);
        });
    }

    /**
     * SDD §4.3: a resident cannot be deleted once referenced; status becomes
     * deceased, transferred_out, or archived instead.
     */
    public function archive(Resident $resident, ?string $remarks = null): Resident
    {
        $resident->update([
            'status' => 'archived',
            'remarks' => $remarks ?? $resident->remarks,
            'updated_by' => Auth::id(),
        ]);

        return $resident;
    }

    /**
     * SRS §2.3 / SDD Table 5.1: only the Barangay Captain restores an archived
     * record. Deceased and transferred-out records are changed through
     * population events (B3), not restored here.
     */
    public function restore(Resident $resident): Resident
    {
        $resident->update([
            'status' => 'active',
            'updated_by' => Auth::id(),
        ]);

        return $resident;
    }

    private function nextResidentNumber(): string
    {
        $year = now()->year;
        $prefix = "RN-{$year}-";

        $last = Resident::withTrashed()
            ->where('resident_number', 'like', "{$prefix}%")
            ->lockForUpdate()
            ->orderByDesc('resident_number')
            ->first();

        $next = $last ? ((int) substr($last->resident_number, -5)) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
