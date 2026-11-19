<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Models\SocialAssistanceRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Social assistance received by residents (SRS §3.2.3.10, SDD Table 3.2):
 * recorded by the Barangay Secretary, viewable by both roles.
 */
class SocialAssistanceController extends Controller
{
    public function index(Resident $resident)
    {
        return response()->json([
            'data' => $resident->socialAssistance()->with('recorder:id,full_name')->orderByDesc('date_received')->get()
                ->map(fn (SocialAssistanceRecord $r) => $this->present($r)),
        ]);
    }

    public function store(Request $request, Resident $resident)
    {
        $data = $request->validate([
            'assistance_type' => ['required', Rule::in(SocialAssistanceRecord::TYPES)],
            'program_name' => ['required', 'string', 'max:255'],
            'description_or_amount' => ['nullable', 'string', 'max:255'],
            'date_received' => ['required', 'date', 'before_or_equal:today'],
            'source' => ['nullable', 'string', 'max:255'],
        ]);

        $record = $resident->socialAssistance()->create($data + [
            'household_id' => $resident->currentHouseholdMembership?->household_id,
            'recorded_by' => Auth::id(),
        ]);

        return response()->json(['data' => $this->present($record->load('recorder:id,full_name'))], 201);
    }

    private function present(SocialAssistanceRecord $r): array
    {
        return [
            'id' => $r->id,
            'assistance_type' => $r->assistance_type,
            'program_name' => $r->program_name,
            'description_or_amount' => $r->description_or_amount,
            'date_received' => $r->date_received->toDateString(),
            'source' => $r->source,
            'recorded_by' => $r->recorder?->full_name,
        ];
    }
}
