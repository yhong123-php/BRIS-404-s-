<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SDD module F1 — table ai_suggestions. */
class AiSuggestion extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'F1';
    }

    protected $table = 'ai_suggestions';

    protected $fillable = [
        'resident_id',
        'household_id',
        'suggestion_type',
        'program_name',
        'payload',
        'rationale',
        'engine_version',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
