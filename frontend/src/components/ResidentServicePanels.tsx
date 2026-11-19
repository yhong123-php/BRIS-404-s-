import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import client from '../api/client';
import { useAuth } from '../context/AuthContext';
import { useDialog } from '../context/DialogContext';
import { useToast } from '../context/ToastContext';
import { apiError, formatDate, formatDateTime, EmptyRow, Section, StatusBadge } from './ui';

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
  const dialog = useDialog();
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

  const recordConsent = async (row: ConsentRow, status: 'granted' | 'revoked') => {
    if (status === 'granted') {
      const v = await dialog.form({
        title: 'Record opt-in',
        description: <>The resident consents to: <strong>{row.purpose.name}</strong>{row.purpose.description ? ` — ${row.purpose.description}` : ''}</>,
        fields: [{ name: 'form_path', label: 'Signed consent form', required: true, placeholder: 'Scanned file name or form number', help: 'Record only what the resident ticked on the signed form.' }],
        submitLabel: 'Record opt-in',
      });
      if (v) run(() => client.post(`/admin/residents/${residentId}/consents`, { consent_purpose_id: row.purpose.id, status, form_path: v.form_path }), 'Consent recorded.');
      return;
    }
    const ok = await dialog.confirm({
      title: 'Record opt-out',
      message: <>Record that the resident withdraws consent for <strong>{row.purpose.name}</strong>? This takes effect immediately.</>,
      confirmLabel: 'Record opt-out', tone: 'danger',
    });
    if (ok) run(() => client.post(`/admin/residents/${residentId}/consents`, { consent_purpose_id: row.purpose.id, status, form_path: null }), 'Consent revoked — takes effect immediately.');
  };

  const issueCopy = async () => {
    const v = await dialog.form({
      title: 'Issue a copy of this record',
      description: 'Check the requester’s valid ID. A representative must also present an authorization letter from the resident.',
      fields: [
        { name: 'requested_by_name', label: 'Requested by', required: true, placeholder: 'Full name of the resident or representative' },
        { name: 'relationship_to_resident', label: 'Relationship to the resident', type: 'select', required: true, defaultValue: 'self', options: [
          { value: 'self', label: 'Self (the resident)' }, { value: 'spouse', label: 'Spouse' }, { value: 'parent', label: 'Parent' },
          { value: 'child', label: 'Child' }, { value: 'sibling', label: 'Sibling' }, { value: 'authorized representative', label: 'Authorized representative' },
        ] },
        { name: 'id_presented', label: 'Valid ID presented', required: true, placeholder: 'e.g. PhilSys ID 1234-5678-9012' },
        { name: 'purpose', label: 'Purpose', required: true, placeholder: 'e.g. Bank requirement, school enrollment' },
      ],
      submitLabel: 'Issue and print',
    });
    if (!v) return;
    try {
      const { data } = await client.post(`/admin/residents/${residentId}/record-copies`, v);
      sessionStorage.setItem('bris_record_copy', JSON.stringify(data.data));
      const win = window.open('/record-copy/print', '_blank');
      if (!win) {
        await dialog.notice({ title: 'Copy recorded', message: <>The browser blocked the print window. <a href="/record-copy/print" target="_blank" rel="noreferrer">Open the printable copy</a>.</> });
      }
      load();
    } catch (err) {
      toast.error(apiError(err));
    }
  };

  const receiveDsr = async () => {
    const v = await dialog.form({
      title: 'Receive a data-subject request',
      description: 'A request under the Data Privacy Act (RA 10173). The Barangay Captain decides it on the Data Privacy page.',
      fields: [
        { name: 'request_type', label: 'Type of request', type: 'select', required: true, options: [
          { value: 'access', label: 'Access — a copy of their personal data' },
          { value: 'correction', label: 'Correction — fix wrong or outdated data' },
          { value: 'objection', label: 'Objection — stop optional processing of their data' },
          { value: 'erasure', label: 'Erasure — remove analytics-derived data' },
        ] },
        { name: 'details', label: 'Details, as stated by the resident', type: 'textarea', required: true },
      ],
      submitLabel: 'Receive request',
    });
    if (v) run(() => client.post(`/admin/residents/${residentId}/data-subject-requests`, v), 'Request received and sent to the Captain for decision.');
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
              {copies.map((c) => <tr key={c.id}><td>{formatDateTime(c.issued_at)}</td><td>{c.requested_by_name}</td><td>{c.id_presented}</td><td>{c.purpose}</td><td>{c.issuer?.full_name}</td></tr>)}
              {copies.length === 0 && <EmptyRow colSpan={5} message="No copies issued." />}
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
