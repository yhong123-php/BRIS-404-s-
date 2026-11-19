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
            'employment_status' => ['nullable', 'in:'.implode(',', \App\Models\Resident::EMPLOYMENT_STATUSES)],
            'educational_attainment' => ['nullable', 'in:'.implode(',', array_keys(\App\Models\Resident::EDUCATION_LEVELS))],
            'monthly_income_bracket' => ['nullable', \Illuminate\Validation\Rule::in(\App\Models\Resident::INCOME_BRACKETS)],
            'contact_number' => ['nullable', 'string', 'regex:/^(09\d{9}|\+639\d{9}|\(?0\d{1,3}\)?[ -]?\d{3,4}[ -]?\d{4})$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'purok_id' => ['nullable', 'exists:puroks,id'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:255'],
            'voter_status' => ['nullable', 'in:registered,not_registered'],
            'residency_start_date' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:birth_date'],
            'remarks' => ['nullable', 'string'],

            // Duplicate-detection workflow (SDD §4.3 steps 3-4)
            'duplicate_check_acknowledged' => ['sometimes', 'boolean'],
            'merge_into_resident_id' => ['nullable', 'exists:residents,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_number.regex' => 'Enter a mobile number like 09171234567 (or +639171234567), or a landline with area code.',
            'residency_start_date.after_or_equal' => 'The resident cannot have lived here since before they were born.',
        ];
    }
}
