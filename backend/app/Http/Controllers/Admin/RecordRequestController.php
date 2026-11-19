<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConsentPurpose;
use App\Models\DataSubjectRequest;
use App\Models\RecordCopy;
use App\Models\Resident;
use App\Models\ResidentConsent;
use App\Services\RecordRequestService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * E1 — Resident record copies (SDD §4.14),
 * G1 — Consent, G2 — Data-subject requests (SDD §4.21).
 */
class RecordRequestController extends Controller
{
    public function __construct(private readonly RecordRequestService $service) {}

    // ------------------------------------------------------------------ E1

    public function recordCopies(Resident $resident)
    {
        return response()->json(['data' => RecordCopy::where('resident_id', $resident->id)->with('issuer:id,full_name')->latest('id')->get()]);
    }

    /** Records the release and returns the data printed on the record-copy template. */
    public function issueRecordCopy(Request $request, Resident $resident)
    {
        $data = $request->validate([
            'requested_by_name' => ['required', 'string', 'max:255'],
            'relationship_to_resident' => ['nullable', 'string', 'max:100'],
            'id_presented' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:255'],
        ]);
        $copy = RecordCopy::create($data + ['resident_id' => $resident->id, 'issued_by' => $request->user()->id, 'issued_at' => now()]);

        $resident->load('purok', 'currentHouseholdMembership.household', 'classifications.classificationType');

        return response()->json(['data' => [
            'copy' => $copy->load('issuer:id,full_name'),
            'resident' => [
                'resident_number' => $resident->resident_number, 'full_name' => $resident->full_name, 'sex' => $resident->sex,
                'birth_date' => $resident->birth_date?->toDateString(), 'birth_place' => $resident->birth_place, 'age' => $resident->age,
                'civil_status' => $resident->civil_status, 'citizenship' => $resident->citizenship, 'religion' => $resident->religion,
                'occupation' => $resident->occupation, 'employment_status' => $resident->employment_status,
                'educational_attainment' => $resident->educational_attainment ? (Resident::EDUCATION_LEVELS[$resident->educational_attainment] ?? $resident->educational_attainment) : null,
                'contact_number' => $resident->contact_number, 'email' => $resident->email,
                'address' => trim(implode(', ', array_filter([$resident->house_number, $resident->street, $resident->purok?->name]))),
                'voter_status' => $resident->voter_status, 'residency_start_date' => $resident->residency_start_date?->toDateString(),
                'household_number' => $resident->currentHouseholdMembership?->household?->household_number,
                'relationship_to_head' => $resident->currentHouseholdMembership?->relationship_to_head,
                'classifications' => $resident->classifications->where('status', 'active')->map(fn ($c) => $c->classificationType->name)->values(),
            ],
        ]], 201);
    }

    // ------------------------------------------------------------------ G1

    public function purposes()
    {
        return response()->json(['data' => ConsentPurpose::orderBy('id')->get()]);
    }

    public function storePurpose(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:64', 'unique:consent_purposes,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => ConsentPurpose::create($data + ['is_optional' => true, 'is_active' => true])], 201);
    }

    public function updatePurpose(Request $request, ConsentPurpose $purpose)
    {
        $purpose->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return response()->json(['data' => $purpose]);
    }

    public function consents(Resident $resident)
    {
        $current = ResidentConsent::where('resident_id', $resident->id)->with('recorder:id,full_name')->get()->keyBy('consent_purpose_id');

        return response()->json(['data' => ConsentPurpose::where('is_active', true)->orderBy('id')->get()->map(fn (ConsentPurpose $p) => [
            'purpose' => $p,
            'consent' => $current[$p->id] ?? null,
        ])]);
    }

    public function recordConsent(Request $request, Resident $resident)
    {
        $data = $request->validate([
            'consent_purpose_id' => ['required', 'exists:consent_purposes,id'],
            'status' => ['required', Rule::in(['granted', 'revoked'])],
            'form_path' => ['nullable', 'string', 'max:255'],
        ]);
        $this->service->recordConsent($resident, $data['consent_purpose_id'], $data['status'], $data['form_path'] ?? null);

        return $this->consents($resident);
    }

    // ------------------------------------------------------------------ G2

    public function dataSubjectRequests(Request $request)
    {
        $query = DataSubjectRequest::with('resident:id,first_name,middle_name,last_name,suffix,resident_number', 'receiver:id,full_name', 'decider:id,full_name')->latest('id');
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        return response()->json(['data' => $query->limit(200)->get()->map(fn (DataSubjectRequest $d) => $d->toArray() + ['resident_name' => $d->resident->full_name])]);
    }

    public function receiveDataSubjectRequest(Request $request, Resident $resident)
    {
        $data = $request->validate([
            'request_type' => ['required', Rule::in(['access', 'correction', 'objection', 'erasure'])],
            'details' => ['required', 'string', 'max:2000'],
        ]);
        $dsr = DataSubjectRequest::create($data + ['resident_id' => $resident->id, 'received_by' => $request->user()->id, 'status' => 'received']);
        Audit::log('data_subject_request_received', 'G2', 'data_subject_requests', $dsr->id, ['type' => $dsr->request_type]);

        return response()->json(['data' => $dsr], 201);
    }

    public function decideDataSubjectRequest(Request $request, DataSubjectRequest $dataSubjectRequest)
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']]);

        return response()->json(['data' => $this->service->decideDataSubjectRequest($dataSubjectRequest, $data['approve'], $data['reason'] ?? null)]);
    }

    /** Access requests that were approved are served through E1; this marks them done. */
    public function completeDataSubjectRequest(DataSubjectRequest $dataSubjectRequest)
    {
        abort_unless($dataSubjectRequest->status === 'approved', 422, 'Only an approved request can be marked completed.');
        $dataSubjectRequest->update(['status' => 'completed']);

        return response()->json(['data' => $dataSubjectRequest]);
    }
}
