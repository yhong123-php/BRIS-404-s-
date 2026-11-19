<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SDD module G2 — table data_subject_requests. */
class DataSubjectRequest extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'G2';
    }

    protected $table = 'data_subject_requests';

    protected $fillable = [
        'resident_id',
        'request_type',
        'details',
        'received_by',
        'status',
        'decided_by',
        'decision_reason',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
