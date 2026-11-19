import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import client from '../api/client';
import { ArchiveIcon, EditIcon, SearchIcon } from '../components/icons';
import type { PaginatedResponse, Resident } from '../api/types';
import { useAuth } from '../context/AuthContext';
import { useDialog } from '../context/DialogContext';
import { useToast } from '../context/ToastContext';

// The -strong/dark: pairing keeps badge text at 4.5:1+ against its own
// light tint in both themes — the plain brand colors alone fail that on a
// light surface (success text-only hits 2.4:1) even though the tinted
// background itself is fine either way.
const STATUS_STYLES: Record<string, string> = {
  active: 'bg-success/10 text-success-strong dark:text-success',
  deceased: 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
  transferred_out: 'bg-gray-200/60 text-gray-600 dark:bg-gray-700/60 dark:text-gray-300',
  archived: 'bg-error/10 text-error-strong dark:text-error',
};

export default function ResidentsListPage() {
  const [searchParams] = useSearchParams();
  const [search, setSearch] = useState(searchParams.get('search') ?? '');
  const [residents, setResidents] = useState<Resident[]>([]);
  const [loading, setLoading] = useState(false);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const { hasPermission } = useAuth();
  const toast = useToast();
  const dialog = useDialog();

  const load = async (query: string, pageNum: number) => {
    setLoading(true);
    try {
      const { data } = await client.get<PaginatedResponse<Resident>>('/admin/residents', {
        params: { search: query || undefined, page: pageNum },
      });
      setResidents(data.data);
      setPage(data.meta?.current_page ?? 1);
      setLastPage(data.meta?.last_page ?? 1);
      setTotal(data.meta?.total ?? data.data.length);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    // Read the query straight from the URL rather than the `search` state:
    // navigating here again from the topbar search (while already mounted
    // on this route) changes searchParams without ever touching `search`,
    // so closing over `search` here would reload with a stale query.
    const query = searchParams.get('search') ?? '';
    setSearch(query);
    load(query, 1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams]);

  const handleSearch = (e: FormEvent) => {
    e.preventDefault();
    load(search, 1);
  };

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
      load(search, page);
    } catch (err: any) {
      toast.error(err?.response?.data?.message ?? 'Could not archive resident.');
    }
  };

  const pageNumbers = Array.from({ length: lastPage }, (_, i) => i + 1).filter(
    (n) => n === 1 || n === lastPage || Math.abs(n - page) <= 1
  );

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Residents</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{total} total</p>
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

      <form onSubmit={handleSearch} className="relative mt-5 max-w-md">
        <SearchIcon className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" width={18} height={18} />
        <input
          placeholder="Search by name or resident number…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="w-full rounded-lg border border-gray-300 bg-white py-2.5 pl-9 pr-24 text-sm outline-none focus:border-primary focus:ring-1 focus:ring-primary dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
        />
        <button
          type="submit"
          className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-charcoal hover:bg-primary-dark hover:text-white"
        >
          Search
        </button>
      </form>

      <div className="mt-5 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-charcoal-deep">
        <table className="w-full text-left text-sm">
          {/* Charcoal header unconditionally (not dark:-gated), matching the
              spec's "charcoal headers" — a fixed dark bar regardless of the
              app's light/dark toggle, same idea as the sidebar. */}
          <thead className="bg-charcoal text-gray-300">
            <tr className="text-xs uppercase tracking-wide">
              <th className="px-5 py-3 font-medium">Resident #</th>
              <th className="px-5 py-3 font-medium">Name</th>
              <th className="px-5 py-3 font-medium">Sex</th>
              <th className="px-5 py-3 font-medium">Age</th>
              <th className="px-5 py-3 font-medium">Purok</th>
              <th className="px-5 py-3 font-medium">Status</th>
              <th className="px-5 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              Array.from({ length: 6 }, (_, i) => (
                <tr key={i}>
                  <td colSpan={7} className="px-5 py-3"><div className="skeleton skeleton-line" style={{ margin: 0 }} /></td>
                </tr>
              ))
            ) : residents.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-5 py-8 text-center text-gray-500 dark:text-gray-400">
                  No residents found.
                </td>
              </tr>
            ) : (
              <AnimatePresence initial={false}>
                {residents.map((r, i) => (
                  <motion.tr
                    key={r.id}
                    initial={{ opacity: 0, x: -16 }}
                    animate={{ opacity: 1, x: 0, transition: { duration: 0.25, delay: i * 0.03 } }}
                    exit={{ opacity: 0, transition: { duration: 0.15 } }}
                    className={`border-b border-gray-100 text-charcoal transition-colors last:border-0 hover:bg-primary/10 dark:border-white/10 dark:text-gray-100 dark:hover:bg-primary/15 ${
                      i % 2 === 1 ? 'bg-bg-light dark:bg-white/5' : 'bg-white dark:bg-transparent'
                    }`}
                  >
                  <td className="px-5 py-3 font-mono text-xs text-gray-500 dark:text-gray-400">{r.resident_number}</td>
                  <td className="px-5 py-3">
                    <Link to={`/residents/${r.id}`} className="font-medium text-gray-900 hover:text-primary-dark dark:text-gray-100 dark:hover:text-primary">
                      {r.full_name}
                    </Link>
                  </td>
                  <td className="px-5 py-3 capitalize text-gray-600 dark:text-gray-300">{r.sex}</td>
                  <td className="px-5 py-3 text-gray-600 dark:text-gray-300">{r.age ?? '—'}</td>
                  <td className="px-5 py-3 text-gray-600 dark:text-gray-300">{r.purok?.name ?? '—'}</td>
                  <td className="px-5 py-3">
                    <span className={`inline-block rounded-full px-2.5 py-1 text-xs font-medium capitalize ${STATUS_STYLES[r.status] ?? ''}`}>
                      {r.status.replace('_', ' ')}
                    </span>
                  </td>
                  <td className="px-5 py-3">
                    <div className="flex items-center justify-end gap-1">
                      <Link
                        to={`/residents/${r.id}`}
                        title="View / edit"
                        className="rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-primary dark:hover:bg-gray-800"
                      >
                        <EditIcon width={16} height={16} />
                      </Link>
                      {hasPermission('residents.merge_archive') && r.status === 'active' && (
                        <button
                          type="button"
                          title="Archive"
                          onClick={() => archive(r)}
                          className="rounded-md p-1.5 text-gray-400 hover:bg-error/10 hover:text-error"
                        >
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
        <div className="mt-5 flex items-center justify-center gap-1.5">
          <button
            disabled={page <= 1}
            onClick={() => load(search, page - 1)}
            className="rounded-full px-3 py-1.5 text-sm text-gray-500 hover:bg-gray-100 disabled:opacity-40 disabled:hover:bg-transparent dark:text-gray-400 dark:hover:bg-gray-800"
          >
            Prev
          </button>
          {pageNumbers.map((n, idx) => (
            <span key={n} className="flex items-center">
              {idx > 0 && pageNumbers[idx - 1] !== n - 1 && <span className="px-1 text-gray-400">…</span>}
              <button
                onClick={() => load(search, n)}
                className={`h-8 w-8 rounded-full text-sm font-medium transition-colors ${
                  n === page ? 'bg-primary text-charcoal' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'
                }`}
              >
                {n}
              </button>
            </span>
          ))}
          <button
            disabled={page >= lastPage}
            onClick={() => load(search, page + 1)}
            className="rounded-full px-3 py-1.5 text-sm text-gray-500 hover:bg-gray-100 disabled:opacity-40 disabled:hover:bg-transparent dark:text-gray-400 dark:hover:bg-gray-800"
          >
            Next
          </button>
        </div>
      )}
    </div>
  );
}
