<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Resident\StoreResidentRequest;
use App\Http\Requests\Admin\Resident\UpdateResidentRequest;
use App\Http\Resources\ResidentResource;
use App\Models\Resident;
use App\Services\ResidentService;
use Illuminate\Http\Request;

/**
 * A1 — Resident Registration and Updates / A3 — Search and View (SDD §4.3).
 */
class ResidentController extends Controller
{
    public function __construct(private readonly ResidentService $residents)
    {
    }

    /**
     * List/search residents. Supports free-text name search and purok/status
     * filters, server-side paginated (SDD §7 performance requirement).
     */
    public function index(Request $request)
    {
        $query = Resident::query()->with('purok');

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('last_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('resident_number', 'like', "%{$search}%");
            });
        }

        if ($purokId = $request->integer('purok_id')) {
            $query->where('purok_id', $purokId);
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        $residents = $query->orderBy('last_name')->orderBy('first_name')->paginate(
            $request->integer('per_page', 25)
        );

        return ResidentResource::collection($residents);
    }

    public function show(Resident $resident)
    {
        $resident->load('purok', 'classifications.classificationType', 'skills.skillType', 'achievements');

        return new ResidentResource($resident);
    }

    /**
     * SDD §4.3 steps 3-4: run duplicate detection first. If candidates exist
     * and staff has neither acknowledged nor chosen to merge, return the
     * candidates for review instead of saving.
     */
    public function store(StoreResidentRequest $request)
    {
        $data = $request->validated();

        if (! empty($data['merge_into_resident_id'])) {
            // Merging assumes the "duplicate" being registered is actually
            // the existing record found stale/incomplete; nothing new is
            // created — staff should instead edit the survivor record.
            return response()->json([
                'message' => 'To merge, update the existing resident record directly rather than resubmitting a new registration.',
            ], 422);
        }

        if (empty($data['duplicate_check_acknowledged'])) {
            $candidates = $this->residents->findDuplicateCandidates(
                $data['last_name'],
                $data['first_name'],
                $data['birth_date']
            );

            if (! empty($candidates)) {
                return response()->json([
                    'message' => 'Possible duplicate residents found. Review and resubmit with duplicate_check_acknowledged=true to proceed anyway.',
                    'candidates' => collect($candidates)->map(fn ($c) => [
                        'resident' => new ResidentResource($c['resident']),
                        'similarity_percent' => $c['similarity_percent'],
                        'same_birth_date' => $c['same_birth_date'],
                    ]),
                ], 409);
            }
        }

        unset($data['duplicate_check_acknowledged'], $data['merge_into_resident_id']);
        $resident = $this->residents->create($data);

        return new ResidentResource($resident);
    }

    public function update(UpdateResidentRequest $request, Resident $resident)
    {
        $this->residents->update($resident, $request->validated());

        return new ResidentResource($resident);
    }

    /**
     * Health Worker's field-level partial edit (SDD Table 9.0: "Partial").
     */
    public function updateHealthFields(Request $request, Resident $resident)
    {
        $data = $request->validate([
            'occupation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'educational_attainment' => ['sometimes', 'nullable', 'string', 'max:255'],
            'monthly_income_bracket' => ['sometimes', 'nullable', 'string', 'max:255'],
            'remarks' => ['sometimes', 'nullable', 'string'],
        ]);

        $this->residents->updateHealthFields($resident, $data);

        return new ResidentResource($resident);
    }

    public function merge(Request $request, Resident $resident)
    {
        $data = $request->validate([
            'survivor_resident_id' => ['required', 'exists:residents,id', 'different:resident'],
        ]);

        $survivor = Resident::findOrFail($data['survivor_resident_id']);
        $result = $this->residents->merge($resident, $survivor);

        return new ResidentResource($result);
    }

    public function archive(Request $request, Resident $resident)
    {
        $data = $request->validate(['remarks' => ['nullable', 'string']]);

        $this->residents->archive($resident, $data['remarks'] ?? null);

        return new ResidentResource($resident);
    }
}
