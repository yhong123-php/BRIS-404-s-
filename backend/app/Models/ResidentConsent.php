<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SDD module G1 — table resident_consents. */
class ResidentConsent extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'G1';
    }

    protected $table = 'resident_consents';

    protected $fillable = [
        'resident_id',
        'consent_purpose_id',
        'status',
        'form_path',
        'recorded_by',
        'recorded_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function purpose(): BelongsTo
    {
        return $this->belongsTo(ConsentPurpose::class, 'consent_purpose_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
