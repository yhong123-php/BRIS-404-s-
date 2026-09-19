<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'A2';
    }

    protected $fillable = [
        'household_number', 'purok_id', 'house_number', 'street',
        'dwelling_ownership', 'construction_type', 'water_source',
        'electricity_access', 'toilet_type', 'status', 'remarks',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['electricity_access' => 'boolean'];
    }

    public function purok(): BelongsTo
    {
        return $this->belongsTo(Purok::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    public function currentMembers(): HasMany
    {
        return $this->hasMany(HouseholdMember::class)->whereNull('date_left');
    }

    public function head()
    {
        return $this->hasOne(HouseholdMember::class)->where('is_head', true)->whereNull('date_left');
    }
}
