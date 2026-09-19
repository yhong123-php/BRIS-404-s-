<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentClassification extends Model
{
    use Auditable;

    public function auditModule(): string
    {
        return 'A5';
    }

    protected $fillable = [
        'resident_id', 'classification_type_id', 'reference_number', 'date_granted',
        'expiry_date', 'document_path', 'verified_by', 'status',
    ];

    protected $hidden = ['active_classification_type_id'];

    protected function casts(): array
    {
        return [
            'date_granted' => 'date',
            'expiry_date' => 'date',
        ];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function classificationType(): BelongsTo
    {
        return $this->belongsTo(ClassificationType::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
