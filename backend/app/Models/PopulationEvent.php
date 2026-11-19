<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SDD module B3 — table population_events. */
class PopulationEvent extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'B3';
    }

    protected $table = 'population_events';

    protected $fillable = [
        'event_type',
        'resident_id',
        'event_date',
        'reported_date',
        'purok_id',
        'document_type',
        'document_number',
        'previous_address',
        'destination_address',
        'cause_or_reason',
        'recorded_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'reported_date' => 'date',
        ];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function purok(): BelongsTo
    {
        return $this->belongsTo(Purok::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
