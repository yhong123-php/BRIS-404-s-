import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import client from '../api/client';
import { ArchiveIcon, EditIcon, SearchIcon } from '../components/icons';
import type { PaginatedResponse, Resident } from '../api/types';
import { EmptyRow, humanize, StatusBadge } from '../components/ui';
import { useAuth } from '../context/AuthContext';
import { useDialog } from '../context/DialogContext';
import { useToast } from '../context/ToastContext';

type SortKey = 'resident_number' | 'name' | 'age' | 'purok' | 'status';

const STATUSES = ['active', 'deceased', 'transferred_out', 'archived'];

/** Table columns (tablets and up; phones get a card list instead). */
const COLUMNS: { key: SortKey | 'sex'; label: string; sortable: boolean }[] = [
  { key: 'resident_number', label: 'Resident #', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'sex', label: 'Sex', sortable: false },
  { key: 'age', label: 'Age', sortable: true },
  { key: 'purok', label: 'Purok', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
];

/** Filters, sort and page live in the URL, so Back from a resident returns to the same view. */
const FILTER_KEYS = ['search', 'purok_id', 'status', 'sex', 'sort', 'dir', 'page'] as const;

export default function ResidentsListPage() {
  const [params, setParams] = useSearchParams();
  const urlSearch = params.get('search') ?? '';
  const purokId = params.get('purok_id') ?? '';
  const status = params.get('status') ?? '';
  const sex = params.get('sex') ?? '';
  const sort = (params.get('sort') as SortKey | null) ?? 'name';
  const dir = params.get('dir') === 'desc' ? 'desc' : 'asc';
  const page = Math.max(1, Number(params.get('page')) || 1);

  const [search, setSearch] = useState(urlSearch);
  const [residents, setResidents] = useState<Resident[]>([]);
  const [puroks, setPuroks] = useState<{ id: number; name: string }[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [reloadKey, setReloadKey] = useState(0);
  const { hasPermission } = useAuth();
  const toast = useToast();
  const dialog = useDialog();

  /** Writes changes into the URL; any change other than the page itself goes back to page 1. */
  // Builds on the live browser URL, not this render's copy: the delayed search
  // update can fire after another change (e.g. Clear filters) and must not undo
  // it. (React Router's function form of setParams also sees the render's copy.)
  const update = (changes: Partial<Record<(typeof FILTER_KEYS)[number], string>>, replace = false) => {
    const next = new URLSearchParams(window.location.search);
    for (const [k, v] of Object.entries(changes)) {
      if (v) next.set(k, v);
      else next.delete(k);
    }
    if (!('page' in changes)) next.delete('page');
    setParams(next, { replace });
  };

  // The topbar search can change ?search= while this page is open; keep the box in step.
  // (Only when it really differs, so a trailing space mid-typing isn't stripped.)
  useEffect(() => {
    setSearch((current) => (current.trim() === urlSearch ? current : urlSearch));
  }, [urlSearch]);

  // Search as you type, like the topbar search (replace, so typing doesn't fill the Back history).
  useEffect(() => {
    if (search.trim() === urlSearch) return;
    const t = setTimeout(() => update({ search: search.trim() }, true), 300);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

  useEffect(() => {
    client.get('/admin/puroks').then(({ data }) => setPuroks(data.data)).catch(() => setPuroks([]));
  }, []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setLoadError(null);
    client
      .get<PaginatedResponse<Resident>>('/admin/residents', {
        params: { search: urlSearch || undefined, purok_id: purokId || undefined, status: status || undefined, sex: sex || undefined, sort, dir, page },
      })
      .then(({ data }) => {
        if (cancelled) return;
        setResidents(data.data);
        setLastPage(data.meta?.last_page ?? 1);
        setTotal(data.meta?.total ?? data.data.length);
      })
      .catch((err) => !cancelled && setLoadError(err?.response?.data?.message ?? 'Could not load residents.'))
      .finally(() => !cancelled && setLoading(false));
    return () => {
      cancelled = true;
    };
  }, [urlSearch, purokId, status, sex, sort, dir, page, reloadKey]);

  const archive = async (resident: Resident) => {
    const ok = await dialog.confirm({
      title: 'Archive resident',
      message: <>Archive <strong>{resident.full_name}</strong>? The record is kept but no longer active. Only the Barangay Captain can restore it.</>,
      confirmLabel: 'Archive', tone: 'danger',
    });
    if (!ok) return;
    try {
      await client.post(`/admin/residents/${resident.id}/archive`);
      toast.success(`${resident.full_name} archived successfully.`);
      setReloadKey((k) => k + 1);
    } catch (err: any) {
      toast.error(err?.response?.data?.message ?? 'Could not archive resident.');
    }
  };

  const toggleSort = (key: SortKey) => {
    update(sort === key ? { sort: key, dir: dir === 'asc' ? 'desc' : 'asc' } : { sort: key === 'name' ? '' : key, dir: '' });
  };

  const filtersActive = Boolean(urlSearch || purokId || status || sex);
  const clearFilters = () => {
    setSearch('');
    update({ search: '', purok_id: '', status: '', sex: '' });
  };

  const pageNumbers = Array.from({ length: lastPage }, (_, i) => i + 1).filter(
    (n) => n === 1 || n === lastPage || Math.abs(n - page) <= 1
  );

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="page-title">Residents</h2>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400" aria-live="polite">
            {loading ? 'Loading…' : `${total} ${total === 1 ? 'resident' : 'residents'}${filtersActive ? ' match your filters' : ''}`}
          </p>
        </div>
        {hasPermission('residents.create') && (
          <Link
            to="/residents/new"
            className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-charcoal transition-colors hover:bg-primary-dark hover:text-white"
          >
            + Register Resident
          </Link>
        )}
      </div>

      <div className="filter-bar">
        <div className="relative w-full sm:w-80">
          <SearchIcon className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" width={18} height={18} />
          <input
            type="search"
            aria-label="Search residents by name or resident number"
            placeholder="Search name or resident number…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && update({ search: search.trim() }, true)}
            className="w-full"
            style={{ paddingLeft: '2.25rem' }}
          />
        </div>
        <select aria-label="Filter by purok" value={purokId} onChange={(e) => update({ purok_id: e.target.value })}>
          <option value="">All puroks</option>
          {puroks.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
        <select aria-label="Filter by status" value={status} onChange={(e) => update({ status: e.target.value })}>
          <option value="">All statuses</option>
          {STATUSES.map((s) => <option key={s} value={s}>{humanize(s)}</option>)}
        </select>
        <select aria-label="Filter by sex" value={sex} onChange={(e) => update({ sex: e.target.value })}>
          <option value="">Male and female</option>
          <option value="male">Male</option>
          <option value="female">Female</option>
        </select>
        <select aria-label="Sort residents" className="sm:hidden" value={`${sort}:${dir}`} onChange={(e) => { const [k, d] = e.target.value.split(':'); update({ sort: k === 'name' ? '' : k, dir: d === 'desc' ? 'desc' : '' }); }}>
          <option value="name:asc">Name A–Z</option>
          <option value="name:desc">Name Z–A</option>
          <option value="age:asc">Youngest first</option>
          <option value="age:desc">Oldest first</option>
          <option value="purok:asc">By purok</option>
          <option value="resident_number:desc">Newest registered</option>
        </select>
        {filtersActive && <button type="button" className="link-btn" onClick={clearFilters}>Clear filters</button>}
      </div>

      {loadError && (
        <div className="mt-4 rounded-lg border border-error/30 bg-error/10 px-4 py-3 text-sm text-error-strong dark:text-error" role="alert">{loadError}</div>
      )}

      {/* Phones: one card per resident, actions always in reach. */}
      <ul className="mt-4 space-y-2 p-0 sm:hidden" aria-busy={loading}>
        {loading ? (
          Array.from({ length: 4 }, (_, i) => <li key={i} className="list-none"><div className="skeleton" style={{ height: '4.25rem' }} /></li>)
        ) : residents.length === 0 ? (
          <li className="list-none rounded-2xl border border-gray-200 dark:border-white/10">
            <div className="empty-state">
              <span>{filtersActive ? 'No residents match these filters.' : 'No residents registered yet.'}</span>
              {filtersActive ? <button type="button" className="button-sm" onClick={clearFilters}>Clear filters</button>
                : hasPermission('residents.create') && <Link to="/residents/new" className="button button-sm button-primary">Register the first resident</Link>}
            </div>
          </li>
        ) : (
          residents.map((r) => (
            <li key={r.id} className="relative flex list-none items-center gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-charcoal">
              <div className="min-w-0 flex-1">
                <Link to={`/residents/${r.id}`} className="font-medium text-gray-900 after:absolute after:inset-0 after:content-[''] dark:text-gray-100">
                  {r.full_name}
                </Link>
                <div className="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                  {[humanize(r.sex), r.age != null ? `${r.age} yrs` : null, r.purok?.name].filter(Boolean).join(' · ')}
                </div>
                <div className="font-mono text-[11px] text-gray-400">{r.resident_number}</div>
              </div>
              <StatusBadge status={r.status} />
              {hasPermission('residents.merge_archive') && r.status === 'active' && (
                <button type="button" title="Archive" aria-label={`Archive ${r.full_name}`} onClick={() => archive(r)} className="icon-btn danger relative z-10">
                  <ArchiveIcon width={16} height={16} />
                </button>
              )}
            </li>
          ))
        )}
      </ul>

      <div className="relative mt-5 hidden overflow-x-auto rounded-2xl sm:block border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-charcoal-deep">
        <table className="w-full border-collapse text-left text-sm">
          {/* Charcoal header in both themes, like the sidebar. */}
          <thead className="bg-charcoal text-gray-300">
            <tr className="text-xs uppercase tracking-wide">
              {COLUMNS.map((c) => (
                <th
                  key={c.key}
                  className="whitespace-nowrap px-3 py-3 font-medium sm:px-5"
                  aria-sort={c.sortable && sort === c.key ? (dir === 'asc' ? 'ascending' : 'descending') : undefined}
                >
                  {c.sortable ? (
                    <button type="button" className="th-sort" data-active={sort === c.key ? 'true' : undefined} onClick={() => toggleSort(c.key as SortKey)}>
                      {c.label}
                      <span className="sort-arrow" aria-hidden="true">{sort === c.key ? (dir === 'asc' ? '▲' : '▼') : '↕'}</span>
                    </button>
                  ) : (
                    c.label
                  )}
                </th>
              ))}
              <th className="px-3 py-3 sm:px-5 text-right font-medium"><span className="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              Array.from({ length: 6 }, (_, i) => (
                <tr key={i}>
                  <td colSpan={COLUMNS.length + 1} className="px-3 py-3 sm:px-5"><div className="skeleton skeleton-line" style={{ margin: 0 }} /></td>
                </tr>
              ))
            ) : residents.length === 0 ? (
              <EmptyRow
                colSpan={COLUMNS.length + 1}
                message={filtersActive ? 'No residents match these filters.' : 'No residents registered yet.'}
                action={
                  filtersActive ? (
                    <button type="button" className="button-sm" onClick={clearFilters}>Clear filters</button>
                  ) : hasPermission('residents.create') ? (
                    <Link to="/residents/new" className="button button-sm button-primary">Register the first resident</Link>
                  ) : undefined
                }
              />
            ) : (
              <AnimatePresence initial={false}>
                {residents.map((r, i) => (
                  <motion.tr
                    key={r.id}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1, transition: { duration: 0.15 } }}
                    exit={{ opacity: 0, transition: { duration: 0.1 } }}
                    className={`border-b border-gray-100 text-charcoal transition-colors last:border-0 hover:bg-primary/10 dark:border-white/10 dark:text-gray-100 dark:hover:bg-primary/15 ${
                      i % 2 === 1 ? 'bg-bg-light dark:bg-white/5' : 'bg-white dark:bg-transparent'
                    }`}
                  >
                    <td className={`whitespace-nowrap px-3 py-3 sm:px-5 font-mono text-xs text-gray-500 dark:text-gray-400`}>{r.resident_number}</td>
                    <td className="min-w-[11rem] px-3 py-3 sm:px-5">
                      <Link to={`/residents/${r.id}`} className="font-medium text-gray-900 hover:text-primary-dark dark:text-gray-100 dark:hover:text-primary">
                        {r.full_name}
                      </Link>
                    </td>
                    <td className={`px-3 py-3 sm:px-5 text-gray-600 dark:text-gray-300`}>{humanize(r.sex)}</td>
                    <td className={`px-3 py-3 sm:px-5 text-gray-600 dark:text-gray-300`}>{r.age ?? '—'}</td>
                    <td className="whitespace-nowrap px-3 py-3 sm:px-5 text-gray-600 dark:text-gray-300">{r.purok?.name ?? '—'}</td>
                    <td className="px-3 py-3 sm:px-5"><StatusBadge status={r.status} /></td>
                    <td className="px-3 py-3 sm:px-5">
                      <div className="flex items-center justify-end gap-1">
                        <Link to={`/residents/${r.id}`} title="View or edit" aria-label={`View or edit ${r.full_name}`} className="icon-btn">
                          <EditIcon width={16} height={16} />
                        </Link>
                        {hasPermission('residents.merge_archive') && r.status === 'active' && (
                          <button type="button" title="Archive" aria-label={`Archive ${r.full_name}`} onClick={() => archive(r)} className="icon-btn danger">
                            <ArchiveIcon width={16} height={16} />
                          </button>
                        )}
                      </div>
                    </td>
                  </motion.tr>
                ))}
              </AnimatePresence>
            )}
          </tbody>
        </table>
      </div>

      {lastPage > 1 && (
        <nav className="pager" aria-label="Pages">
          <button type="button" disabled={page <= 1} onClick={() => update({ page: String(page - 1) })}>Prev</button>
          {pageNumbers.map((n, idx) => (
            <span key={n} className="flex items-center">
              {idx > 0 && pageNumbers[idx - 1] !== n - 1 && <span className="px-1 text-gray-400">…</span>}
              <button type="button" aria-current={n === page ? 'page' : undefined} onClick={() => update({ page: n === 1 ? '' : String(n) })}>
                {n}
              </button>
            </span>
          ))}
          <button type="button" disabled={page >= lastPage} onClick={() => update({ page: String(page + 1) })}>Next</button>
        </nav>
      )}
    </div>
  );
}
