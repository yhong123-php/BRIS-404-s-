<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Models\Skill;
use App\Models\SkillType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A6 — Skills, Achievements, and Livelihood Registry (Addendum §2.1).
 */
class SkillController extends Controller
{
    public function types()
    {
        return response()->json(['data' => SkillType::where('is_active', true)->orderBy('name')->get()]);
    }

    public function storeType(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:skill_types,name'],
            'description' => ['nullable', 'string'],
        ]);
        $data['is_active'] = true;

        return response()->json(['data' => SkillType::create($data)], 201);
    }

    public function index(Resident $resident)
    {
        return response()->json(['data' => $resident->skills()->with('skillType')->get()]);
    }

    /**
     * Addendum §2.1: "A skill entry starts self-declared or staff-encoded
     * and is marked verified only when a staff member records a supporting
     * basis." This endpoint is the staff-encoded path (source=staff_encoded,
     * can be verified immediately if a document is on hand); the portal's
     * self-declared path is added in the E-series.
     */
    public function store(Request $request, Resident $resident)
    {
        $data = $request->validate([
            'skill_type_id' => ['required', 'exists:skill_types,id'],
            'proficiency_level' => ['nullable', 'in:beginner,intermediate,advanced,expert'],
            'document_path' => ['nullable', 'string'],
            'verify_now' => ['sometimes', 'boolean'],
        ]);

        $skill = Skill::create([
            'resident_id' => $resident->id,
            'skill_type_id' => $data['skill_type_id'],
            'proficiency_level' => $data['proficiency_level'] ?? null,
            'document_path' => $data['document_path'] ?? null,
            'source' => 'staff_encoded',
            'status' => 'active',
            'is_verified' => (bool) ($data['verify_now'] ?? false),
            'verified_by' => ! empty($data['verify_now']) ? Auth::id() : null,
            'verified_at' => ! empty($data['verify_now']) ? now() : null,
        ]);

        return response()->json(['data' => $skill->load('skillType')], 201);
    }

    /**
     * Marks an existing (e.g. self-declared) skill verified once staff has
     * checked a supporting basis, per the addendum's business rule.
     */
    public function verify(Skill $skill)
    {
        $skill->update([
            'is_verified' => true,
            'verified_by' => Auth::id(),
            'verified_at' => now(),
        ]);

        return response()->json(['data' => $skill->fresh()]);
    }

    public function revoke(Skill $skill)
    {
        $skill->update(['status' => 'revoked']);

        return response()->json(['data' => $skill->fresh()]);
    }

    /**
     * "Generate Skilled-Residents List" (addendum §4.2, use case 13): residents
     * grouped by verified skill, unverified entries visible but flagged.
     */
    public function bySkillType(SkillType $skillType)
    {
        $skills = Skill::with('resident')
            ->where('skill_type_id', $skillType->id)
            ->where('status', 'active')
            ->get()
            ->map(fn (Skill $s) => [
                'resident_id' => $s->resident_id,
                'resident_name' => optional($s->resident)->full_name,
                'proficiency_level' => $s->proficiency_level,
                'is_verified' => $s->is_verified,
            ]);

        return response()->json(['data' => $skills]);
    }
}
