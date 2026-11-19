<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SDD Table 3.4 — deleted after the retention period (SPMP §5.5.5). */
class AiConversation extends Model
{
    protected $fillable = ['user_id', 'started_at', 'last_message_at', 'delete_after'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_message_at' => 'datetime',
            'delete_after' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }
}
