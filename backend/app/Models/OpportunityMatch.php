<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SDD module F2 — table opportunity_matches. */
class OpportunityMatch extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'F2';
    }

    protected $table = 'opportunity_matches';

    protected $fillable = [
        'opportunity_id',
        'resident_id',
        'purok_id',
        'score',
        'rank',
        'purok_rank',
        'within_slots',
        'outcome',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'score' => 'float',
            'within_slots' => 'boolean',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function purok(): BelongsTo
    {
        return $this->belongsTo(Purok::class);
    }
}
