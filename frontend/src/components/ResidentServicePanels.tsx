import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import client from '../api/client';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { Section, StatusBadge, apiError, formatDate } from './ui';

/**
 * Resident-page panels for the services residents request in person through
 * the Barangay Secretary: consent (G1), record copies (E1), and data-subject
 * requests (G2).
 */

interface ConsentRow {
  purpose: { id: number; code: string; name: string; description: string | null };
  consent: null | { status: 'granted' | 'revoked'; form_path: string | null; recorded_at: string; revoked_at: string | null; recorder: { full_name: string } | null };
}

export default function ResidentServicePanels({ residentId }: { residentId: number }) {
  const { hasPermission } = useAuth();
  const toast = useToast();
  const [consents, setConsents] = useState<ConsentRow[]>([]);
  const [copies, setCopies] = useState<{ id: number; requested_by_name: string; purpose: string; id_presented: string; issued_at: string; issuer: { full_name: string } | null }[]>([]);

  const canConsent = hasPermission('g1.record') || hasPermission('g1.configure');
  const load = useCallback(() => {
    if (canConsent) client.get(`/admin/residents/${residentId}/consents`).then(({ data }) => setConsents(data.data)).catch(() => undefined);
    if (hasPermission('record_copies.issue')) client.get(`/admin/residents/${residentId}/record-copies`).then(({ data }) => setCopies(data.data)).catch(() => undefined);
  }, [residentId, hasPermission, canConsent]);
  useEffect(() => { load(); }, [load]);

  const run = async (fn: () => Promise<unknown>, ok: string) => {
    try { await fn(); toast.success(ok); load(); } catch (err) { toast.error(apiError(err)); }
  };

  const recordConsent = (row: ConsentRow, status: 'granted' | 'revoked') => {
    const form_path = status === 'granted' ? window.prompt('Signed consent form (scanned file name or reference):') : null;
    if (status === 'granted' && !form_path) return;
    run(() => client.post(`/admin/residents/${residentId}/consents`, { consent_purpose_id: row.purpose.id, status, form_path }), status === 'granted' ? 'Consent recorded.' : 'Consent revoked — takes effect immediately.');
  };

  const issueCopy = async () => {
    const requested_by_name = window.prompt('Name of the person requesting the copy (resident or authorized representative):');
    if (!requested_by_name) return;
    const relationship_to_resident = window.prompt('Relationship to the resident (e.g. self, spouse, authorized representative):', 'self') ?? '';
    const id_presented = window.prompt('Valid ID presented (type and number). A representative must also present an authorization letter:');
    if (!id_presented) return;
    const purpose = window.prompt('Purpose of the request:');
    if (!purpose) return;
    try {
      const { data } = await client.post(`/admin/residents/${residentId}/record-copies`, { requested_by_name, relationship_to_resident, id_presented, purpose });
      sessionStorage.setItem('bris_record_copy', JSON.stringify(data.data));
      window.open('/record-copy/print', '_blank');
      load();
    } catch (err) {
      toast.error(apiError(err));
    }
  };

  const receiveDsr = () => {
    const request_type = window.prompt('Type of request: access, correction, objection, or erasure')?.trim().toLowerCase();
    if (!request_type) return;
    if (!['access', 'correction', 'objection', 'erasure'].includes(request_type)) return toast.error('Type must be access, correction, objection, or erasure.');
    const details = window.prompt('Details of the request, as stated by the resident:');
    if (!details) return;
    run(() => client.post(`/admin/residents/${residentId}/data-subject-requests`, { request_type, details }), 'Request received and sent to the Captain for decision.');
  };

  return (
    <>
      {canConsent && (
        <Section title="Consent (Data Privacy Act)">
          <p className="note">Recorded from the resident’s signed consent form. Mandatory registration data does not depend on consent. A revocation takes effect immediately.</p>
          <table className="data-table">
            <thead><tr><th>Purpose</th><th>Status</th><th>Form</th><th></th></tr></thead>
            <tbody>
              {consents.map((c) => (
                <tr key={c.purpose.id}>
                  <td>{c.purpose.name}<div className="note">{c.purpose.description}</div></td>
                  <td>{c.consent ? <StatusBadge status={c.consent.status} /> : <span className="muted">not recorded (treated as no consent)</span>}{c.consent && <div className="note">{formatDate(c.consent.revoked_at ?? c.consent.recorded_at)} · {c.consent.recorder?.full_name}</div>}</td>
                  <td>{c.consent?.form_path ?? '—'}</td>
                  <td>
                    {hasPermission('g1.record') && (
                      <div className="row-actions">
                        {c.consent?.status !== 'granted' && <button onClick={() => recordConsent(c, 'granted')}>Record opt-in</button>}
                        {c.consent?.status === 'granted' && <button onClick={() => recordConsent(c, 'revoked')}>Record opt-out</button>}
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Section>
      )}

      {hasPermission('record_copies.issue') && (
        <Section title="Copies of this record" actions={<button onClick={issueCopy}>Issue record copy</button>}>
          <p className="note">The requester is identified against a valid ID; a representative also presents an authorization letter. Every copy is logged.</p>
          <table className="data-table">
            <thead><tr><th>Issued</th><th>Requested by</th><th>ID presented</th><th>Purpose</th><th>Issued by</th></tr></thead>
            <tbody>
              {copies.map((c) => <tr key={c.id}><td>{new Date(c.issued_at).toLocaleString()}</td><td>{c.requested_by_name}</td><td>{c.id_presented}</td><td>{c.purpose}</td><td>{c.issuer?.full_name}</td></tr>)}
              {copies.length === 0 && <tr><td colSpan={5}>No copies issued.</td></tr>}
            </tbody>
          </table>
        </Section>
      )}

      {hasPermission('g2.receive') && (
        <Section title="Data-subject requests" actions={<button onClick={receiveDsr}>Receive request</button>}>
          <p className="note">Requests to access, correct, object to, or erase personal data (RA 10173). The Barangay Captain decides them on the <Link to="/privacy">Data Privacy</Link> page.</p>
        </Section>
      )}
    </>
  );
}
