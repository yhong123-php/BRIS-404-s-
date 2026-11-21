import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import client from '../api/client';
import { useAuth } from '../context/AuthContext';
import { SearchIcon } from './icons';

/**
 * Header search across residents, households and puroks. Results appear as you
 * type, grouped by kind; ↑/↓ moves, Enter opens, Esc closes. Enter with nothing
 * highlighted opens the full resident search.
 */

interface Hit { kind: 'Residents' | 'Households' | 'Puroks'; label: string; detail: string; to: string }

export default function GlobalSearch() {
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const [q, setQ] = useState('');
  const [hits, setHits] = useState<Hit[]>([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [active, setActive] = useState(-1);
  const puroks = useRef<{ id: number; name: string; residents_count: number }[] | null>(null);
  const box = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const term = q.trim();
    if (term.length < 2) { setHits([]); setLoading(false); return; }
    setLoading(true);
    const timer = setTimeout(async () => {
      const jobs: Promise<Hit[]>[] = [];
      if (hasPermission('residents.view')) {
        jobs.push(client.get('/admin/residents', { params: { search: term, per_page: 5 } })
          .then(({ data }) => data.data.map((r: { id: number; full_name: string; resident_number: string; purok?: { name: string } | null }) => ({
            kind: 'Residents' as const, label: r.full_name, detail: [r.resident_number, r.purok?.name].filter(Boolean).join(' · '), to: `/residents/${r.id}`,
          }))).catch(() => []));
      }
      if (hasPermission('households.view') || hasPermission('households.manage')) {
        jobs.push(client.get('/admin/households', { params: { search: term, per_page: 5 } })
          .then(({ data }) => data.data.map((h: { id: number; household_number: string; size?: number; house_number: string | null; street: string | null }) => ({
            kind: 'Households' as const, label: h.household_number, detail: [[h.house_number, h.street].filter(Boolean).join(' '), h.size !== undefined ? `${h.size} members` : ''].filter(Boolean).join(' · '), to: `/households/${h.id}`,
          }))).catch(() => []));
      }
      if (hasPermission('puroks.view')) {
        jobs.push((async () => {
          if (!puroks.current) puroks.current = (await client.get('/admin/puroks').catch(() => ({ data: { data: [] } }))).data.data;
          const t = term.toLowerCase();
          return (puroks.current ?? []).filter((p) => p.name.toLowerCase().includes(t))
            .map((p) => ({ kind: 'Puroks' as const, label: p.name, detail: `${p.residents_count} residents`, to: `/puroks/${p.id}` }));
        })());
      }
      const all = (await Promise.all(jobs)).flat();
      setHits(all);
      setActive(-1);
      setLoading(false);
    }, 250);
    return () => clearTimeout(timer);
  }, [q, hasPermission]);

  // Close when clicking anywhere else.
  useEffect(() => {
    const onDown = (e: MouseEvent) => { if (box.current && !box.current.contains(e.target as Node)) setOpen(false); };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, []);

  const go = (to: string) => { navigate(to); setQ(''); setHits([]); setOpen(false); };

  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setOpen(true); setActive((i) => Math.min(i + 1, hits.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(i - 1, -1)); }
    else if (e.key === 'Escape') { setOpen(false); }
    else if (e.key === 'Enter') {
      e.preventDefault();
      if (active >= 0 && hits[active]) go(hits[active].to);
      else if (q.trim()) go(`/residents?search=${encodeURIComponent(q.trim())}`);
    }
  };

  const groups = (['Residents', 'Households', 'Puroks'] as const).map((k) => ({ kind: k, items: hits.map((h, i) => ({ h, i })).filter(({ h }) => h.kind === k) })).filter((g) => g.items.length);
  const showPanel = open && q.trim().length >= 2;

  return (
    <div ref={box} className="global-search relative flex-1 max-w-md">
      <SearchIcon className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" width={18} height={18} />
      <input
        value={q}
        onChange={(e) => { setQ(e.target.value); setOpen(true); }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKey}
        placeholder="Search residents, households, puroks…"
        aria-label="Search residents, households and puroks"
        aria-expanded={showPanel}
        role="combobox"
        className="w-full rounded-lg border border-gray-200 bg-white py-2 pl-9 pr-3 text-sm text-charcoal outline-none focus:border-primary focus:ring-1 focus:ring-primary dark:border-white/10 dark:bg-charcoal dark:text-gray-100"
      />
      {showPanel && (
        <div className="search-panel" role="listbox">
          {loading && hits.length === 0 && <div className="search-empty">Searching…</div>}
          {!loading && hits.length === 0 && <div className="search-empty">No matches for “{q.trim()}”.</div>}
          {groups.map((g) => (
            <div key={g.kind}>
              <div className="search-group">{g.kind}</div>
              {g.items.map(({ h, i }) => (
                <button key={h.to} type="button" role="option" aria-selected={i === active} className={`search-hit${i === active ? ' active' : ''}`} onMouseEnter={() => setActive(i)} onClick={() => go(h.to)}>
                  <span className="search-hit-label">{h.label}</span>
                  {h.detail && <span className="search-hit-detail">{h.detail}</span>}
                </button>
              ))}
            </div>
          ))}
          {q.trim() && hasPermission('residents.view') && (
            <button type="button" className="search-hit search-all" onClick={() => go(`/residents?search=${encodeURIComponent(q.trim())}`)}>
              See all residents matching “{q.trim()}” →
            </button>
          )}
        </div>
      )}
    </div>
  );
}
