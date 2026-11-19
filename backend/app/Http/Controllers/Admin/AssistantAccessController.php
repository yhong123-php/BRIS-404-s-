<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiQueryLog;
use App\Models\AssistantAccess;
use App\Models\Role;
use App\Services\Assistant\AssistantService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Configure Assistant Access and Review Chatbot Audit Logs (SRS §3.2.1.14,
 * §3.2.3.8–§3.2.3.9; SDD Table 5.2). Captain only (assistant.configure).
 */
class AssistantAccessController extends Controller
{
    public function index()
    {
        $roles = Role::orderBy('name')->get();
        $rows = AssistantAccess::all()->groupBy('role_id');

        return response()->json([
            'data' => [
                'categories' => collect(AssistantAccess::CATEGORIES)->map(fn ($c) => ['key' => $c, 'label' => AssistantService::CATEGORY_LABELS[$c]])->all(),
                'roles' => $roles->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'enabled' => collect(AssistantAccess::CATEGORIES)->mapWithKeys(fn ($c) => [
                        $c => (bool) optional(($rows[$role->id] ?? collect())->firstWhere('query_category', $c))->is_enabled,
                    ])->all(),
                ])->all(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
            'query_category' => ['required', Rule::in(AssistantAccess::CATEGORIES)],
            'is_enabled' => ['required', 'boolean'],
        ]);

        $row = AssistantAccess::firstOrNew(['role_id' => $data['role_id'], 'query_category' => $data['query_category']]);
        $row->is_enabled = $data['is_enabled'];
        $row->save();

        return $this->index();
    }

    public function logs(Request $request)
    {
        $query = AiQueryLog::with('user:id,full_name,username')->latest('id');

        if ($outcome = $request->string('outcome')->toString()) {
            $query->where('outcome', $outcome);
        }
        if ($category = $request->string('category')->toString()) {
            $query->where('query_category', $category);
        }

        $logs = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => collect($logs->items())->map(fn (AiQueryLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at,
                'user' => $log->user?->full_name,
                'question' => $log->question,
                'query_category' => $log->query_category,
                'function_called' => $log->function_called,
                'parameters' => $log->parameters,
                'personal_data_types' => $log->personal_data_types,
                'row_count' => $log->row_count,
                'sent_to_model' => $log->sent_to_model,
                'outcome' => $log->outcome,
            ]),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }
}
