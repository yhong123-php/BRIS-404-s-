<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Skill extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'A6';
    }

    protected $fillable = [
        'resident_id', 'skill_type_id', 'proficiency_level', 'is_verified',
        'verified_by', 'verified_at', 'document_path', 'source', 'status',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function skillType(): BelongsTo
    {
        return $this->belongsTo(SkillType::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
