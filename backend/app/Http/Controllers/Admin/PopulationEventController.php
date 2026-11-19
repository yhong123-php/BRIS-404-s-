<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PopulationEvent;
use App\Services\PopulationEventService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** B3 — Population monitoring: births, deaths, transfers (SDD §4.10). */
class PopulationEventController extends Controller
{
    public function __construct(private readonly PopulationEventService $events) {}

    public function index(Request $request)
    {
        $query = PopulationEvent::with('resident:id,first_name,middle_name,last_name,suffix,resident_number', 'purok:id,name', 'recorder:id,full_name')->latest('event_date')->latest('id');
        if ($type = $request->string('event_type')->toString()) {
            $query->where('event_type', $type);
        }
        $page = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (PopulationEvent $e) => [
                'id' => $e->id, 'event_type' => $e->event_type, 'event_date' => $e->event_date->toDateString(),
                'reported_date' => $e->reported_date->toDateString(), 'resident' => ['id' => $e->resident->id, 'name' => $e->resident->full_name],
                'purok' => $e->purok?->name, 'document_type' => $e->document_type, 'document_number' => $e->document_number,
                'previous_address' => $e->previous_address, 'destination_address' => $e->destination_address,
                'cause_or_reason' => $e->cause_or_reason, 'recorded_by' => $e->recorder?->full_name,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request)
    {
        $type = $request->input('event_type');
        $data = $request->validate([
            'event_type' => ['required', Rule::in(['birth', 'death', 'transfer_in', 'transfer_out'])],
            'event_date' => ['required', 'date', 'before_or_equal:today'],
            'reported_date' => ['nullable', 'date'],
            'resident_id' => [Rule::requiredIf(in_array($type, ['death', 'transfer_out'], true)), 'nullable', 'exists:residents,id'],
            // birth / new transfer-in
            'last_name' => [Rule::requiredIf($type === 'birth'), 'nullable', 'string', 'max:255'],
            'first_name' => [Rule::requiredIf($type === 'birth'), 'nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'sex' => [Rule::requiredIf($type === 'birth'), 'nullable', 'in:male,female'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'civil_status' => ['nullable', 'in:single,married,widowed,separated,divorced,annulled'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'mother_resident_id' => ['nullable', 'exists:residents,id'],
            'father_resident_id' => ['nullable', 'exists:residents,id'],
            'purok_id' => ['nullable', 'exists:puroks,id'],
            'household_id' => ['nullable', 'exists:households,id'],
            'relationship_to_head' => ['nullable', 'string', 'max:100'],
            // supporting details
            'document_type' => ['nullable', 'string', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:255'],
            'previous_address' => ['nullable', 'string', 'max:255'],
            'destination_address' => [Rule::requiredIf($type === 'transfer_out'), 'nullable', 'string', 'max:255'],
            'cause_or_reason' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        $result = $this->events->record($data);

        return response()->json(['data' => $result['event'], 'warning' => $result['warning']], 201);
    }
}
