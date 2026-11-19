import { useCallback, useEffect, useState } from 'react';
import client from '../api/client';
import { apiError, downloadFile, formatDateTime, StatusBadge } from '../components/ui';
import { useDialog } from '../context/DialogContext';
import { useToast } from '../context/ToastContext';

/**
 * D3 — Backup and restore (SRS §3.2.2.12, SDD §6). Nightly backups run at 1:00 AM;
 * the Captain can also back up on demand, download an archive, and restore.
 * A pre-restore snapshot is taken automatically so a mistaken restore can be undone.
 */

interface BackupRow {
  id: number; file_name: string; file_size: number; type: string; checksum: string | null; status: string;
  error_message: string | null; started_at: string; finished_at: string | null; trigger: { full_name: string } | null;
}

export default function BackupsPage() {
  const toast = useToast();
  const dialog = useDialog();
  const [rows, setRows] = useState<BackupRow[]>([]);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => client.get('/admin/backups').then(({ data }) => setRows(data.data)), []);
  useEffect(() => { load(); }, [load]);

  const backupNow = async () => {
    setBusy(true);
    try {
      await client.post('/admin/backups', {}, { timeout: 900_000 });
      toast.success('Backup completed.');
    } catch (err) {
      toast.error(apiError(err, 'Backup failed — see the history below.'));
    } finally {
      setBusy(false);
      load();
    }
  };

  const restore = async (b: BackupRow) => {
    const ok = await dialog.confirm({
      title: 'Restore backup',
      message: <>This <strong>replaces all current data</strong> with the data from <strong>{formatDateTime(b.started_at)}</strong> ({b.file_name}). A pre-restore snapshot is taken first, so this can be undone.</>,
      confirmLabel: 'Restore', tone: 'danger', typeToConfirm: 'RESTORE',
    });
    if (!ok) return;
    setBusy(true);
    try {
      const { data } = await client.post(`/admin/backups/${b.id}/restore`, { confirm: 'RESTORE' }, { timeout: 1_800_000 });
      await dialog.notice({ title: 'Restore complete', message: `${data.message} You will be signed out because the session data was restored too.` });
      localStorage.removeItem('bris_token');
      window.location.href = '/login';
    } catch (err) {
      toast.error(apiError(err));
    } finally {
      setBusy(false);
      load();
    }
  };

  const lastSuccess = rows.find((r) => r.status === 'success');

  return (
    <div>
      <div className="page-header">
        <h2>Backup &amp; Restore</h2>
        <button className="button-primary" onClick={backupNow} disabled={busy}>{busy ? 'Working…' : 'Back up now'}</button>
      </div>
      <div className={`callout ${lastSuccess ? '' : 'callout-warn'}`}>
        {lastSuccess ? <>Last successful backup: <strong>{formatDateTime(lastSuccess.started_at)}</strong>.</> : <>No successful backup yet.</>}{' '}
        Scheduled backups run nightly at 1:00 AM (keep the Laravel scheduler running). Archives are compressed, encrypted, and checksummed.
        Retention: daily for 14 days, weekly for 8 weeks, monthly for 12 months. Copy downloaded archives to an off-site storage device.
      </div>
      <table className="data-table">
        <thead><tr><th>Started</th><th>Type</th><th>File</th><th>Size</th><th>Status</th><th>By</th><th></th></tr></thead>
        <tbody>
          {rows.map((b) => (
            <tr key={b.id}>
              <td>{formatDateTime(b.started_at)}</td>
              <td>{b.type.replace('_', ' ')}</td>
              <td>{b.file_name}<div className="note" title={b.checksum ?? ''}>{b.checksum ? `sha256 ${b.checksum.slice(0, 12)}…` : ''}</div></td>
              <td>{b.file_size ? `${(b.file_size / 1024 / 1024).toFixed(2)} MB` : '—'}</td>
              <td><StatusBadge status={b.status} />{b.error_message && <div className="note">{b.error_message}</div>}</td>
              <td>{b.trigger?.full_name ?? 'Scheduler'}</td>
              <td>
                {b.status === 'success' && !b.error_message?.includes('pruned') && (
                  <div className="row-actions">
                    <button onClick={() => downloadFile(`/admin/backups/${b.id}/download`, {}, b.file_name.replace('.enc', '')).catch((err) => toast.error(apiError(err)))}>Download</button>
                    <button onClick={() => restore(b)} disabled={busy}>Restore</button>
                  </div>
                )}
              </td>
            </tr>
          ))}
          {rows.length === 0 && <tr><td colSpan={7}>No backups yet.</td></tr>}
        </tbody>
      </table>
    </div>
  );
}
