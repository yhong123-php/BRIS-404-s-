import { useEffect, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import client from '../api/client';

/** Shared building blocks for the module pages (B1–G2). */

export interface ResidentOption {
  id: number;
  full_name: string;
  resident_number: string;
  status: string;
  purok?: { id: number; name: string } | null;
}

/** Turns a Laravel validation/abort response into one readable message. */
export function apiError(err: unknown, fallback = 'Something went wrong.'): string {
  const data = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
  if (data?.errors) return Object.values(data.errors).flat().join(' ');
  return data?.message ?? fallback;
}

/** Search-as-you-type resident picker (active residents by default). */
export function ResidentPicker({
  value,
  onChange,
  status = 'active',
  placeholder = 'Search resident by name or number…',
}: {
  value: ResidentOption | null;
  onChange: (r: ResidentOption | null) => void;
  status?: string | null;
  placeholder?: string;
}) {
  const [search, setSearch] = useState('');
  const [results, setResults] = useState<ResidentOption[]>([]);

  useEffect(() => {
    if (search.trim().length < 2) {
      setResults([]);
      return;
    }
    const t = setTimeout(() => {
      client
        .get('/admin/residents', { params: { search, per_page: 8, ...(status ? { status } : {}) } })
        .then(({ data }) => setResults(data.data))
        .catch(() => setResults([]));
    }, 250);
    return () => clearTimeout(t);
  }, [search, status]);

  if (value) {
    return (
      <div className="picked">
        <strong>{value.full_name}</strong> <span className="muted">({value.resident_number}{value.purok ? ` · ${value.purok.name}` : ''})</span>{' '}
        <button type="button" onClick={() => onChange(null)}>
          Change
        </button>
      </div>
    );
  }

  return (
    <div>
      <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={placeholder} style={{ width: '100%' }} />
      {results.length > 0 && (
        <ul className="search-results">
          {results.map((r) => (
            <li key={r.id}>
              <span>
                {r.full_name} <span className="muted">({r.resident_number}{r.purok ? ` · ${r.purok.name}` : ''})</span>
              </span>
              <button
                type="button"
                onClick={() => {
                  onChange(r);
                  setSearch('');
                  setResults([]);
                }}
              >
                Select
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function Badge({ children, tone = 'neutral' }: { children: ReactNode; tone?: 'neutral' | 'good' | 'warn' | 'bad' | 'info' }) {
  return <span className={`badge badge-${tone}`}>{children}</span>;
}

const STATUS_TONE: Record<string, 'neutral' | 'good' | 'warn' | 'bad' | 'info'> = {
  active: 'good', granted: 'good', approved: 'good', confirmed: 'good', released: 'good', claimed: 'good', success: 'good', completed: 'good', open: 'good', hired: 'good',
  pending: 'warn', submitted: 'warn', received: 'warn', pending_approval: 'warn', ready_for_pickup: 'info', draft: 'neutral', listed: 'neutral', shortlisted: 'info',
  head: 'info', verified: 'good', unverified: 'warn', archived: 'neutral', deceased: 'neutral', transferred_out: 'neutral', deactivated: 'bad',
  rejected: 'bad', revoked: 'bad', cancelled: 'bad', failed: 'bad', closed: 'neutral', unclaimed: 'bad', declined: 'bad',
};

export function StatusBadge({ status }: { status: string }) {
  return <Badge tone={STATUS_TONE[status] ?? 'neutral'}>{humanize(status)}</Badge>;
}

/** Loading placeholders (shimmering grey blocks) shown while data is fetched. */
export function SkeletonLines({ lines = 5 }: { lines?: number }) {
  return (
    <div aria-busy="true" aria-label="Loading">
      {Array.from({ length: lines }, (_, i) => <div key={i} className="skeleton skeleton-line" style={{ width: `${90 - ((i * 17) % 35)}%` }} />)}
    </div>
  );
}

export function PageSkeleton({ cards = 3 }: { cards?: number }) {
  return (
    <div aria-busy="true" aria-label="Loading">
      <div className="skeleton skeleton-title" />
      <div className="stat-grid" style={{ display: 'grid', gridTemplateColumns: `repeat(auto-fit, minmax(160px, 1fr))`, gap: '1rem', marginBottom: '1.2rem' }}>
        {Array.from({ length: cards }, (_, i) => <div key={i} className="skeleton skeleton-card" />)}
      </div>
      <SkeletonLines lines={6} />
    </div>
  );
}

export interface BarDatum { label: string; value: number; second?: number }

/**
 * Horizontal bar chart. One series by default; pass `seriesNames` with two names
 * and `second` values for a stacked two-part bar (e.g. male / female).
 * Hovering a row shows its exact numbers; a table view sits underneath.
 */
export function BarChart({ data, seriesNames, unit = '' }: { data: BarDatum[]; seriesNames?: [string, string]; unit?: string }) {
  const totals = data.map((d) => d.value + (d.second ?? 0));
  const max = Math.max(1, ...totals);
  return (
    <div>
      {seriesNames && <div className="chart-legend"><span>{seriesNames[0]}</span><span className="alt">{seriesNames[1]}</span></div>}
      <div className="bar-chart" role="img" aria-label={data.map((d, i) => `${d.label}: ${totals[i]}`).join(', ')}>
        {data.map((d, i) => {
          const tip = seriesNames ? `${d.label} — ${seriesNames[0]} ${d.value}, ${seriesNames[1]} ${d.second ?? 0}, total ${totals[i]}${unit}` : `${d.label}: ${d.value}${unit}`;
          return (
            <div key={d.label} className="bar-row" title={tip}>
              <span className="bar-label">{d.label}</span>
              <div className="bar-track">
                {d.value > 0 && <div className="bar-fill" style={{ width: `${(d.value / max) * 100}%` }} />}
                {seriesNames && (d.second ?? 0) > 0 && <div className="bar-fill alt" style={{ width: `${((d.second ?? 0) / max) * 100}%` }} />}
              </div>
              <span className="bar-value">{totals[i]}</span>
            </div>
          );
        })}
      </div>
      <details className="chart-table">
        <summary>Show as table</summary>
        <table className="data-table">
          <thead><tr><th></th>{seriesNames ? <><th>{seriesNames[0]}</th><th>{seriesNames[1]}</th><th>Total</th></> : <th>Count</th>}</tr></thead>
          <tbody>{data.map((d, i) => <tr key={d.label}><td>{d.label}</td>{seriesNames ? <><td>{d.value}</td><td>{d.second ?? 0}</td><td>{totals[i]}</td></> : <td>{d.value}</td>}</tr>)}</tbody>
        </table>
      </details>
    </div>
  );
}

/** "Residents & Households › HH-5-03" trail shown above a detail page's title. */
export function Breadcrumb({ items }: { items: { label: string; to?: string }[] }) {
  return (
    <nav className="breadcrumb no-print" aria-label="Breadcrumb">
      {items.map((it, i) => (
        <span key={i}>
          {i > 0 && <span className="breadcrumb-sep" aria-hidden="true">›</span>}
          {it.to ? <Link to={it.to}>{it.label}</Link> : <span aria-current="page">{it.label}</span>}
        </span>
      ))}
    </nav>
  );
}

/** An empty table row that says what to do next, with the action right there. */
export function EmptyRow({ colSpan, message, action }: { colSpan: number; message: string; action?: ReactNode }) {
  return (
    <tr className="empty-row">
      <td colSpan={colSpan}>
        <div className="empty-state"><span>{message}</span>{action}</div>
      </td>
    </tr>
  );
}

export function Section({ title, children, actions }: { title: string; children: ReactNode; actions?: ReactNode }) {
  return (
    <section className="detail-section">
      <div className="section-head">
        <h3>{title}</h3>
        {actions}
      </div>
      {children}
    </section>
  );
}

export const BARANGAY_HEADER = ['Republic of the Philippines', 'Province of Bohol', 'Municipality of Pilar', 'BARANGAY SAN ISIDRO'];

export function DocumentHeader({ title }: { title: string }) {
  return (
    <div className="doc-header">
      {BARANGAY_HEADER.map((line, i) => (
        <div key={line} className={i === BARANGAY_HEADER.length - 1 ? 'doc-barangay' : ''}>
          {line}
        </div>
      ))}
      <div className="doc-office">OFFICE OF THE PUNONG BARANGAY</div>
      <h2 className="doc-title">{title}</h2>
    </div>
  );
}

/** One date style everywhere: "Nov 19, 2026". Accepts dates or full timestamps. */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  const d = new Date(/^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T00:00:00` : value);
  return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}

/** Date and time: "Nov 19, 2026, 10:11 PM". */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? value : d.toLocaleString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

const SPECIAL_LABELS: Record<string, string> = {
  self_employed: 'Self-employed', not_in_labor_force: 'Not in labor force', transferred_out: 'Transferred out', transfer_in: 'Transfer in',
  transfer_out: 'Transfer out', not_registered: 'Not registered', registered: 'Registered voter', semi_concrete: 'Semi-concrete',
  light_materials: 'Light materials', informal_settler: 'Informal settler', pending_approval: 'Pending approval', ready_for_pickup: 'Ready for pickup',
  per_purok: 'Fair share per purok', no_data: 'No data', '4ps': '4Ps',
};

/** Turns stored codes into words: "self_employed" → "Self-employed", "male" → "Male". */
export function humanize(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  if (SPECIAL_LABELS[value]) return SPECIAL_LABELS[value];
  const words = value.replace(/_/g, ' ').trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
}

export function peso(value: string | number | null | undefined): string {
  return `₱${Number(value ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;
}

/** Downloads a file from an authenticated API endpoint (the token stays in the request header). */
export async function downloadFile(url: string, params: Record<string, unknown>, fallbackName: string) {
  const res = await client.get(url, { params, responseType: 'blob' });
  const disposition = String(res.headers['content-disposition'] ?? '');
  const name = /filename="?([^"]+)"?/.exec(disposition)?.[1] ?? fallbackName;
  const href = URL.createObjectURL(res.data as Blob);
  const a = document.createElement('a');
  a.href = href;
  a.download = name;
  a.click();
  URL.revokeObjectURL(href);
}
