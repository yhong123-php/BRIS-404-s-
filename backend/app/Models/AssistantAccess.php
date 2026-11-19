<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assistant query categories enabled per role (SDD Table 3.3), maintained by
 * the Barangay Captain.
 */
class AssistantAccess extends Model
{
    use Auditable;

    public const CATEGORIES = ['residents', 'social_assistance', 'employment_skills', 'education', 'community_analysis'];

    protected $table = 'assistant_access';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['role_id', 'query_category', 'is_enabled'];

    public function auditModule(): string
    {
        return 'H1';
    }

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Composite key: make save()/update() target the right row. */
    protected function setKeysForSaveQuery($query): Builder
    {
        return $query->where('role_id', $this->getAttribute('role_id'))
            ->where('query_category', $this->getAttribute('query_category'));
    }

    /** @return list<string> */
    public static function enabledCategoriesFor(?int $roleId): array
    {
        if (! $roleId) {
            return [];
        }

        return static::where('role_id', $roleId)->where('is_enabled', true)->pluck('query_category')->all();
    }
}
