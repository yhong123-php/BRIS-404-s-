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
     * Health Worker's field-level partial edit (SDD Table 9.0: "Partial").
     */
    public function updateHealthFields(Resident $resident, array $data): Resident
    {
        $allowed = array_intersect_key($data, array_flip(Resident::HEALTH_EDITABLE_FIELDS));
        $allowed['updated_by'] = Auth::id();
        $resident->update($allowed);

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
            HouseholdMember::where('resident_id', $duplicate->id)->update(['resident_id' => $survivor->id]);
            Relationship::where('resident_id', $duplicate->id)->update(['resident_id' => $survivor->id]);
            Relationship::where('related_resident_id', $duplicate->id)->update(['related_resident_id' => $survivor->id]);
            ResidentClassification::where('resident_id', $duplicate->id)->update(['resident_id' => $survivor->id]);
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
