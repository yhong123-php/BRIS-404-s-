import { DocumentHeader, formatDate, formatDateTime } from '../components/ui';
import { EDUCATION_LEVELS, EMPLOYMENT_STATUS_LABELS, type EmploymentStatus } from '../api/types';

/** E1 — Printable copy of a resident's record (SRS §3.2.1.10, SDD §4.14). */

interface CopyData {
  copy: { id: number; requested_by_name: string; relationship_to_resident: string | null; id_presented: string; purpose: string; issued_at: string; issuer: { full_name: string } | null };
  resident: Record<string, string | number | string[] | null>;
}

export default function RecordCopyPrintPage() {
  let data: CopyData | null = null;
  try {
    data = JSON.parse(sessionStorage.getItem('bris_record_copy') ?? 'null');
  } catch {
    data = null;
  }
  if (!data) return <p style={{ padding: '2rem' }}>Nothing to print. Issue the copy from the resident’s page.</p>;
  const r = data.resident;
  const rows: [string, string][] = [
    ['Resident number', String(r.resident_number ?? '—')],
    ['Full name', String(r.full_name ?? '—')],
    ['Sex', String(r.sex ?? '—')],
    ['Birth date / place', `${formatDate(r.birth_date as string)}${r.birth_place ? ` · ${r.birth_place}` : ''}`],
    ['Civil status', String(r.civil_status ?? '—')],
    ['Citizenship / religion', `${r.citizenship ?? '—'} / ${r.religion ?? '—'}`],
    ['Address', String(r.address || '—')],
    ['Household', r.household_number ? `${r.household_number} (${r.relationship_to_head ?? '—'})` : '—'],
    ['Occupation', String(r.occupation ?? '—')],
    ['Employment status', r.employment_status ? EMPLOYMENT_STATUS_LABELS[r.employment_status as EmploymentStatus] : '—'],
    ['Highest education', r.educational_attainment ? (EDUCATION_LEVELS[r.educational_attainment as string] ?? String(r.educational_attainment)) : '—'],
    ['Contact', [r.contact_number, r.email].filter(Boolean).join(' · ') || '—'],
    ['Voter status', String(r.voter_status ?? '—')],
    ['Resident since', formatDate(r.residency_start_date as string)],
    ['Sector classifications', (r.classifications as string[] | null)?.join(', ') || '—'],
  ];

  return (
    <>
      <div className="print-toolbar">
        <button onClick={() => window.close()}>Close</button>
        <button className="button-primary" onClick={() => window.print()}>Print</button>
      </div>
      <div className="print-page">
        <DocumentHeader title="COPY OF RESIDENT RECORD" />
        <table className="data-table">
          <tbody>{rows.map(([k, v]) => <tr key={k}><th style={{ width: '35%', textAlign: 'left' }}>{k}</th><td style={{ textTransform: 'capitalize' }}>{v}</td></tr>)}</tbody>
        </table>
        <div className="doc-meta">
          <div>Issued {formatDateTime(data.copy.issued_at)} to {data.copy.requested_by_name} ({data.copy.relationship_to_resident || 'self'}) upon presentation of {data.copy.id_presented}, for: {data.copy.purpose}.</div>
          <div>Issued by: {data.copy.issuer?.full_name ?? '—'} · Copy no. {data.copy.id}</div>
          <div>This copy contains personal information protected under Republic Act No. 10173.</div>
        </div>
        <div className="doc-signature"><div><div className="line">BARANGAY SECRETARY</div></div></div>
      </div>
    </>
  );
}
