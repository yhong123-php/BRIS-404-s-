import { useEffect, useState } from 'react';
import { Navigate } from 'react-router-dom';
import client from '../api/client';
import { apiError, downloadFile } from '../components/ui';
import { useToast } from '../context/ToastContext';

/**
 * C2 — Report generation (SRS §3.2.1.7, SDD §4.12). One engine for every report:
 * pick a report and parameters, preview, then print (PDF) or export to a
 * spreadsheet (CSV). Every run and export is recorded in the audit log.
 */

interface Catalog {
  reports: { key: string; title: string; parameters: string[] }[];
  puroks: { id: number; name: string }[];
  classification_types: { id: number; name: string }[];
}
interface Report { header: string; title: string; parameters: Record<string, string>; generated_at: string; generated_by: string; columns: string[]; rows: (string | number | null)[][] }

export function ReportGenerator() {
  const toast = useToast();
  const [catalog, setCatalog] = useState<Catalog | null>(null);
  const [key, setKey] = useState('masterlist');
  const [params, setParams] = useState<Record<string, string>>({});
  const [report, setReport] = useState<Report | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => { client.get('/admin/report-engine').then(({ data }) => setCatalog(data.data)); }, []);
  const def = catalog?.reports.find((r) => r.key === key);
  const cleanParams = () => Object.fromEntries(Object.entries(params).filter(([k, v]) => v && def?.parameters.includes(k)));

  const preview = async () => {
    setLoading(true);
    try {
      const { data } = await client.get(`/admin/report-engine/${key}`, { params: cleanParams() });
      setReport(data.data);
    } catch (err) {
      toast.error(apiError(err));
    } finally {
      setLoading(false);
    }
  };

  const exportCsv = () => downloadFile(`/admin/report-engine/${key}`, { ...cleanParams(), format: 'csv' }, `bris-${key}.csv`).catch((err) => toast.error(apiError(err)));

  if (!catalog) return <p>Loading…</p>;
  const has = (p: string) => def?.parameters.includes(p);

  return (
    <div>
      <div className="panel no-print">
        <div className="form-grid">
          <label>
            Report
            <select value={key} onChange={(e) => { setKey(e.target.value); setReport(null); }}>
              {catalog.reports.map((r) => <option key={r.key} value={r.key}>{r.title}</option>)}
            </select>
          </label>
          {has('purok_id') && (
            <label>Purok<select value={params.purok_id ?? ''} onChange={(e) => setParams({ ...params, purok_id: e.target.value })}><option value="">All puroks</option>{catalog.puroks.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
          )}
          {has('status') && (
            <label>Record status<select value={params.status ?? ''} onChange={(e) => setParams({ ...params, status: e.target.value })}><option value="">Active</option><option value="archived">Archived</option><option value="deceased">Deceased</option><option value="transferred_out">Transferred out</option></select></label>
          )}
          {has('classification_type_id') && (
            <label>Sector<select value={params.classification_type_id ?? ''} onChange={(e) => setParams({ ...params, classification_type_id: e.target.value })}><option value="">{key === 'beneficiary_list' ? '4Ps Member (default)' : 'All sectors'}</option>{catalog.classification_types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select></label>
          )}
          {has('date_from') && <label>From<input type="date" value={params.date_from ?? ''} onChange={(e) => setParams({ ...params, date_from: e.target.value })} /></label>}
          {has('date_to') && <label>To<input type="date" value={params.date_to ?? ''} onChange={(e) => setParams({ ...params, date_to: e.target.value })} /></label>}
        </div>
        <div className="row-actions">
          <button className="button-primary" onClick={preview} disabled={loading}>{loading ? 'Generating…' : 'Preview'}</button>
          <button onClick={exportCsv}>Export to spreadsheet (CSV)</button>
          {report && <button onClick={() => window.print()}>Print / save as PDF</button>}
        </div>
        <p className="note">Reports contain personal data; every run and export is recorded in the audit log.</p>
      </div>

      {report && (
        <div className="print-page" style={{ maxWidth: 'none', padding: '1.5rem', marginTop: '1rem' }}>
          <div className="doc-header" style={{ marginBottom: '1rem' }}>
            {report.header.split(' · ').map((l, i, a) => <div key={l} className={i === a.length - 1 ? 'doc-barangay' : ''}>{l}</div>)}
            <h2 className="doc-title" style={{ textDecoration: 'none' }}>{report.title}</h2>
          </div>
          <p className="note" style={{ color: '#333' }}>
            {Object.entries(report.parameters).map(([k, v]) => `${k}: ${v ?? '—'}`).join(' · ')}
            {Object.keys(report.parameters).length ? ' · ' : ''}Generated {report.generated_at} by {report.generated_by} · {report.rows.length} row(s)
          </p>
          <div className="table-scroll">
            <table className="data-table">
              <thead><tr>{report.columns.map((c) => <th key={c}>{c}</th>)}</tr></thead>
              <tbody>
                {report.rows.map((r, i) => <tr key={i}>{r.map((cell, j) => <td key={j}>{cell ?? '—'}</td>)}</tr>)}
                {report.rows.length === 0 && <tr><td colSpan={report.columns.length}>No records for these parameters.</td></tr>}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}

/** The old /report-generator address now opens the merged Reports page. */
export default function ReportGeneratorRedirect() {
  return <Navigate to="/reports?tab=all" replace />;
}
