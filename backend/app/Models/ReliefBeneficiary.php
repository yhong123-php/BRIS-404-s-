<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SDD module B2 — table relief_beneficiaries. */
class ReliefBeneficiary extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'B2';
    }

    protected $table = 'relief_beneficiaries';

    protected $fillable = [
        'relief_distribution_id',
        'household_id',
        'purok_id',
        'resident_id',
        'entitlement_quantity',
        'priority_score',
        'rank',
        'purok_rank',
        'duplicate_flag',
        'score_breakdown',
        'inclusion',
        'is_excluded',
        'within_allocation',
        'override_reason',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'duplicate_flag' => 'array',
            'score_breakdown' => 'array',
            'is_excluded' => 'boolean',
            'within_allocation' => 'boolean',
            'priority_score' => 'float',
        ];
    }

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(ReliefDistribution::class, 'relief_distribution_id');
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(ReliefClaim::class);
    }

    public function purok(): BelongsTo
    {
        return $this->belongsTo(Purok::class);
    }
}
