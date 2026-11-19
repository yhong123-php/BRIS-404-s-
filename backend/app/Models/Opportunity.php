<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SDD module F2 — table opportunities. */
class Opportunity extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'F2';
    }

    protected $table = 'opportunities';

    protected $fillable = [
        'title',
        'type',
        'provider',
        'required_skills',
        'target_purok_ids',
        'min_age',
        'max_age',
        'slots',
        'allocation_mode',
        'closing_date',
        'status',
        'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'required_skills' => 'array',
            'target_purok_ids' => 'array',
            'closing_date' => 'date',
        ];
    }

    public function matches(): HasMany
    {
        return $this->hasMany(OpportunityMatch::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
