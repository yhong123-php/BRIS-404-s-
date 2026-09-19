<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurokStatistic extends Model
{
    // Nightly-job snapshot rows; not user-editable, so not wrapped in Auditable.

    protected $fillable = [
        'purok_id', 'snapshot_date', 'total_residents', 'total_households',
        'male_count', 'female_count', 'age_band_counts',
        'senior_count', 'pwd_count', 'solo_parent_count',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'age_band_counts' => 'array',
        ];
    }

    public function purok(): BelongsTo
    {
        return $this->belongsTo(Purok::class);
    }
}
