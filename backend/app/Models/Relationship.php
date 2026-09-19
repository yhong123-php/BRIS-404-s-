<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Relationship extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'A3';
    }

    protected $fillable = [
        'resident_id', 'related_resident_id', 'relationship_type', 'family_id', 'start_date', 'end_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id');
    }

    public function relatedResident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'related_resident_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
