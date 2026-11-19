<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Writes an append-only audit_logs row for every create/update/delete on the
 * host model, per SDD §5.3. Auditing is done here (a model observer), not in
 * controllers, so no code path that writes through the model can skip it.
 */
trait Auditable
{
    protected static array $auditIgnoredColumns = [
        'created_at',
        'updated_at',
        'active_classification_type_id',
    ];

    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->writeAuditLog('create', null, $model->auditableAttributes($model->getAttributes()));
        });

        static::updated(function ($model) {
            $changes = $model->auditableAttributes($model->getChanges());

            if (empty($changes)) {
                return;
            }

            $old = [];
            foreach (array_keys($changes) as $key) {
                $old[$key] = $model->getOriginal($key);
            }

            $model->writeAuditLog('update', $old, $changes);
        });

        static::deleted(function ($model) {
            $model->writeAuditLog('delete', $model->auditableAttributes($model->getAttributes()), null);
        });
    }

    protected function auditableAttributes(array $attributes): array
    {
        foreach (static::$auditIgnoredColumns as $ignored) {
            unset($attributes[$ignored]);
        }

        return $attributes;
    }

    /**
     * Override in a model to tag its audit rows with a specific SDD module
     * code (e.g. "A4"); defaults to the model's class name.
     */
    public function auditModule(): string
    {
        return class_basename($this);
    }

    protected function writeAuditLog(string $action, ?array $oldValues, ?array $newValues): void
    {
        $request = request();
        $user = auth('sanctum')->user();

        AuditLog::create([
            'user_id' => $user instanceof User ? $user->id : null,
            'action' => $action,
            'module' => $this->auditModule(),
            'table_name' => $this->getTable(),
            'record_id' => $this->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'client_type' => match (true) {
                $user instanceof User => 'staff',
                default => 'system',
            },
        ]);
    }
}
