<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resident_number' => $this->resident_number,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'suffix' => $this->suffix,
            'full_name' => $this->full_name,
            'sex' => $this->sex,
            'birth_date' => optional($this->birth_date)->toDateString(),
            'age' => $this->age,
            'birth_place' => $this->birth_place,
            'civil_status' => $this->civil_status,
            'religion' => $this->religion,
            'citizenship' => $this->citizenship,
            'occupation' => $this->occupation,
            'employment_status' => $this->employment_status,
            'educational_attainment' => $this->educational_attainment,
            'monthly_income_bracket' => $this->monthly_income_bracket,
            'contact_number' => $this->contact_number,
            'email' => $this->email,
            'purok_id' => $this->purok_id,
            'purok' => $this->whenLoaded('purok', fn () => ['id' => $this->purok->id, 'name' => $this->purok->name]),
            'house_number' => $this->house_number,
            'street' => $this->street,
            'voter_status' => $this->voter_status,
            'residency_start_date' => optional($this->residency_start_date)->toDateString(),
            'status' => $this->status,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
