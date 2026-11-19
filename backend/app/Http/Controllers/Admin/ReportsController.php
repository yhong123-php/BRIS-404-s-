<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\ClassificationType;
use App\Models\Resident;
use App\Models\ResidentClassification;
use App\Models\Skill;
use App\Models\SkillType;
use Illuminate\Http\Request;

/**
 * Reporting on data the system already collects — skills, classifications,
 * achievements — grouped/counted for the dashboard and the barangay's own
 * reporting needs (SDD addendum §4.2 use case 13 "Generate Skilled-Residents
 * List", and the equivalent for classifications/achievements). Deliberately
 * plain aggregate queries, not a prediction or inference of any kind: every
 * number here is an exact count of records staff already entered.
 */
class ReportsController extends Controller
{
    public function skills(Request $request)
    {
        // Scoped to currently-active residents throughout: archiving a
        // resident (ResidentService::archive()) never touches their skill
        // rows, so an unscoped count would include archived/deceased/
        // transferred residents' skills in a report about who's currently
        // available in the barangay.
        $byType = SkillType::withCount(['skills' => fn ($q) => $q->where('status', 'active')->whereHas('resident', fn ($r) => $r->where('status', 'active'))])
            ->having('skills_count', '>', 0)
            ->orderByDesc('skills_count')
            ->get()
            ->map(fn (SkillType $type) => [
                'skill_type_id' => $type->id,
                'name' => $type->name,
                'count' => $type->skills_count,
            ]);

        return response()->json([
            'data' => [
                'by_type' => $byType,
                'total_skilled_residents' => Skill::where('status', 'active')
                    ->whereHas('resident', fn ($q) => $q->where('status', 'active'))
                    ->distinct('resident_id')
                    ->count('resident_id'),
            ],
        ]);
    }

    /**
     * "Beneficiaries" here means residents holding a sectoral classification
     * that denotes an assistance program — currently just 4Ps Member, since
     * that's the only such classification type this system tracks. Relief
     * distribution (SDD B2) has no table yet, so it isn't represented here;
     * this endpoint doesn't claim to cover it.
     */
    public function beneficiaries(Request $request)
    {
        $data = $request->validate([
            'classification_type_id' => ['nullable', 'exists:classification_types,id'],
        ]);

        // Scoped to currently-active residents — same reasoning as skills():
        // archiving/merging a resident never revokes their classifications,
        // so an unscoped count would list archived/deceased/transferred
        // residents as current beneficiaries.
        $byType = ClassificationType::withCount(['residentClassifications as active_count' => fn ($q) => $q->where('status', 'active')->whereHas('resident', fn ($r) => $r->where('status', 'active'))])
            ->having('active_count', '>', 0)
            ->orderByDesc('active_count')
            ->get()
            ->map(fn (ClassificationType $type) => [
                'classification_type_id' => $type->id,
                'name' => $type->name,
                'count' => $type->active_count,
            ]);

        $defaultType = ClassificationType::where('name', '4Ps Member')->first();
        $typeId = $data['classification_type_id'] ?? $defaultType?->id;

        $beneficiaries = $typeId
            ? ResidentClassification::with(['resident.purok', 'classificationType'])
                ->where('classification_type_id', $typeId)
                ->where('status', 'active')
                ->whereHas('resident', fn ($q) => $q->where('status', 'active'))
                ->get()
                ->map(fn (ResidentClassification $c) => [
                    'resident_id' => $c->resident_id,
                    'resident_name' => optional($c->resident)->full_name,
                    'purok' => optional(optional($c->resident)->purok)->name,
                    'classification_type' => $c->classificationType->name,
                    'date_granted' => optional($c->date_granted)->toDateString(),
                ])
            : collect();

        return response()->json([
            'data' => [
                'by_type' => $byType,
                'selected_classification_type_id' => $typeId,
                'beneficiaries' => $beneficiaries,
            ],
        ]);
    }

    /**
     * Employment report from residents.employment_status (SDD Table 3.1).
     * Self-employed counts as employed; residents with no status recorded are
     * reported as "unspecified", never assumed either way.
     */
    public function employment(Request $request)
    {
        $counts = Resident::where('status', 'active')
            ->selectRaw('employment_status, count(*) as c')->groupBy('employment_status')->pluck('c', 'employment_status');

        $unemployedResidents = Resident::with('purok')
            ->where('status', 'active')->where('employment_status', 'unemployed')
            ->orderBy('last_name')->get()
            ->map(fn (Resident $r) => [
                'resident_id' => $r->id,
                'resident_name' => $r->full_name,
                'purok' => optional($r->purok)->name,
                'age' => $r->age,
            ]);

        return response()->json([
            'data' => [
                'employed_count' => (int) ($counts['employed'] ?? 0) + (int) ($counts['self_employed'] ?? 0),
                'unemployed_count' => (int) ($counts['unemployed'] ?? 0),
                'not_in_labor_force_count' => (int) ($counts['not_in_labor_force'] ?? 0),
                'unspecified_count' => (int) ($counts[''] ?? 0),
                'unemployed_residents' => $unemployedResidents,
            ],
        ]);
    }

    public function achievements(Request $request)
    {
        // Counted in PHP: a SQL alias named "category" collides with the real
        // column, which MySQL's ONLY_FULL_GROUP_BY mode rejects. NULL and empty
        // categories both count as "Uncategorized".
        $byCategory = Achievement::pluck('category')
            ->map(fn ($c) => filled($c) ? $c : 'Uncategorized')
            ->countBy()
            ->sortDesc()
            ->map(fn ($count, $category) => ['category' => $category, 'count' => $count])
            ->values();

        $recent = Achievement::with('resident')
            ->latest()
            ->take(10)
            ->get()
            ->map(fn (Achievement $a) => [
                'id' => $a->id,
                'resident_id' => $a->resident_id,
                'resident_name' => optional($a->resident)->full_name,
                'title' => $a->title,
                'category' => $a->category,
                'date_achieved' => optional($a->date_achieved)->toDateString(),
            ]);

        return response()->json([
            'data' => [
                'by_category' => $byCategory,
                'total' => Achievement::count(),
                'recent' => $recent,
            ],
        ]);
    }
}
