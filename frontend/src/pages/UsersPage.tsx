import { useCallback, useEffect, useState, type FormEvent } from 'react';
import client from '../api/client';
import { apiError, formatDateTime, StatusBadge } from '../components/ui';
import { useAuth } from '../context/AuthContext';
import { useDialog } from '../context/DialogContext';
import { useToast } from '../context/ToastContext';

/**
 * D1 — User accounts, roles and permissions (SRS §3.2.1.8–§3.2.1.9, SDD §4.13).
 * The Barangay Captain (System Administrator) creates accounts with a temporary
 * password, assigns one of the two roles, deactivates accounts (never deletes),
 * forces password resets, and edits role permissions.
 */

interface UserRow { id: number; full_name: string; username: string; email: string; role: string; role_id: number; position: string | null; is_active: boolean; must_change_password: boolean; last_login_at: string | null }
interface RoleRow { id: number; name: string; description: string | null; permissions: string[] }
interface PermissionRow { id: number; name: string; module: string; description: string }

const ROLE_LABEL: Record<string, string> = { captain: 'Barangay Captain (System Administrator)', secretary: 'Barangay Secretary' };

export default function UsersPage() {
  const { user: me } = useAuth();
  const toast = useToast();
  const dialog = useDialog();
  const [tab, setTab] = useState<'accounts' | 'roles'>('accounts');
  const [users, setUsers] = useState<UserRow[]>([]);
  const [roles, setRoles] = useState<RoleRow[]>([]);
  const [permissions, setPermissions] = useState<PermissionRow[]>([]);
  const [form, setForm] = useState({ full_name: '', username: '', email: '', role_id: '', position: '' });

  const load = useCallback(() => {
    client.get('/admin/users').then(({ data }) => setUsers(data.data));
    client.get('/admin/roles').then(({ data }) => { setRoles(data.data.roles); setPermissions(data.data.permissions); });
  }, []);
  useEffect(() => { load(); }, [load]);

  const showPassword = (name: string, password: string) =>
    dialog.notice({
      title: `Temporary password for ${name}`,
      message: 'Give it to the user in person. They must change it at first login. It is not shown again.',
      copyText: password,
    });

  const create = async (e: FormEvent) => {
    e.preventDefault();
    try {
      const { data } = await client.post('/admin/users', form);
      showPassword(form.full_name, data.data.temporary_password);
      setForm({ full_name: '', username: '', email: '', role_id: '', position: '' });
      load();
    } catch (err) {
      toast.error(apiError(err));
    }
  };

  const update = async (u: UserRow, body: Record<string, unknown>, ok: string) => {
    try { const { data } = await client.patch(`/admin/users/${u.id}`, body); setUsers(data.data); toast.success(ok); } catch (err) { toast.error(apiError(err)); }
  };

  const toggle = async (role: RoleRow, perm: string, on: boolean) => {
    const next = on ? [...role.permissions, perm] : role.permissions.filter((p) => p !== perm);
    try { const { data } = await client.put(`/admin/roles/${role.id}/permissions`, { permissions: next }); setRoles(data.data.roles); toast.success('Permissions updated.'); } catch (err) { toast.error(apiError(err)); }
  };

  const modules = Array.from(new Set(permissions.map((p) => p.module)));

  return (
    <div>
      <div className="page-header"><h2>User Management</h2></div>
      <div className="tabs">
        <button className={tab === 'accounts' ? 'active' : ''} onClick={() => setTab('accounts')}>Accounts</button>
        <button className={tab === 'roles' ? 'active' : ''} onClick={() => setTab('roles')}>Roles &amp; permissions</button>
      </div>

      {tab === 'accounts' && (
        <>
          <form className="panel" onSubmit={create}>
            <h4>Create an account</h4>
            <div className="form-grid">
              <label>Full name *<input required value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} /></label>
              <label>Username *<input required value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} /></label>
              <label>Email *<input required type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></label>
              <label>Role *<select required value={form.role_id} onChange={(e) => setForm({ ...form, role_id: e.target.value })}><option value="">Select…</option>{roles.map((r) => <option key={r.id} value={r.id}>{ROLE_LABEL[r.name] ?? r.name}</option>)}</select></label>
              <label>Position<input value={form.position} onChange={(e) => setForm({ ...form, position: e.target.value })} /></label>
            </div>
            <div className="form-actions"><button type="submit" className="button-primary">Create with temporary password</button></div>
          </form>

          <table className="data-table">
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead>
            <tbody>
              {users.map((u) => (
                <tr key={u.id}>
                  <td>{u.full_name}<div className="note">{u.email}{u.position ? ` · ${u.position}` : ''}</div></td>
                  <td>{u.username}</td>
                  <td>
                    <select value={u.role_id} disabled={u.id === me?.id} onChange={(e) => update(u, { role_id: Number(e.target.value) }, 'Role changed.')}>
                      {roles.map((r) => <option key={r.id} value={r.id}>{ROLE_LABEL[r.name] ?? r.name}</option>)}
                    </select>
                  </td>
                  <td><StatusBadge status={u.is_active ? 'active' : 'deactivated'} />{u.must_change_password && <div className="note">must change password</div>}</td>
                  <td>{u.last_login_at ? formatDateTime(u.last_login_at) : 'never'}</td>
                  <td>
                    {u.id !== me?.id && (
                      <div className="row-actions">
                        <button onClick={() => update(u, { is_active: !u.is_active }, u.is_active ? 'Account deactivated.' : 'Account activated.')}>{u.is_active ? 'Deactivate' : 'Activate'}</button>
                        <button onClick={async () => { if (!(await dialog.confirm({ title: 'Reset password', message: <>Reset the password of <strong>{u.full_name}</strong>? They will be signed out and must use the new temporary password.</>, confirmLabel: 'Reset password', tone: 'danger' }))) return; try { const { data } = await client.post(`/admin/users/${u.id}/reset-password`); showPassword(u.full_name, data.data.temporary_password); load(); } catch (err) { toast.error(apiError(err)); } }}>Reset password</button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="note">Accounts are deactivated rather than deleted, so historical records stay attributable. Deactivation and password resets sign the user out immediately.</p>
        </>
      )}

      {tab === 'roles' && (
        <>
          <p className="note">Defaults follow SDD Table 5.1. Changes take effect at the user’s next request and are recorded in the audit log.</p>
          <div className="table-scroll">
            <table className="data-table">
              <thead><tr><th>Module</th><th>Permission</th>{roles.map((r) => <th key={r.id}>{ROLE_LABEL[r.name] ?? r.name}</th>)}</tr></thead>
              <tbody>
                {modules.map((m) => permissions.filter((p) => p.module === m).map((p, i) => (
                  <tr key={p.id}>
                    <td>{i === 0 ? m : ''}</td>
                    <td>{p.description}<div className="note"><code>{p.name}</code></div></td>
                    {roles.map((r) => (
                      <td key={r.id}>
                        <input type="checkbox" checked={r.permissions.includes(p.name)} disabled={r.name === 'captain' && p.name === 'users.manage'} onChange={(e) => toggle(r, p.name, e.target.checked)} />
                      </td>
                    ))}
                  </tr>
                )))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}
