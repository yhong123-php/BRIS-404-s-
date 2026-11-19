<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SDD module B2 — table relief_distributions. */
class ReliefDistribution extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'B2';
    }

    protected $table = 'relief_distributions';

    protected $fillable = [
        'title',
        'description',
        'source_of_funds',
        'goods_description',
        'quantity_available',
        'quantity_per_beneficiary',
        'target_rule',
        'priority_weights',
        'allocation_mode',
        'start_date',
        'end_date',
        'venue',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'target_rule' => 'array',
            'priority_weights' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(ReliefBeneficiary::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
