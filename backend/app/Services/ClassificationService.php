<?php

namespace App\Services;

use App\Models\ClassificationType;
use App\Models\Resident;
use App\Models\ResidentClassification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A5 — Resident Classification (SDD §4.7).
 */
class ClassificationService
{
    /**
     * Assigns a classification to a resident. The DB's generated-column
     * unique index already prevents two *active* rows for the same
     * (resident, type), but we check first to return a clean 422 instead of
     * a raw DB error.
     */
    public function assign(int $residentId, int $classificationTypeId, array $data): ResidentClassification
    {
        $type = ClassificationType::findOrFail($classificationTypeId);

        $alreadyActive = ResidentClassification::where('resident_id', $residentId)
            ->where('classification_type_id', $classificationTypeId)
            ->where('status', 'active')
            ->exists();

        if ($alreadyActive) {
            throw ValidationException::withMessages([
                'classification_type_id' => 'This resident already has an active classification of this type.',
            ]);
        }

        if ($type->requires_reference_number && empty($data['reference_number'])) {
            throw ValidationException::withMessages([
                'reference_number' => 'This classification requires a reference number.',
            ]);
        }

        if ($type->requires_document && empty($data['document_path'])) {
            throw ValidationException::withMessages([
                'document_path' => 'This classification requires a supporting document.',
            ]);
        }

        return DB::transaction(fn () => ResidentClassification::create([
            'resident_id' => $residentId,
            'classification_type_id' => $classificationTypeId,
            'reference_number' => $data['reference_number'] ?? null,
            'date_granted' => $data['date_granted'] ?? now()->toDateString(),
            'expiry_date' => $data['expiry_date'] ?? null,
            'document_path' => $data['document_path'] ?? null,
            'verified_by' => Auth::id(),
            'status' => 'active',
        ]));
    }

    public function revoke(ResidentClassification $classification): ResidentClassification
    {
        $classification->update(['status' => 'revoked']);

        return $classification;
    }

    /**
     * SDD §4.7 "derived versus declared": age-based categories are computed
     * and offered as suggestions, but the assignment itself stays an
     * explicit staff action.
     */
    public function suggestAgeDerivedClassifications(Resident $resident): array
    {
        $age = $resident->age;
        if ($age === null) {
            return [];
        }

        $alreadyActiveTypeIds = ResidentClassification::where('resident_id', $resident->id)
            ->where('status', 'active')
            ->pluck('classification_type_id');

        return ClassificationType::where('is_age_derived', true)
            ->where('is_active', true)
            ->whereNotIn('id', $alreadyActiveTypeIds)
            ->get()
            ->filter(fn (ClassificationType $type) => $type->min_age === null || $age >= $type->min_age)
            ->values()
            ->map(fn (ClassificationType $type) => [
                'classification_type_id' => $type->id,
                'name' => $type->name,
                'reason' => "Age {$age} meets the minimum age of {$type->min_age} for {$type->name}.",
            ])
            ->all();
    }

    /**
     * Scheduled-job logic (SDD §4.7): flag classifications that have expired.
     * Exposed as a plain method for now; wired to the scheduler in the
     * C-series dashboard/action-list work.
     */
    public function expireOverdueClassifications(): int
    {
        return ResidentClassification::where('status', 'active')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', now()->toDateString())
            ->update(['status' => 'expired']);
    }
}
