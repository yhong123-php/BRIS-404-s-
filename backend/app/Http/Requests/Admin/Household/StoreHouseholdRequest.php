<?php

namespace App\Http\Requests\Admin\Household;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreHouseholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'household_number' => ['required', 'string', 'max:50'],
            'purok_id' => ['required', 'exists:puroks,id'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:255'],
            'dwelling_ownership' => ['nullable', 'in:owned,rented,shared,informal_settler,other'],
            'construction_type' => ['nullable', 'in:concrete,semi_concrete,wood,light_materials,other'],
            'water_source' => ['nullable', 'string', 'max:255'],
            'electricity_access' => ['sometimes', 'boolean'],
            'toilet_type' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],

            // SDD §4.4: a household must have at least one member and exactly one head.
            'members' => ['required', 'array', 'min:1'],
            'members.*.resident_id' => ['required', 'exists:residents,id', 'distinct'],
            'members.*.relationship_to_head' => ['nullable', 'string', 'max:100'],
            'members.*.is_head' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $members = $this->input('members', []);
            $headCount = collect($members)->filter(fn ($m) => ! empty($m['is_head']))->count();

            if ($headCount !== 1) {
                $validator->errors()->add('members', 'A household must have exactly one head.');
            }
        });
    }
}
