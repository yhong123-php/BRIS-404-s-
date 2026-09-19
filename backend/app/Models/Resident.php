<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Resident extends Model
{
    use Auditable, SoftDeletes;

    public function auditModule(): string
    {
        return 'A1';
    }

    protected $fillable = [
        'resident_number', 'last_name', 'first_name', 'middle_name', 'suffix',
        'sex', 'birth_date', 'birth_place', 'civil_status', 'citizenship', 'religion',
        'occupation', 'educational_attainment', 'monthly_income_bracket',
        'contact_number', 'email', 'purok_id', 'house_number', 'street',
        'voter_status', 'residency_start_date', 'photo_path', 'status', 'remarks',
        'created_by', 'updated_by',
    ];

    /** Fields a Health Worker may edit via the field-level partial-edit permission (SDD Table 9.0). */
    public const HEALTH_EDITABLE_FIELDS = [
        'occupation', 'educational_attainment', 'monthly_income_bracket', 'remarks',
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
