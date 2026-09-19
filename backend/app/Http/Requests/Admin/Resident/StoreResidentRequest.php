<?php

namespace App\Http\Requests\Admin\Resident;

use Illuminate\Foundation\Http\FormRequest;

class StoreResidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the `permission:residents.create` route middleware
    }

    public function rules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'sex' => ['required', 'in:male,female'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'civil_status' => ['required', 'in:single,married,widowed,separated,divorced,annulled'],
            'citizenship' => ['nullable', 'string', 'max:255'],
            'religion' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'educational_attainment' => ['nullable', 'string', 'max:255'],
            'monthly_income_bracket' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'purok_id' => ['nullable', 'exists:puroks,id'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:255'],
            'voter_status' => ['nullable', 'in:registered,not_registered'],
            'residency_start_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],

            // Duplicate-detection workflow (SDD §4.3 steps 3-4)
            'duplicate_check_acknowledged' => ['sometimes', 'boolean'],
            'merge_into_resident_id' => ['nullable', 'exists:residents,id'],
        ];
    }
}
