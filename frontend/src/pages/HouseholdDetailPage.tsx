import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import client from '../api/client';
import type { Household } from '../api/types';
import { PageSkeleton, Section, StatusBadge, apiError, formatDate, humanize } from '../components/ui';
import { useAuth } from '../context/AuthContext';
import { useDialog } from '../context/DialogContext';
import { useToast } from '../context/ToastContext';

/**
 * A2 — Household management (SDD §4.4): dwelling details, members with their
 * relationship to the head, adding members, and promoting a new head.
 */

const RELATIONSHIPS = ['spouse', 'son', 'daughter', 'father', 'mother', 'grandchild', 'grandparent', 'sibling', 'relative', 'boarder', 'helper', 'other'];
const OWNERSHIP = ['owned', 'rented', 'shared', 'informal_settler', 'other'];
const CONSTRUCTION = ['concrete', 'semi_concrete', 'wood', 'light_materials', 'other'];

export default function HouseholdDetailPage() {
  const { id } = useParams();
  const { hasPermission } = useAuth();
  const dialog = useDialog();
  const toast = useToast();
  const canManage = hasPermission('households.manage');
  const [household, setHousehold] = useState<Household | null>(null);
  const [purokName, setPurokName] = useState<string | null>(null);

  const load = useCallback(() => client.get(`/admin/households/${id}`).then(({ data }) => setHousehold(data.data)), [id]);
  useEffect(() => {
    // Reset first: this route doesn't remount between households, so the previous one would linger.
    setHousehold(null);
    load();
  }, [load]);
  useEffect(() => {
    if (!household?.purok_id) return;
    client.get('/admin/puroks').then(({ data }) => setPurokName(data.data.find((p: { id: number }) => p.id === household.purok_id)?.name ?? null)).catch(() => undefined);
  }, [household?.purok_id]);

  const run = async (fn: () => Promise<unknown>, ok: string) => {
    try { await fn(); toast.success(ok); load(); } catch (err) { toast.error(apiError(err)); }
  };

  if (!household) return <PageSkeleton />;
  const members = household.members ?? [];

  const addMember = async () => {
    const res = await client.get('/admin/residents', { params: { purok_id: household.purok_id ?? undefined, status: 'active', per_page: 300 } }).catch(() => null);
    const already = new Set(members.map((m) => m.resident_id));
    const options = ((res?.data.data ?? []) as { id: number; full_name: string; age: number | null }[])
      .filter((r) => !already.has(r.id))
      .map((r) => ({ value: String(r.id), label: `${r.full_name}${r.age !== null ? ` (${r.age})` : ''}` }));
    if (options.length === 0) return dialog.notice({ title: 'Add member', message: `No other active residents are registered in ${purokName ?? 'this purok'}. Register the resident first.` });
    const v = await dialog.form({
      title: `Add a member to ${household.household_number}`,
      description: 'A resident belongs to one household at a time; adding them here ends any other current membership.',
      fields: [
        { name: 'resident_id', label: 'Resident', type: 'select', required: true, options },
        { name: 'relationship_to_head', label: 'Relationship to the household head', type: 'select', required: true, options: RELATIONSHIPS.map((r) => ({ value: r, label: humanize(r) })) },
      ],
      submitLabel: 'Add member',
    });
    if (v) run(() => client.post(`/admin/households/${id}/members`, { resident_id: Number(v.resident_id), relationship_to_head: v.relationship_to_head }), 'Member added.');
  };

  const makeHead = async (residentId: number, name: string) => {
    const ok = await dialog.confirm({
      title: 'Change household head',
      message: <>Make <strong>{name}</strong> the head of household {household.household_number}? The current head stays a member.</>,
      confirmLabel: 'Make head',
    });
    if (ok) run(() => client.post(`/admin/households/${id}/promote-head`, { resident_id: residentId }), `${name} is now the household head.`);
  };

  const editDetails = async () => {
    const v = await dialog.form({
      title: `Edit household ${household.household_number}`,
      fields: [
        { name: 'house_number', label: 'House number', defaultValue: household.house_number ?? '' },
        { name: 'street', label: 'Street / sitio', defaultValue: household.street ?? '' },
        { name: 'dwelling_ownership', label: 'Dwelling ownership', type: 'select', defaultValue: household.dwelling_ownership ?? '', options: OWNERSHIP.map((o) => ({ value: o, label: humanize(o) })) },
        { name: 'construction_type', label: 'Construction', type: 'select', defaultValue: household.construction_type ?? '', options: CONSTRUCTION.map((o) => ({ value: o, label: humanize(o) })) },
        { name: 'water_source', label: 'Water source', defaultValue: household.water_source ?? '', placeholder: 'e.g. Level III (piped), deep well' },
        { name: 'electricity_access', label: 'Electricity', type: 'select', required: true, defaultValue: household.electricity_access ? '1' : '0', options: [{ value: '1', label: 'Yes' }, { value: '0', label: 'No' }] },
        { name: 'toilet_type', label: 'Toilet', defaultValue: household.toilet_type ?? '', placeholder: 'e.g. Water-sealed' },
      ],
      submitLabel: 'Save changes',
    });
    if (v) {
      const body = Object.fromEntries(Object.entries(v).map(([k, x]) => [k, x === '' ? null : x]));
      run(() => client.patch(`/admin/households/${id}`, { ...body, electricity_access: v.electricity_access === '1' }), 'Household updated.');
    }
  };

  return (
    <div>
      <div className="page-header">
        <h2>Household {household.household_number} <StatusBadge status={household.status ?? 'active'} /></h2>
        {canManage && (
          <div className="row-actions">
            <button onClick={editDetails}>Edit details</button>
            <button className="button-primary" onClick={addMember}>+ Add member</button>
          </div>
        )}
      </div>

      <Section title="Dwelling">
        <dl className="detail-grid">
          <dt>Purok</dt><dd>{purokName ?? '—'}</dd>
          <dt>Address</dt><dd>{[household.house_number, household.street].filter(Boolean).join(' ') || '—'}</dd>
          <dt>Ownership</dt><dd>{humanize(household.dwelling_ownership)}</dd>
          <dt>Construction</dt><dd>{humanize(household.construction_type)}</dd>
          <dt>Water source</dt><dd>{household.water_source || '—'}</dd>
          <dt>Electricity</dt><dd>{household.electricity_access ? 'Yes' : 'No'}</dd>
          <dt>Toilet</dt><dd>{household.toilet_type || '—'}</dd>
          <dt>Members</dt><dd>{members.length}</dd>
        </dl>
      </Section>

      <Section title={`Members (${members.length})`}>
        <table className="data-table">
          <thead><tr><th>Resident</th><th>Relationship</th><th>Role</th><th>Member since</th><th></th></tr></thead>
          <tbody>
            {[...members].sort((a, b) => Number(b.is_head) - Number(a.is_head)).map((m) => (
              <tr key={m.id}>
                <td><Link to={`/residents/${m.resident_id}`}>{m.resident_name}</Link></td>
                <td>{humanize(m.relationship_to_head)}</td>
                <td>{m.is_head ? <StatusBadge status="head" /> : 'Member'}</td>
                <td>{formatDate(m.date_joined)}</td>
                <td>{canManage && !m.is_head && <button onClick={() => makeHead(m.resident_id, m.resident_name)}>Make head</button>}</td>
              </tr>
            ))}
            {members.length === 0 && <tr><td colSpan={5}>No members yet. Add the residents who live in this dwelling.</td></tr>}
          </tbody>
        </table>
      </Section>
    </div>
  );
}
