import { useEffect, useState, type ReactNode } from 'react';
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
  rejected: 'bad', revoked: 'bad', cancelled: 'bad', failed: 'bad', closed: 'neutral', unclaimed: 'bad', declined: 'bad',
};

export function StatusBadge({ status }: { status: string }) {
  return <Badge tone={STATUS_TONE[status] ?? 'neutral'}>{status.replace(/_/g, ' ')}</Badge>;
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

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
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
