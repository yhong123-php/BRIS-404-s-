<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Achievement extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'A6';
    }

    protected $fillable = [
        'resident_id', 'title', 'issuing_body', 'date_achieved', 'category', 'attachment_path', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['date_achieved' => 'date'];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
