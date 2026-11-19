<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SDD module E1 — table record_copies. */
class RecordCopy extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'E1';
    }

    protected $table = 'record_copies';

    protected $fillable = [
        'resident_id',
        'requested_by_name',
        'relationship_to_resident',
        'id_presented',
        'purpose',
        'issued_by',
        'issued_at',
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
        ];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
