<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Social assistance received by a resident (SDD Table 3.2, SRS §3.2.3.10).
 */
class SocialAssistanceRecord extends Model
{
    use Auditable;

    public const TYPES = ['medical', 'educational', 'livelihood', 'other'];

    public function auditModule(): string
    {
        return 'A5';
    }

    protected $fillable = [
        'resident_id', 'household_id', 'assistance_type', 'program_name',
        'description_or_amount', 'date_received', 'source', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['date_received' => 'date'];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
