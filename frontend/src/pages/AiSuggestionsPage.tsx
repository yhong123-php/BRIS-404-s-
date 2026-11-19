import { useCallback, useEffect, useState } from 'react';
import { Link, Navigate } from 'react-router-dom';
import client from '../api/client';
import { EMPLOYMENT_STATUS_LABELS, type EmploymentStatus } from '../api/types';
import { StatusBadge, apiError, formatDate } from '../components/ui';
import { useAuth } from '../context/AuthContext';
import { useDialog } from '../context/DialogContext';
import { useToast } from '../context/ToastContext';

/**
 * F1 — AI Resident Analysis Engine (SRS §3.2.1.13, SDD §4.17). The engine only
 * writes suggestions; the Secretary confirms or rejects each one, and only
 * confirmed suggestions change the registry. Optional analysis runs only for
 * residents who consented (G1).
 */

interface Suggestion {
  id: number; resident_id: number; resident_name: string | null; purok: string | null; suggestion_type: string;
  program_name: string | null; payload: Record<string, string | number>; rationale: string; engine_version: string;
  status: string; reviewed_at: string | null; reviewer: { full_name: string } | null; created_at: string;
}

const TYPE_LABELS: Record<string, string> = {
  eligibility: 'Program eligibility',
  employment_status: 'Employment status',
  skill_category: 'Skill category',
};

function describe(s: Suggestion): string {
  if (s.suggestion_type === 'employment_status') return `Set employment status to “${EMPLOYMENT_STATUS_LABELS[s.payload.employment_status as EmploymentStatus] ?? s.payload.employment_status}”`;
  if (s.suggestion_type === 'skill_category') return `Add skill “${s.payload.skill}” (unverified until checked)`;
  if (s.suggestion_type === 'eligibility') return `Eligible for classification “${s.payload.classification}”`;
  return JSON.stringify(s.payload);
}

export function AiResidentAnalysis() {
  const { hasPermission } = useAuth();
  const toast = useToast();
  const dialog = useDialog();
  const [rows, setRows] = useState<Suggestion[]>([]);
  const [status, setStatus] = useState('pending');
  const [running, setRunning] = useState(false);
  const canConfirm = hasPermission('f1.confirm');

  const load = useCallback(() => client.get('/admin/ai-suggestions', { params: { status } }).then(({ data }) => setRows(data.data)), [status]);
  useEffect(() => { load(); }, [load]);

  const run = async () => {
    setRunning(true);
    try {
      const { data } = await client.post('/admin/ai-suggestions/run');
      const n = Object.values(data.data as Record<string, number>).reduce((a, b) => a + b, 0);
      toast.success(`Analysis complete: ${n} new suggestion(s).`);
      load();
    } catch (err) {
      toast.error(apiError(err));
    } finally {
      setRunning(false);
    }
  };

  const decide = async (s: Suggestion, confirm: boolean) => {
    const extra: Record<string, string> = {};
    if (confirm && s.suggestion_type === 'eligibility') {
      const v = await dialog.form({
        title: `Confirm: ${s.payload.classification}`,
        description: <>{s.resident_name} — {s.rationale}</>,
        fields: [
          { name: 'reference_number', label: 'Reference number', required: true, placeholder: 'e.g. OSCA ID number' },
          { name: 'document_path', label: 'Supporting document', required: true, placeholder: 'Scanned file name or document reference' },
        ],
        submitLabel: 'Confirm and record',
      });
      if (!v) return;
      extra.reference_number = v.reference_number;
      extra.document_path = v.document_path;
    }
    try {
      await client.post(`/admin/ai-suggestions/${s.id}/decide`, { confirm, ...extra });
      toast.success(confirm ? 'Confirmed — the record was updated.' : 'Suggestion rejected.');
      load();
    } catch (err) {
      toast.error(apiError(err));
    }
  };

  return (
    <div>
      <div className="page-header">
        <h2>AI Resident Analysis</h2>
        {canConfirm && <button className="button-primary" onClick={run} disabled={running}>{running ? 'Analyzing…' : 'Run analysis now'}</button>}
      </div>
      <div className="callout">
        Suggestions only — nothing changes in the registry until the Barangay Secretary confirms. Employment-status and skill suggestions are made only for
        residents who consented to optional analytics; the eligibility check is mandatory. The engine never approves or rejects 4Ps or other assistance applicants.
        It also runs automatically every night.
      </div>
      <div className="tabs">
        {['pending', 'confirmed', 'rejected'].map((s) => <button key={s} className={status === s ? 'active' : ''} onClick={() => setStatus(s)}>{s}</button>)}
      </div>
      <table className="data-table">
        <thead><tr><th>Resident</th><th>Type</th><th>Suggestion</th><th>Why</th><th>Status</th><th></th></tr></thead>
        <tbody>
          {rows.map((s) => (
            <tr key={s.id}>
              <td><Link to={`/residents/${s.resident_id}`}>{s.resident_name}</Link><div className="note">{s.purok ?? ''}</div></td>
              <td>{TYPE_LABELS[s.suggestion_type] ?? s.suggestion_type}</td>
              <td>{describe(s)}</td>
              <td>{s.rationale}<div className="note">{s.engine_version} · {formatDate(s.created_at)}</div></td>
              <td><StatusBadge status={s.status} />{s.reviewer && <div className="note">{s.reviewer.full_name}</div>}</td>
              <td>{canConfirm && s.status === 'pending' && <div className="row-actions"><button onClick={() => decide(s, true)}>Confirm</button><button onClick={() => decide(s, false)}>Reject</button></div>}</td>
            </tr>
          ))}
          {rows.length === 0 && <tr><td colSpan={6}>No {status} suggestions.</td></tr>}
        </tbody>
      </table>
    </div>
  );
}

/** The old /ai-suggestions address now opens the AI Resident Analysis tab of Reports. */
export default function AiSuggestionsRedirect() {
  return <Navigate to="/reports?tab=ai" replace />;
}
