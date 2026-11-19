<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SDD module D3 — table backups. */
class Backup extends Model
{
    protected $table = 'backups';

    protected $fillable = [
        'file_name',
        'storage_path',
        'file_size',
        'type',
        'checksum',
        'status',
        'error_message',
        'started_at',
        'finished_at',
        'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
