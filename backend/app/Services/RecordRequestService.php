<?php

namespace App\Services;

use App\Models\AiSuggestion;
use App\Models\ConsentPurpose;
use App\Models\DataSubjectRequest;
use App\Models\OpportunityMatch;
use App\Models\Resident;
use App\Models\ResidentConsent;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * G1 — Consent management and G2 — Data-subject requests (SDD §4.21).
 * The Secretary records; the Captain decides.
 */
class RecordRequestService
{
    // ------------------------------------------------------------------ G1

    public function recordConsent(Resident $resident, int $purposeId, string $status, ?string $formPath): ResidentConsent
    {
        ConsentPurpose::where('is_active', true)->findOrFail($purposeId);

        $consent = ResidentConsent::firstOrNew(['resident_id' => $resident->id, 'consent_purpose_id' => $purposeId]);
        $consent->fill([
            'status' => $status,
            'form_path' => $formPath ?? $consent->form_path,
            'recorded_by' => Auth::id(),
            'recorded_at' => $consent->recorded_at ?? now(),
            'revoked_at' => $status === 'revoked' ? now() : null,
        ])->save();

        // A revocation takes effect immediately for opportunity matching (SDD §4.21).
        if ($status === 'revoked' && $consent->purpose->code === 'opportunity_matching') {
            OpportunityMatch::where('resident_id', $resident->id)->whereHas('opportunity', fn ($q) => $q->where('status', 'open'))->delete();
        }

        return $consent;
    }

    public static function hasConsent(int $residentId, string $code): bool
    {
        return ResidentConsent::where('resident_id', $residentId)->where('status', 'granted')
            ->whereHas('purpose', fn ($q) => $q->where('code', $code)->where('is_active', true))->exists();
    }

    /** IDs of residents who granted a consent purpose. */
    public static function consentingResidentIds(string $code)
    {
        return ResidentConsent::where('status', 'granted')->whereHas('purpose', fn ($q) => $q->where('code', $code)->where('is_active', true))->pluck('resident_id');
    }

    // ------------------------------------------------------------------ G2

    /**
     * The Captain decides. Objection or erasure removes analytics-derived data
     * (suggestions and matches) and revokes optional consents; registry data the
     * barangay must keep is blocked rather than erased (SDD §4.21).
     */
    public function decideDataSubjectRequest(DataSubjectRequest $request, bool $approve, ?string $reason): DataSubjectRequest
    {
        if ($request->status !== 'received') {
            throw ValidationException::withMessages(['status' => 'This request has already been decided.']);
        }

        DB::transaction(function () use ($request, $approve, $reason) {
            $status = $approve ? 'approved' : 'rejected';
            if ($approve && in_array($request->request_type, ['objection', 'erasure'], true)) {
                AiSuggestion::where('resident_id', $request->resident_id)->delete();
                OpportunityMatch::where('resident_id', $request->resident_id)->delete();
                ResidentConsent::where('resident_id', $request->resident_id)->where('status', 'granted')
                    ->update(['status' => 'revoked', 'revoked_at' => now(), 'recorded_by' => Auth::id()]);
                $status = 'completed';
                $reason = trim(($reason ? $reason.' ' : '').'Analytics-derived data removed and optional consents revoked; official registry data is retained as a public record.');
            }
            $request->update(['status' => $status, 'decided_by' => Auth::id(), 'decision_reason' => $reason, 'decided_at' => now()]);
        });
        Audit::log('data_subject_request_'.$request->status, 'G2', 'data_subject_requests', $request->id, ['type' => $request->request_type]);

        return $request;
    }
}
