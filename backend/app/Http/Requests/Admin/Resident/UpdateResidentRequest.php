<?php

namespace App\Http\Requests\Admin\Resident;

use Illuminate\Foundation\Http\FormRequest;

class UpdateResidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'last_name' => ['sometimes', 'string', 'max:255'],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'sex' => ['sometimes', 'in:male,female'],
            'birth_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'civil_status' => ['sometimes', 'in:single,married,widowed,separated,divorced,annulled'],
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
        ];
    }
}
