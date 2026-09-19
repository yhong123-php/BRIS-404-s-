<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassificationType extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'A5';
    }

    protected $fillable = [
        'name', 'description', 'requires_reference_number', 'requires_document',
        'has_expiry', 'is_age_derived', 'min_age', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_reference_number' => 'boolean',
            'requires_document' => 'boolean',
            'has_expiry' => 'boolean',
            'is_age_derived' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function residentClassifications(): HasMany
    {
        return $this->hasMany(ResidentClassification::class);
    }
}
