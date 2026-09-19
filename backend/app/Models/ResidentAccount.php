<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * Placeholder for the E-series resident portal account (SDD §3.5, resident_accounts).
 * Referenced now only so Auditable's instanceof checks resolve; the migration,
 * relations, and full fillable set are built out when the E-series lands.
 */
class ResidentAccount extends Model
{
    use HasApiTokens;

    protected $fillable = [];
}
