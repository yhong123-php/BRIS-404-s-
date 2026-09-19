<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Relationship;
use App\Models\Resident;
use App\Services\RelationshipService;
use Illuminate\Http\Request;

/**
 * A3 — Family Relationship Management (SDD §4.5).
 */
class RelationshipController extends Controller
{
    public function __construct(private readonly RelationshipService $relationships)
    {
    }

    public function index(Resident $resident)
    {
        $relationships = Relationship::with('relatedResident')
            ->where('resident_id', $resident->id)
            ->get()
            ->map(fn (Relationship $r) => [
                'id' => $r->id,
                'related_resident_id' => $r->related_resident_id,
                'related_resident_name' => optional($r->relatedResident)->full_name,
                'relationship_type' => $r->relationship_type,
                'family_id' => $r->family_id,
                'start_date' => optional($r->start_date)->toDateString(),
                'end_date' => optional($r->end_date)->toDateString(),
            ]);

        return response()->json(['data' => $relationships]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'resident_id' => ['required', 'exists:residents,id'],
            'related_resident_id' => ['required', 'exists:residents,id'],
            'relationship_type' => ['required', 'in:parent,child,spouse,sibling,guardian,other'],
            'family_id' => ['nullable', 'exists:families,id'],
            'start_date' => ['nullable', 'date'],
        ]);

        $relationship = $this->relationships->create(
            $data['resident_id'],
            $data['related_resident_id'],
            $data['relationship_type'],
            $data['family_id'] ?? null,
            $data['start_date'] ?? null,
        );

        return response()->json(['data' => $relationship], 201);
    }

    public function endSpouseRelation(Request $request, Relationship $relationship)
    {
        $data = $request->validate(['end_date' => ['required', 'date']]);

        $this->relationships->endSpouseRelation($relationship, $data['end_date']);

        return response()->json(['data' => $relationship->fresh()]);
    }
}
