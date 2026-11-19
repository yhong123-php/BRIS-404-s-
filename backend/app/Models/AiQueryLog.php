<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only log of assistant queries (SRS §3.2.3.9, SDD §5.3): rows can be
 * inserted and read, never updated or deleted from the application.
 */
class AiQueryLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'ai_conversation_id', 'question', 'query_category', 'function_called',
        'parameters', 'personal_data_types', 'row_count', 'sent_to_model', 'outcome',
    ];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Assistant query logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Assistant query logs are append-only.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
