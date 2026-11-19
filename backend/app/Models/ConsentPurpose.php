<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** SDD module G1 — table consent_purposes. */
class ConsentPurpose extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'G1';
    }

    protected $table = 'consent_purposes';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_optional',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_optional' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
