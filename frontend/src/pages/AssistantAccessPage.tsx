import { useEffect, useState } from 'react';
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

const OUTCOME_STYLES: Record<AssistantQueryLog['outcome'], string> = {
  answered: 'bg-success/10 text-success-strong dark:text-success',
  no_data: 'bg-gray-200/70 text-gray-700 dark:bg-white/10 dark:text-gray-300',
  refused: 'bg-error/10 text-error-strong dark:text-error',
  unavailable: 'bg-amber-200/70 text-amber-900 dark:bg-amber-400/20 dark:text-amber-200',
};

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
      <h1 className="text-2xl font-semibold tracking-tight text-charcoal dark:text-gray-50">Assistant Access</h1>
      <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
        Choose which query categories each role may use in the AI-Powered Barangay Assistant, and review every assistant query.
      </p>

      <div className="mt-6 overflow-x-auto rounded-2xl border border-gray-200 bg-bg-light dark:border-white/10 dark:bg-charcoal">
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="border-b border-gray-200 dark:border-white/10">
              <th className="px-4 py-3 font-semibold">Query category</th>
              {access?.roles.map((r) => (
                <th key={r.id} className="px-4 py-3 font-semibold capitalize">
                  {r.name === 'captain' ? 'Barangay Captain' : 'Barangay Secretary'}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {access?.categories.map((c) => (
              <tr key={c.key} className="border-b border-gray-100 last:border-0 dark:border-white/5">
                <td className="px-4 py-3 first-letter:uppercase">{c.label}</td>
                {access.roles.map((r) => {
                  const key = `${r.id}:${c.key}`;
                  return (
                    <td key={r.id} className="px-4 py-3">
                      <label className="inline-flex cursor-pointer items-center gap-2">
                        <input
                          type="checkbox"
                          checked={r.enabled[c.key] ?? false}
                          disabled={saving === key}
                          onChange={(e) => toggle(r.id, c.key, e.target.checked)}
                          className="h-4 w-4 accent-primary"
                        />
                        <span className="text-xs text-gray-500 dark:text-gray-400">{r.enabled[c.key] ? 'Allowed' : 'Not allowed'}</span>
                      </label>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <h2 className="mt-8 text-lg font-semibold text-charcoal dark:text-gray-50">Assistant audit log</h2>
      <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
        Append-only. Records who asked, when, the category and approved function used, and the type of personal information accessed.
      </p>
      <div className="mt-3 overflow-x-auto rounded-2xl border border-gray-200 bg-bg-light dark:border-white/10 dark:bg-charcoal">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b border-gray-200 dark:border-white/10">
              {['When', 'User', 'Question', 'Category', 'Function', 'Personal data accessed', 'Rows', 'Sent to model', 'Outcome'].map((h) => (
                <th key={h} className="px-3 py-2 font-semibold">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {logs.map((log) => (
              <tr key={log.id} className="border-b border-gray-100 align-top last:border-0 dark:border-white/5">
                <td className="whitespace-nowrap px-3 py-2">{new Date(log.created_at).toLocaleString()}</td>
                <td className="px-3 py-2">{log.user ?? '—'}</td>
                <td className="max-w-xs px-3 py-2">{log.question}</td>
                <td className="px-3 py-2">{log.query_category?.replace(/_/g, ' ') ?? '—'}</td>
                <td className="px-3 py-2 font-mono">{log.function_called ?? '—'}</td>
                <td className="px-3 py-2">{log.personal_data_types ?? '—'}</td>
                <td className="px-3 py-2">{log.row_count}</td>
                <td className="px-3 py-2">{log.sent_to_model === 'none' ? 'Nothing' : 'Aggregated data only'}</td>
                <td className="px-3 py-2">
                  <span className={`rounded-full px-2 py-0.5 font-medium ${OUTCOME_STYLES[log.outcome]}`}>{log.outcome.replace('_', ' ')}</span>
                </td>
              </tr>
            ))}
            {logs.length === 0 && (
              <tr>
                <td colSpan={9} className="px-3 py-6 text-center text-gray-500">
                  No assistant queries yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
