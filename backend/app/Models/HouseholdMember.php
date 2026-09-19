<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdMember extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'A2';
    }

    protected $fillable = [
        'household_id', 'resident_id', 'relationship_to_head', 'is_head', 'date_joined', 'date_left',
    ];

    protected function casts(): array
    {
        return [
            'is_head' => 'boolean',
            'date_joined' => 'date',
            'date_left' => 'date',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }
}
