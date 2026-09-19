<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Household\StoreHouseholdRequest;
use App\Http\Resources\HouseholdResource;
use App\Models\Household;
use App\Services\HouseholdService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A2 — Household Management (SDD §4.4).
 */
class HouseholdController extends Controller
{
    public function __construct(private readonly HouseholdService $households)
    {
    }

    public function index(Request $request)
    {
        $query = Household::query()->with('members.resident');

        if ($purokId = $request->integer('purok_id')) {
            $query->where('purok_id', $purokId);
        }

        $households = $query->orderBy('household_number')->paginate($request->integer('per_page', 25));

        return HouseholdResource::collection($households);
    }

    public function show(Household $household)
    {
        $household->load('members.resident');

        return new HouseholdResource($household);
    }

    public function store(StoreHouseholdRequest $request)
    {
        $data = $request->safe()->except('members');
        $household = $this->households->create($data, $request->input('members'));

        return new HouseholdResource($household);
    }

    public function update(Request $request, Household $household)
    {
        $data = $request->validate([
            'house_number' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:255'],
            'dwelling_ownership' => ['nullable', 'in:owned,rented,shared,informal_settler,other'],
            'construction_type' => ['nullable', 'in:concrete,semi_concrete,wood,light_materials,other'],
            'water_source' => ['nullable', 'string', 'max:255'],
            'electricity_access' => ['sometimes', 'boolean'],
            'toilet_type' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,archived'],
            'remarks' => ['nullable', 'string'],
        ]);

        $this->households->update($household, $data);

        return new HouseholdResource($household->fresh('members.resident'));
    }

    public function addMember(Request $request, Household $household)
    {
        $data = $request->validate([
            'resident_id' => ['required', 'exists:residents,id'],
            'relationship_to_head' => ['nullable', 'string', 'max:100'],
            'is_head' => ['sometimes', 'boolean'],
        ]);

        $this->households->addMember($household, $data['resident_id'], $data['relationship_to_head'] ?? null, (bool) ($data['is_head'] ?? false));

        return new HouseholdResource($household->fresh('members.resident'));
    }

    public function promoteHead(Request $request, Household $household)
    {
        $data = $request->validate(['resident_id' => ['required', 'exists:residents,id']]);

        $this->households->promoteHead($household, $data['resident_id']);

        return new HouseholdResource($household->fresh('members.resident'));
    }

    public function split(Request $request, Household $household)
    {
        $data = $request->validate([
            'household_number' => ['required', 'string', 'max:50'],
            'purok_id' => ['nullable', 'exists:puroks,id'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:255'],
            'resident_ids' => ['required', 'array', 'min:1'],
            'resident_ids.*' => ['exists:residents,id'],
        ]);

        $newHousehold = $this->households->split(
            $household,
            collect($data)->except('resident_ids')->all(),
            $data['resident_ids']
        );

        return new HouseholdResource($newHousehold);
    }
}
