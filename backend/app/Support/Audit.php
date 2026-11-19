<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

/**
 * Writes audit entries for events that are not plain model changes — approvals,
 * decisions, report runs, exports, backups, restores
 * (SDD §5.3). Model create/update/delete is already logged by the Auditable trait.
 */
class Audit
{
    public static function log(string $action, string $module, ?string $table = null, ?int $recordId = null, ?array $newValues = null, ?array $oldValues = null): void
    {
        $request = request();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'module' => $module,
            'table_name' => $table ?? 'n/a',
            'record_id' => $recordId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'client_type' => Auth::check() ? 'staff' : 'system',
        ]);
    }
}
