<?php

namespace App\Services;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\PopulationEvent;
use App\Models\Relationship;
use App\Models\Resident;
use App\Models\ResidentClassification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * B3 — Population monitoring (SDD §4.10). Population change is stored as dated
 * events; recording one applies its effect to the registry in one transaction
 * (SDD Table 4.2).
 */
class PopulationEventService
{
    public function __construct(
        private readonly ResidentService $residents,
        private readonly HouseholdService $households,
        private readonly RelationshipService $relationships,
    ) {}

    /** @return array{event: PopulationEvent, warning: ?string} */
    public function record(array $data): array
    {
        return DB::transaction(fn () => match ($data['event_type']) {
            'birth' => $this->birth($data),
            'death' => $this->death($data),
            'transfer_in' => $this->transferIn($data),
            'transfer_out' => $this->transferOut($data),
        });
    }

    /** Creates the resident, attaches the newborn to the mother's household, records parent relationships. */
    private function birth(array $data): array
    {
        $mother = isset($data['mother_resident_id']) ? Resident::findOrFail($data['mother_resident_id']) : null;
        $father = isset($data['father_resident_id']) ? Resident::findOrFail($data['father_resident_id']) : null;

        $baby = $this->residents->create([
            'last_name' => $data['last_name'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'sex' => $data['sex'],
            'birth_date' => $data['event_date'],
            'birth_place' => $data['birth_place'] ?? null,
            'civil_status' => 'single',
            'purok_id' => $mother?->purok_id ?? ($data['purok_id'] ?? null),
            'house_number' => $mother?->house_number,
            'street' => $mother?->street,
            'status' => 'active',
        ]);

        $membership = $mother ? HouseholdMember::where('resident_id', $mother->id)->whereNull('date_left')->first() : null;
        if ($membership) {
            $this->households->addMember($membership->household, $baby->id, 'child');
        }
        foreach ([$mother, $father] as $parent) {
            if ($parent) {
                $this->relationships->create($baby->id, $parent->id, 'child', null, $data['event_date']);
            }
        }

        return ['event' => $this->event($data, $baby), 'warning' => $membership ? null : 'The newborn was not added to a household because the mother has no current household.'];
    }

    /** Sets status deceased, closes household membership and active classifications, prompts for a new head. */
    private function death(array $data): array
    {
        $resident = $this->activeResident($data['resident_id']);
        $wasHead = HouseholdMember::where('resident_id', $resident->id)->whereNull('date_left')->where('is_head', true)->first();

        $resident->update(['status' => 'deceased', 'updated_by' => Auth::id()]);
        $this->closeMembership($resident, $data['event_date']);
        ResidentClassification::where('resident_id', $resident->id)->where('status', 'active')->get()->each->update(['status' => 'revoked']);
        Relationship::where(fn ($q) => $q->where('resident_id', $resident->id)->orWhere('related_resident_id', $resident->id))
            ->where('relationship_type', 'spouse')->whereNull('end_date')->update(['end_date' => $data['event_date']]);

        $warning = $wasHead ? "The deceased was head of household {$wasHead->household->household_number}. Promote a new household head." : null;

        return ['event' => $this->event($data, $resident), 'warning' => $warning];
    }

    /** Registers or reactivates the resident, records the previous address, assigns purok and household. */
    private function transferIn(array $data): array
    {
        if (! empty($data['resident_id'])) {
            $resident = Resident::findOrFail($data['resident_id']);
            if ($resident->status === 'active') {
                throw ValidationException::withMessages(['resident_id' => 'This resident is already active.']);
            }
            $resident->update(['status' => 'active', 'purok_id' => $data['purok_id'] ?? $resident->purok_id, 'updated_by' => Auth::id()]);
        } else {
            foreach (['last_name', 'first_name', 'sex', 'birth_date', 'civil_status'] as $field) {
                if (empty($data[$field])) {
                    throw ValidationException::withMessages([$field => 'Required to register a new resident who transferred in.']);
                }
            }
            $resident = $this->residents->create([
                'last_name' => $data['last_name'], 'first_name' => $data['first_name'], 'middle_name' => $data['middle_name'] ?? null,
                'sex' => $data['sex'], 'birth_date' => $data['birth_date'], 'civil_status' => $data['civil_status'],
                'purok_id' => $data['purok_id'] ?? null, 'residency_start_date' => $data['event_date'], 'status' => 'active',
            ]);
        }

        if (! empty($data['household_id'])) {
            $this->households->addMember(Household::findOrFail($data['household_id']), $resident->id, $data['relationship_to_head'] ?? null);
        }

        return ['event' => $this->event($data, $resident), 'warning' => null];
    }

    /** Sets status transferred_out with destination and date, and closes household membership. */
    private function transferOut(array $data): array
    {
        $resident = $this->activeResident($data['resident_id']);
        $wasHead = HouseholdMember::where('resident_id', $resident->id)->whereNull('date_left')->where('is_head', true)->first();
        $resident->update(['status' => 'transferred_out', 'updated_by' => Auth::id()]);
        $this->closeMembership($resident, $data['event_date']);

        return ['event' => $this->event($data, $resident), 'warning' => $wasHead ? "The resident was head of household {$wasHead->household->household_number}. Promote a new household head." : null];
    }

    private function event(array $data, Resident $resident): PopulationEvent
    {
        return PopulationEvent::create([
            'event_type' => $data['event_type'],
            'resident_id' => $resident->id,
            'event_date' => $data['event_date'],
            'reported_date' => $data['reported_date'] ?? now()->toDateString(),
            'purok_id' => $resident->purok_id,
            'document_type' => $data['document_type'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'previous_address' => $data['previous_address'] ?? null,
            'destination_address' => $data['destination_address'] ?? null,
            'cause_or_reason' => $data['cause_or_reason'] ?? null,
            'recorded_by' => Auth::id(),
            'remarks' => $data['remarks'] ?? null,
        ]);
    }

    private function activeResident(int $id): Resident
    {
        $resident = Resident::findOrFail($id);
        if ($resident->status !== 'active') {
            throw ValidationException::withMessages(['resident_id' => 'This resident is not active.']);
        }

        return $resident;
    }

    private function closeMembership(Resident $resident, string $date): void
    {
        HouseholdMember::where('resident_id', $resident->id)->whereNull('date_left')->update(['date_left' => $date, 'is_head' => false]);
    }
}
