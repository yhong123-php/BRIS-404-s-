<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HouseholdResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'household_number' => $this->household_number,
            'purok_id' => $this->purok_id,
            'house_number' => $this->house_number,
            'street' => $this->street,
            'dwelling_ownership' => $this->dwelling_ownership,
            'construction_type' => $this->construction_type,
            'water_source' => $this->water_source,
            'electricity_access' => $this->electricity_access,
            'toilet_type' => $this->toilet_type,
            'status' => $this->status,
            'remarks' => $this->remarks,
            'size' => $this->whenLoaded('members', fn () => $this->members->whereNull('date_left')->count()),
            'members' => $this->whenLoaded('members', function () {
                return $this->members->whereNull('date_left')->values()->map(fn ($m) => [
                    'id' => $m->id,
                    'resident_id' => $m->resident_id,
                    'resident_name' => optional($m->resident)->full_name,
                    'relationship_to_head' => $m->relationship_to_head,
                    'is_head' => $m->is_head,
                    'date_joined' => optional($m->date_joined)->toDateString(),
                ]);
            }),
        ];
    }
}
