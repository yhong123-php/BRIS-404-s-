<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClassificationType;
use App\Models\Household;
use App\Models\Purok;
use App\Models\Resident;
use Illuminate\Http\Request;

/**
 * A4 — Purok Population Monitoring (SDD §4.6).
 *
 * Counts are derived by query rather than stored as editable figures; the
 * nightly purok_statistics snapshot (used by the dashboard) is a separate
 * scheduled job wired up in the C-series, not this controller.
 */
class PurokController extends Controller
{
    public function index()
    {
        $puroks = Purok::withCount(['residents', 'households'])->orderBy('purok_number')->get();

        return response()->json(['data' => $puroks]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:puroks,name'],
            'purok_number' => ['required', 'string', 'max:50'],
            'leader_resident_id' => ['nullable', 'exists:residents,id'],
            'description' => ['nullable', 'string'],
        ]);

        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;

        $purok = Purok::create($data);

        return response()->json(['data' => $purok], 201);
    }

    public function update(Request $request, Purok $purok)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', 'unique:puroks,name,'.$purok->id],
            'purok_number' => ['sometimes', 'string', 'max:50'],
            'leader_resident_id' => ['nullable', 'exists:residents,id'],
            'description' => ['nullable', 'string'],
        ]);

        $data['updated_by'] = $request->user()->id;
        $purok->update($data);

        return response()->json(['data' => $purok]);
    }

    /**
     * "Drilling into a purok lists its households" (SDD §4.6).
     */
    public function households(Purok $purok)
    {
        $households = Household::where('purok_id', $purok->id)->with('members.resident')->get();

        return response()->json(['data' => $households]);
    }

    /**
     * Live-computed counts (not the nightly snapshot): total residents,
     * households, sex distribution, age bands, and sectoral counts.
     */
    public function statistics(Purok $purok)
    {
        $residents = Resident::where('purok_id', $purok->id)->where('status', 'active')->get();

        $ageBands = [
            '0-6' => 0, '7-12' => 0, '13-17' => 0, '18-59' => 0, '60+' => 0,
        ];

        foreach ($residents as $resident) {
            $age = $resident->age ?? 0;
            $band = match (true) {
                $age <= 6 => '0-6',
                $age <= 12 => '7-12',
                $age <= 17 => '13-17',
                $age <= 59 => '18-59',
                default => '60+',
            };
            $ageBands[$band]++;
        }

        $residentIds = $residents->pluck('id');
        $sectoralCount = function (string $classificationName) use ($residentIds) {
            return ClassificationType::where('name', $classificationName)
                ->first()
                ?->residentClassifications()
                ->where('status', 'active')
                ->whereIn('resident_id', $residentIds)
                ->count() ?? 0;
        };

        return response()->json(['data' => [
            'purok_id' => $purok->id,
            'total_residents' => $residents->count(),
            'total_households' => Household::where('purok_id', $purok->id)->count(),
            'male_count' => $residents->where('sex', 'male')->count(),
            'female_count' => $residents->where('sex', 'female')->count(),
            'age_band_counts' => $ageBands,
            'senior_count' => $sectoralCount('Senior Citizen'),
            'pwd_count' => $sectoralCount('Person with Disability'),
            'solo_parent_count' => $sectoralCount('Solo Parent'),
        ]]);
    }
}
