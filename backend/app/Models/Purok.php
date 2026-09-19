<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purok extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'A4';
    }

    protected $fillable = [
        'name', 'purok_number', 'leader_resident_id', 'description', 'created_by', 'updated_by',
    ];

    public function leader(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'leader_resident_id');
    }

    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class);
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }

    public function statistics(): HasMany
    {
        return $this->hasMany(PurokStatistic::class);
    }
}
