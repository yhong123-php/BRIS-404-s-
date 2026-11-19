<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SDD module C3 — table demographic_forecasts. */
class DemographicForecast extends Model
{
    protected $table = 'demographic_forecasts';

    protected $fillable = [
        'purok_id',
        'metric',
        'period',
        'projected_value',
        'method',
        'input_from',
        'input_to',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'input_from' => 'date',
            'input_to' => 'date',
            'generated_at' => 'datetime',
            'projected_value' => 'float',
        ];
    }

    public function purok(): BelongsTo
    {
        return $this->belongsTo(Purok::class);
    }
}
