<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ai_conversation_id', 'sender', 'content', 'records', 'function_called'];

    protected function casts(): array
    {
        return ['records' => 'array', 'created_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}
