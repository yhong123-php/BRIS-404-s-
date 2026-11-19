<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Resident extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public function auditModule(): string
    {
        return 'A1';
    }

    protected $fillable = [
        'resident_number', 'last_name', 'first_name', 'middle_name', 'suffix',
        'sex', 'birth_date', 'birth_place', 'civil_status', 'citizenship', 'religion',
        'occupation', 'employment_status', 'educational_attainment', 'monthly_income_bracket',
        'contact_number', 'email', 'purok_id', 'house_number', 'street',
        'voter_status', 'residency_start_date', 'photo_path', 'status', 'remarks',
        'created_by', 'updated_by',
    ];

    /** SDD Table 3.1 — residents.employment_status. */
    public const EMPLOYMENT_STATUSES = ['employed', 'self_employed', 'unemployed', 'not_in_labor_force'];

    /** Monthly household income brackets offered at registration. */
    public const INCOME_BRACKETS = [
        'No income', 'Below 5,000', '5,000–10,000', '10,000–20,000', '20,000–40,000', 'Above 40,000',
    ];

    /**
     * Highest educational attainment (SRS §3.2.3.5, §3.2.3.10). A fixed list so
     * the assistant's education queries and reports count consistently.
     */
    public const EDUCATION_LEVELS = [
        'no_formal_education' => 'No formal education',
        'elementary_level' => 'Elementary level',
        'elementary_graduate' => 'Elementary graduate',
        'high_school_level' => 'High school level',
        'high_school_graduate' => 'High school graduate',
        'vocational' => 'Vocational / technical',
        'college_level' => 'College level',
        'college_graduate' => 'College graduate',
        'post_graduate' => 'Post-graduate',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'residency_start_date' => 'date',
        ];
    }

    public function purok(): BelongsTo
    {
        return $this->belongsTo(Purok::class);
    }

    public function householdMemberships(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    public function currentHouseholdMembership()
    {
        return $this->hasOne(HouseholdMember::class)->whereNull('date_left')->latestOfMany('date_joined');
    }

    public function relationshipsFrom(): HasMany
    {
        return $this->hasMany(Relationship::class, 'resident_id');
    }

    public function classifications(): HasMany
    {
        return $this->hasMany(ResidentClassification::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(Skill::class);
    }

    public function socialAssistance(): HasMany
    {
        return $this->hasMany(SocialAssistanceRecord::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(Achievement::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->last_name}, {$this->first_name} {$this->middle_name} {$this->suffix}");
    }

    public function getAgeAttribute(): ?int
    {
        return $this->birth_date ? Carbon::parse($this->birth_date)->age : null;
    }
}
