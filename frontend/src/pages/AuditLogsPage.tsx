import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { formatDateTime, SkeletonLines, EmptyRow, humanize, MODULE_NAMES, moduleName } from '../components/ui';
import client from '../api/client';
import type { AuditLogEntry, AssistantQueryLog } from '../api/types';
import { useAuth } from '../context/AuthContext';

/**
 * D2 — Audit log (SRS §3.2.1.12, SDD §4.13, §5.3). Append-only; filterable by
 * user, date range, module, and record. Assistant queries (ai_query_logs) are
 * listed in the same view.
 */
export default function AuditLogsPage() {
  const { hasPermission } = useAuth();
  const [tab, setTab] = useState<'activity' | 'assistant'>('activity');
  const [logs, setLogs] = useState<AuditLogEntry[]>([]);
  const [assistantLogs, setAssistantLogs] = useState<AssistantQueryLog[]>([]);
  const [users, setUsers] = useState<{ id: number; full_name: string }[]>([]);
  const [filters, setFilters] = useState({ module: '', action: '', user_id: '', table_name: '', record_id: '', date_from: '', date_to: '' });
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(false);

  const load = useCallback((p = 1) => {
    setLoading(true);
    const params = Object.fromEntries(Object.entries({ ...filters, page: p }).filter(([, v]) => v !== '' && v !== undefined));
    client
      .get('/admin/audit-logs', { params })
      .then(({ data }) => {
        setLogs(data.data);
        setUsers(data.users ?? []);
        setPage(data.meta.current_page);
        setLastPage(data.meta.last_page);
      })
      .finally(() => setLoading(false));
  }, [filters]);

  // Load once on open; later loads happen when the Filter button or paging is used.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load(1); }, []);
  useEffect(() => {
    if (tab === 'assistant' && hasPermission('assistant.configure')) {
      client.get('/admin/assistant-logs').then(({ data }) => setAssistantLogs(data.data));
    }
  }, [tab, hasPermission]);

  const set = (k: keyof typeof filters) => (e: { target: { value: string } }) => setFilters({ ...filters, [k]: e.target.value });
  const handleFilter = (e: FormEvent) => { e.preventDefault(); load(1); };

  return (
    <div>
      <div className="page-header"><h2>Audit Log</h2></div>
      <p className="note">Append-only: no screen, role, or endpoint can edit or delete an entry.</p>
      <div className="tabs">
        <button className={tab === 'activity' ? 'active' : ''} onClick={() => setTab('activity')}>System activity</button>
        {hasPermission('assistant.configure') && <button className={tab === 'assistant' ? 'active' : ''} onClick={() => setTab('assistant')}>AI assistant queries</button>}
      </div>

      {tab === 'activity' && (
        <>
          <form onSubmit={handleFilter} className="search-bar" style={{ flexWrap: 'wrap' }}>
            <select value={filters.user_id} onChange={set('user_id')}>
              <option value="">All users</option>
              {users.map((u) => <option key={u.id} value={u.id}>{u.full_name}</option>)}
            </select>
            <select value={filters.module} onChange={set('module')} aria-label="Filter by module">
              <option value="">All modules</option>
              {Object.entries(MODULE_NAMES).map(([code, name]) => <option key={code} value={code}>{name}</option>)}
            </select>
            <input placeholder="Action (e.g. update)" value={filters.action} onChange={set('action')} />
            <input placeholder="Table (e.g. residents)" value={filters.table_name} onChange={set('table_name')} />
            <input placeholder="Record ID" value={filters.record_id} onChange={set('record_id')} style={{ width: '7rem' }} />
            <input type="date" value={filters.date_from} onChange={set('date_from')} title="From" />
            <input type="date" value={filters.date_to} onChange={set('date_to')} title="To" />
            <button type="submit">Filter</button>
          </form>

          {loading ? <SkeletonLines lines={8} /> : (
            <div className="table-scroll">
              <table className="data-table">
                <thead><tr><th>When</th><th>User</th><th>Action</th><th>Module</th><th>Table</th><th>Record</th><th>Changes</th></tr></thead>
                <tbody>
                  {logs.map((log) => (
                    <tr key={log.id}>
                      <td>{formatDateTime(log.created_at)}</td>
                      <td>{log.user}</td>
                      <td>{humanize(log.action)}</td>
                      <td>{moduleName(log.module)}</td>
                      <td>{humanize(log.table_name)}</td>
                      <td>{log.record_id ?? '—'}</td>
                      <td style={{ maxWidth: '28rem' }}>
                        {(log.new_values || log.old_values) ? (
                          <details>
                            <summary className="note">view</summary>
                            {log.old_values && <pre style={{ whiteSpace: 'pre-wrap', fontSize: '0.75rem' }}>before: {JSON.stringify(log.old_values, null, 1)}</pre>}
                            {log.new_values && <pre style={{ whiteSpace: 'pre-wrap', fontSize: '0.75rem' }}>after: {JSON.stringify(log.new_values, null, 1)}</pre>}
                          </details>
                        ) : '—'}
                      </td>
                    </tr>
                  ))}
                  {logs.length === 0 && <EmptyRow colSpan={7} message="No audit log entries found." />}
                </tbody>
              </table>
            </div>
          )}
          <div className="row-actions" style={{ marginTop: '0.7rem' }}>
            <button disabled={page <= 1} onClick={() => load(page - 1)}>← Newer</button>
            <span className="note">Page {page} of {lastPage}</span>
            <button disabled={page >= lastPage} onClick={() => load(page + 1)}>Older →</button>
          </div>
        </>
      )}

      {tab === 'assistant' && (
        <div className="table-scroll">
          <table className="data-table">
            <thead><tr><th>When</th><th>User</th><th>Question</th><th>Category</th><th>Function</th><th>Personal data accessed</th><th>Rows</th><th>Sent to AI model</th><th>Outcome</th></tr></thead>
            <tbody>
              {assistantLogs.map((l) => (
                <tr key={l.id}>
                  <td>{formatDateTime(l.created_at)}</td>
                  <td>{l.user ?? '—'}</td>
                  <td>{l.question}</td>
                  <td>{l.query_category?.replace(/_/g, ' ') ?? '—'}</td>
                  <td><code>{l.function_called ?? '—'}</code></td>
                  <td>{l.personal_data_types ?? '—'}</td>
                  <td>{l.row_count}</td>
                  <td>{l.sent_to_model === 'none' ? 'Nothing' : 'Aggregated data only'}</td>
                  <td>{l.outcome.replace('_', ' ')}</td>
                </tr>
              ))}
              {assistantLogs.length === 0 && <EmptyRow colSpan={9} message="No assistant queries yet." />}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
