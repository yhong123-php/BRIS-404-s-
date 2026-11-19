<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SDD module B2 — table relief_claims. */
class ReliefClaim extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'B2';
    }

    protected $table = 'relief_claims';

    protected $fillable = [
        'relief_beneficiary_id',
        'claimed_by_resident_id',
        'claimant_name',
        'relationship_to_head',
        'quantity_released',
        'claimed_at',
        'released_by',
        'reversal_reason',
        'reversed_by',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(ReliefBeneficiary::class, 'relief_beneficiary_id');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
