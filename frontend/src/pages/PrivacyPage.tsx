import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import client from '../api/client';
import { StatusBadge, apiError, formatDate } from '../components/ui';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';

/**
 * G1 — Consent purposes and G2 — Data-subject requests (SRS §3.2.1.13, §3.5.6;
 * SDD §4.21). The Captain configures consent purposes and decides requests;
 * the Secretary records consent and receives requests on the resident's page.
 */

interface Purpose { id: number; code: string; name: string; description: string | null; is_active: boolean }
interface Dsr {
  id: number; resident_id: number; resident_name: string; request_type: string; details: string; status: string;
  decision_reason: string | null; created_at: string; receiver: { full_name: string } | null; decider: { full_name: string } | null;
}

const TYPE_HELP: Record<string, string> = {
  access: 'Approve, then issue a resident record copy (E1) from the resident’s page and mark the request completed.',
  correction: 'Approve, then the Barangay Secretary updates the resident’s record and marks the request completed.',
  objection: 'Approval removes analytics-derived data (AI suggestions, opportunity matches) and revokes optional consents.',
  erasure: 'Approval removes analytics-derived data and revokes optional consents. Official registry data is retained as a public record and blocked from analytics.',
};

export default function PrivacyPage() {
  const { hasPermission } = useAuth();
  const toast = useToast();
  const [purposes, setPurposes] = useState<Purpose[]>([]);
  const [requests, setRequests] = useState<Dsr[]>([]);
  const [newPurpose, setNewPurpose] = useState({ code: '', name: '', description: '' });
  const canConfigure = hasPermission('g1.configure');
  const canDecide = hasPermission('g2.decide');

  const load = useCallback(() => {
    client.get('/admin/consent-purposes').then(({ data }) => setPurposes(data.data)).catch(() => undefined);
    client.get('/admin/data-subject-requests').then(({ data }) => setRequests(data.data)).catch(() => undefined);
  }, []);
  useEffect(() => { load(); }, [load]);

  const run = async (fn: () => Promise<unknown>, ok: string) => {
    try { await fn(); toast.success(ok); load(); } catch (err) { toast.error(apiError(err)); }
  };

  const addPurpose = (e: FormEvent) => {
    e.preventDefault();
    run(() => client.post('/admin/consent-purposes', newPurpose), 'Consent purpose added.').then(() => setNewPurpose({ code: '', name: '', description: '' }));
  };

  return (
    <div>
      <div className="page-header"><h2>Data Privacy</h2></div>
      <p className="note">Republic Act No. 10173 (Data Privacy Act of 2012). Residents make requests and consent changes in person through the Barangay Secretary.</p>

      <h3>Data-subject requests</h3>
      <table className="data-table">
        <thead><tr><th>Received</th><th>Resident</th><th>Type</th><th>Details</th><th>Status</th><th></th></tr></thead>
        <tbody>
          {requests.map((r) => (
            <tr key={r.id}>
              <td>{formatDate(r.created_at)}<div className="note">by {r.receiver?.full_name ?? '—'}</div></td>
              <td><Link to={`/residents/${r.resident_id}`}>{r.resident_name}</Link></td>
              <td>{r.request_type}<div className="note">{TYPE_HELP[r.request_type]}</div></td>
              <td>{r.details}</td>
              <td><StatusBadge status={r.status} />{r.decider && <div className="note">{r.decider.full_name}{r.decision_reason ? `: ${r.decision_reason}` : ''}</div>}</td>
              <td>
                <div className="row-actions">
                  {canDecide && r.status === 'received' && (
                    <>
                      <button onClick={() => { const reason = window.prompt('Decision note (optional):'); if (reason !== null) run(() => client.post(`/admin/data-subject-requests/${r.id}/decide`, { approve: true, reason }), 'Request approved.'); }}>Approve</button>
                      <button onClick={() => { const reason = window.prompt('Reason for rejecting:'); if (reason) run(() => client.post(`/admin/data-subject-requests/${r.id}/decide`, { approve: false, reason }), 'Request rejected.'); }}>Reject</button>
                    </>
                  )}
                  {canDecide && r.status === 'approved' && <button onClick={() => run(() => client.post(`/admin/data-subject-requests/${r.id}/complete`), 'Marked completed.')}>Mark completed</button>}
                </div>
              </td>
            </tr>
          ))}
          {requests.length === 0 && <tr><td colSpan={6}>No data-subject requests. The Secretary receives them from the resident’s page.</td></tr>}
        </tbody>
      </table>

      <h3 style={{ marginTop: '2rem' }}>Consent purposes</h3>
      <p className="note">Optional, data-driven uses of resident data. Mandatory registration data does not depend on consent. Which processing relies on consent is to be confirmed with the barangay’s Data Protection Officer.</p>
      <table className="data-table">
        <thead><tr><th>Code</th><th>Purpose</th><th>Description</th><th>Active</th></tr></thead>
        <tbody>
          {purposes.map((p) => (
            <tr key={p.id}>
              <td><code>{p.code}</code></td>
              <td>{p.name}</td>
              <td>{p.description}</td>
              <td>
                {canConfigure ? (
                  <input type="checkbox" checked={p.is_active} onChange={(e) => run(() => client.patch(`/admin/consent-purposes/${p.id}`, { is_active: e.target.checked }), 'Updated.')} />
                ) : p.is_active ? 'Yes' : 'No'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {canConfigure && (
        <form className="panel" onSubmit={addPurpose} style={{ marginTop: '1rem' }}>
          <h4>Add a consent purpose</h4>
          <div className="form-grid">
            <label>Code *<input required value={newPurpose.code} onChange={(e) => setNewPurpose({ ...newPurpose, code: e.target.value })} placeholder="e.g. sms_updates" /></label>
            <label>Name *<input required value={newPurpose.name} onChange={(e) => setNewPurpose({ ...newPurpose, name: e.target.value })} /></label>
            <label>Description<input value={newPurpose.description} onChange={(e) => setNewPurpose({ ...newPurpose, description: e.target.value })} /></label>
          </div>
          <div className="form-actions"><button type="submit" className="button-primary">Add purpose</button></div>
        </form>
      )}
    </div>
  );
}
