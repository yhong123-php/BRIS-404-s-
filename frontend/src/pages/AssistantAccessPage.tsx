import { useEffect, useState } from 'react';
import { EmptyRow, Section, SkeletonLines, StatusBadge, formatDateTime, humanize } from '../components/ui';
import client from '../api/client';
import type { AssistantQueryLog } from '../api/types';

/**
 * Configure Assistant Access and Review Chatbot Audit Logs (SRS §3.2.1.14,
 * §3.2.3.8–§3.2.3.9; SDD Table 5.2). Barangay Captain only.
 */

interface AccessData {
  categories: { key: string; label: string }[];
  roles: { id: number; name: string; description: string | null; enabled: Record<string, boolean> }[];
}

export default function AssistantAccessPage() {
  const [access, setAccess] = useState<AccessData | null>(null);
  const [logs, setLogs] = useState<AssistantQueryLog[]>([]);
  const [saving, setSaving] = useState<string | null>(null);

  useEffect(() => {
    client.get('/admin/assistant-access').then(({ data }) => setAccess(data.data));
    client.get('/admin/assistant-logs').then(({ data }) => setLogs(data.data));
  }, []);

  const toggle = async (roleId: number, category: string, isEnabled: boolean) => {
    const key = `${roleId}:${category}`;
    setSaving(key);
    try {
      const { data } = await client.put('/admin/assistant-access', { role_id: roleId, query_category: category, is_enabled: isEnabled });
      setAccess(data.data);
    } finally {
      setSaving(null);
    }
  };

  return (
    <div>
      <div className="page-header"><h2>Assistant Access</h2></div>
      <p className="note">Choose which query categories each role may use in the AI-Powered Barangay Assistant, and review every assistant query.</p>

      <Section title="Who may ask what">
        <div className="table-scroll">
          <table className="data-table">
            <thead>
              <tr>
                <th>Query category</th>
                {access?.roles.map((r) => <th key={r.id}>{r.name === 'captain' ? 'Barangay Captain' : 'Barangay Secretary'}</th>)}
              </tr>
            </thead>
            <tbody>
              {!access && <tr><td colSpan={3}><SkeletonLines lines={4} /></td></tr>}
              {access?.categories.map((c) => (
                <tr key={c.key}>
                  <td style={{ textTransform: 'none' }}>{c.label.charAt(0).toUpperCase() + c.label.slice(1)}</td>
                  {access.roles.map((r) => {
                    const key = `${r.id}:${c.key}`;
                    const on = r.enabled[c.key] ?? false;
                    return (
                      <td key={r.id}>
                        <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.5rem', cursor: 'pointer' }}>
                          <input type="checkbox" checked={on} disabled={saving === key} onChange={(e) => toggle(r.id, c.key, e.target.checked)} />
                          <StatusBadge status={on ? 'allowed' : 'not_allowed'} />
                        </label>
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Assistant audit log">
        <p className="note" style={{ marginTop: 0 }}>Append-only. Records who asked, when, the category and approved function used, and the type of personal information accessed.</p>
        <div className="table-scroll">
          <table className="data-table">
            <thead><tr><th>When</th><th>User</th><th>Question</th><th>Category</th><th>Function</th><th>Personal data accessed</th><th>Rows</th><th>Sent to model</th><th>Outcome</th></tr></thead>
            <tbody>
              {logs.map((log) => (
                <tr key={log.id}>
                  <td style={{ whiteSpace: 'nowrap' }}>{formatDateTime(log.created_at)}</td>
                  <td>{log.user ?? '—'}</td>
                  <td style={{ maxWidth: '18rem' }}>{log.question}</td>
                  <td>{humanize(log.query_category)}</td>
                  <td><code>{log.function_called ?? '—'}</code></td>
                  <td>{log.personal_data_types ?? '—'}</td>
                  <td>{log.row_count}</td>
                  <td>{log.sent_to_model === 'none' ? 'Nothing' : 'Aggregated data only'}</td>
                  <td><StatusBadge status={log.outcome} /></td>
                </tr>
              ))}
              {logs.length === 0 && <EmptyRow colSpan={9} message="No assistant queries yet." />}
            </tbody>
          </table>
        </div>
      </Section>
    </div>
  );
}
