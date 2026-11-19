<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\Relationship;
use Illuminate\Http\Request;

/**
 * A3 — Family Relationship Management (SDD §4.5). The families table itself
 * had a model and migration but no CRUD surface: RelationshipController
 * could attach a relationship to an existing family_id, but nothing could
 * create that family in the first place. This closes that gap.
 */
class FamilyController extends Controller
{
    public function index(Request $request)
    {
        $families = Family::with('head')
            ->withCount('relationships')
            ->orderBy('family_name')
            ->paginate(20);

        return response()->json([
            'data' => $families->getCollection()->map(fn (Family $family) => [
                'id' => $family->id,
                'family_name' => $family->family_name,
                'family_head_id' => $family->family_head_id,
                'family_head_name' => optional($family->head)->full_name,
                'member_count' => $family->relationships_count,
                'remarks' => $family->remarks,
            ]),
            'meta' => [
                'current_page' => $families->currentPage(),
                'last_page' => $families->lastPage(),
                'total' => $families->total(),
            ],
        ]);
    }

    public function show(Family $family)
    {
        $family->load('head');

        $members = Relationship::with(['resident', 'relatedResident'])
            ->where('family_id', $family->id)
            ->get()
            ->map(fn (Relationship $r) => [
                'id' => $r->id,
                'resident_id' => $r->resident_id,
                'resident_name' => optional($r->resident)->full_name,
                'related_resident_id' => $r->related_resident_id,
                'related_resident_name' => optional($r->relatedResident)->full_name,
                'relationship_type' => $r->relationship_type,
                'start_date' => optional($r->start_date)->toDateString(),
                'end_date' => optional($r->end_date)->toDateString(),
            ]);

        return response()->json([
            'data' => [
                'id' => $family->id,
                'family_name' => $family->family_name,
                'family_head_id' => $family->family_head_id,
                'family_head_name' => optional($family->head)->full_name,
                'remarks' => $family->remarks,
                'relationships' => $members,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'family_name' => ['required', 'string', 'max:255'],
            'family_head_id' => ['nullable', 'exists:residents,id'],
            'remarks' => ['nullable', 'string'],
        ]);

        $family = Family::create($data);

        return response()->json(['data' => $family], 201);
    }

    public function update(Request $request, Family $family)
    {
        $data = $request->validate([
            'family_name' => ['sometimes', 'string', 'max:255'],
            'family_head_id' => ['nullable', 'exists:residents,id'],
            'remarks' => ['nullable', 'string'],
        ]);

        $family->update($data);

        return response()->json(['data' => $family->fresh()]);
    }
}
