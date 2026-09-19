<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Family extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'A3';
    }

    protected $fillable = ['family_name', 'family_head_id', 'remarks'];

    public function head(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'family_head_id');
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(Relationship::class);
    }
}
