<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiSuggestion;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\DataSubjectRequest;
use App\Models\PopulationEvent;
use App\Models\ReliefBeneficiary;
use App\Models\ReliefDistribution;
use App\Models\Household;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentClassification;
use App\Models\SkillType;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * C1 — the dashboard SDD Table 9.0 grants every role dashboard.view for.
 * Only counts each viewer can already see individually elsewhere (residents,
 * households, puroks) are shown unconditionally; pending approvals and the
 * activity feed additionally require the permission their own screens do
 * (users.manage, audit.view) so this endpoint never leaks more than the
 * screens it summarizes.
 */
class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $user = $request->user();

        $data = [
            'residents_total' => Resident::where('status', 'active')->count(),
            'residents_by_sex' => Resident::where('status', 'active')
                ->selectRaw('sex, count(*) as count')
                ->groupBy('sex')
                ->pluck('count', 'sex'),
            'households_total' => Household::count(),
            'puroks_total' => Purok::count(),
        ];

        // Same underlying queries as ReportsController — kept as one-line
        // summaries here rather than duplicated logic; the full breakdowns
        // live on the Reports page these link to.
        if ($user->hasPermission('a6.view')) {
            $topSkill = SkillType::withCount(['skills' => fn ($q) => $q->where('status', 'active')])
                ->having('skills_count', '>', 0)
                ->orderByDesc('skills_count')
                ->first();

            $data['top_skill'] = $topSkill ? ['name' => $topSkill->name, 'count' => $topSkill->skills_count] : null;
        }

        if ($user->hasPermission('reports.generate.all')) {
            $data['unemployed_count'] = Resident::where('status', 'active')->where('employment_status', 'unemployed')->count();
        }

        // SDD §4.11 action list: items waiting on someone, each linking to its screen.
        $actions = [];
        $add = function (string $label, int $count, string $link) use (&$actions) {
            if ($count > 0) {
                $actions[] = ['label' => $label, 'count' => $count, 'link' => $link];
            }
        };
        if ($user->hasPermission('f3.approve')) {
            $add('Relief allocation plans awaiting your approval', ReliefDistribution::where('status', 'pending_approval')->count(), '/relief');
        }
        if ($user->hasPermission('g2.decide')) {
            $add('Data-subject requests awaiting your decision', DataSubjectRequest::where('status', 'received')->count(), '/data-requests');
        }
        if ($user->hasPermission('f1.confirm')) {
            $add('AI suggestions waiting for your review', AiSuggestion::where('status', 'pending')->count(), '/reports?tab=ai');
        }
        if ($user->hasPermission('classifications.view')) {
            $add('Classifications expiring within 30 days', ResidentClassification::where('status', 'active')->whereNotNull('expiry_date')
                ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(30)->toDateString()])->count(), '/reports');
        }
        if ($user->hasPermission('relief.view')) {
            $add('Unclaimed relief in open distributions', ReliefBeneficiary::where('status', 'listed')->where('is_excluded', false)->where('within_allocation', true)
                ->whereHas('distribution', fn ($q) => $q->where('status', 'open'))->count(), '/relief');
        }
        if ($user->hasPermission('backup.restore')) {
            $lastSuccess = Backup::where('status', 'success')->max('started_at');
            $add('Failed backups since the last successful one', Backup::where('status', 'failed')->when($lastSuccess, fn ($q) => $q->where('started_at', '>', $lastSuccess))->count(), '/backups');
            if (! $lastSuccess || now()->diffInHours($lastSuccess) > 36) {
                $actions[] = ['label' => 'No successful backup in the last 36 hours', 'count' => 1, 'link' => '/backups'];
            }
        }
        $data['action_list'] = $actions;

        if ($user->hasPermission('population_events.view')) {
            $data['events_this_month'] = PopulationEvent::whereBetween('event_date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
                ->selectRaw('event_type, count(*) as c')->groupBy('event_type')->pluck('c', 'event_type');
        }
        if ($user->hasPermission('relief.view')) {
            $data['active_relief'] = ReliefDistribution::where('status', 'open')->get()->map(fn ($d) => [
                'id' => $d->id, 'title' => $d->title,
                'allocated' => $d->beneficiaries()->where('is_excluded', false)->where('within_allocation', true)->count(),
                'claimed' => $d->beneficiaries()->where('status', 'claimed')->count(),
            ]);
        }

        if ($user->hasPermission('audit.view')) {
            $data['recent_activity'] = AuditLog::with('user')
                ->latest()
                ->take(10)
                ->get()
                ->map(fn (AuditLog $log) => [
                    'action' => $log->action,
                    'module' => $log->module,
                    'table_name' => $log->table_name,
                    'record_id' => $log->record_id,
                    'user' => $log->user?->full_name ?? 'System',
                    'created_at' => $log->created_at,
                ]);
        }

        return response()->json(['data' => $data]);
    }
}
