<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClassificationType;
use App\Models\Resident;
use App\Models\ResidentClassification;
use App\Services\ClassificationService;
use Illuminate\Http\Request;

/**
 * A5 — Resident Classification (SDD §4.7).
 */
class ClassificationController extends Controller
{
    public function __construct(private readonly ClassificationService $classifications)
    {
    }

    public function types()
    {
        return response()->json(['data' => ClassificationType::where('is_active', true)->orderBy('name')->get()]);
    }

    public function storeType(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:classification_types,name'],
            'description' => ['nullable', 'string'],
            'requires_reference_number' => ['sometimes', 'boolean'],
            'requires_document' => ['sometimes', 'boolean'],
            'has_expiry' => ['sometimes', 'boolean'],
            'is_age_derived' => ['sometimes', 'boolean'],
            'min_age' => ['nullable', 'integer', 'min:0', 'max:150'],
        ]);
        $data['is_active'] = true;

        return response()->json(['data' => ClassificationType::create($data)], 201);
    }

    public function index(Resident $resident)
    {
        $classifications = $resident->classifications()->with('classificationType')->get();

        return response()->json(['data' => $classifications]);
    }

    public function suggestions(Resident $resident)
    {
        return response()->json(['data' => $this->classifications->suggestAgeDerivedClassifications($resident)]);
    }

    public function store(Request $request, Resident $resident)
    {
        $data = $request->validate([
            'classification_type_id' => ['required', 'exists:classification_types,id'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'date_granted' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'document_path' => ['nullable', 'string'],
        ]);

        $classification = $this->classifications->assign($resident->id, $data['classification_type_id'], $data);

        return response()->json(['data' => $classification->load('classificationType')], 201);
    }

    public function revoke(ResidentClassification $classification)
    {
        $this->classifications->revoke($classification);

        return response()->json(['data' => $classification->fresh()]);
    }
}
