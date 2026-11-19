<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * D2 — Audit Log (SDD §5.3). Read-only: audit_logs is written exclusively by
 * the Auditable model trait, never by hand, so this controller has no
 * store/update/destroy.
 */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'module' => ['nullable', 'string'],
            'action' => ['nullable', 'string'],
            'table_name' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer'],
            'record_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
        ]);

        $logs = AuditLog::with('user')
            ->when($data['module'] ?? null, fn ($q, $module) => $q->where('module', $module))
            ->when($data['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($data['table_name'] ?? null, fn ($q, $table) => $q->where('table_name', $table))
            ->when($data['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($data['record_id'] ?? null, fn ($q, $id) => $q->where('record_id', $id))
            ->when($data['date_from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($data['date_to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => $logs->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'user' => optional($log->user)->full_name ?? 'System',
                'action' => $log->action,
                'module' => $log->module,
                'table_name' => $log->table_name,
                'record_id' => $log->record_id,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'client_type' => $log->client_type,
                'created_at' => $log->created_at,
            ]),
            'users' => \App\Models\User::orderBy('full_name')->get(['id', 'full_name']),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
