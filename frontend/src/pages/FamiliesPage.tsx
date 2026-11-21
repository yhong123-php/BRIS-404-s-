import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import client from '../api/client';
import type { Family, PaginatedResponse } from '../api/types';
import { useAuth } from '../context/AuthContext';
import { EmptyRow, SkeletonLines } from '../components/ui';

export default function FamiliesPage() {
  const [families, setFamilies] = useState<Family[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [familyName, setFamilyName] = useState('');
  const [remarks, setRemarks] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const { hasPermission } = useAuth();

  const load = () => {
    client.get<PaginatedResponse<Family>>('/admin/families').then(({ data }) => setFamilies(data.data)).finally(() => setLoading(false));
  };

  useEffect(load, []);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await client.post('/admin/families', { family_name: familyName, remarks: remarks || null });
      setFamilyName('');
      setRemarks('');
      setShowForm(false);
      load();
    } catch (err: any) {
      setError(err?.response?.data?.message ?? 'Could not create family.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div>
      <div className="page-header">
        <h2>Families</h2>
        {hasPermission('relationships.manage') && (
          <button className="button" onClick={() => setShowForm((v) => !v)}>
            {showForm ? 'Cancel' : '+ New Family'}
          </button>
        )}
      </div>

      {showForm && (
        <form className="form-grid" onSubmit={submit}>
          {error && <div className="form-error">{error}</div>}
          <label>
            Family name *
            <input value={familyName} onChange={(e) => setFamilyName(e.target.value)} required />
          </label>
          <label>
            Remarks
            <input value={remarks} onChange={(e) => setRemarks(e.target.value)} />
          </label>
          <div className="form-actions">
            <button className="button-primary" type="submit" disabled={submitting}>
              {submitting ? 'Saving…' : 'Create Family'}
            </button>
          </div>
        </form>
      )}

      <table className="data-table">
        <thead>
          <tr>
            <th>Family Name</th>
            <th>Head</th>
            <th>Members</th>
          </tr>
        </thead>
        <tbody>
          {families.map((f) => (
            <tr key={f.id}>
              <td>
                <Link to={`/families/${f.id}`}>{f.family_name}</Link>
              </td>
              <td>{f.family_head_name ?? '—'}</td>
              <td>{f.member_count ?? 0}</td>
            </tr>
          ))}
          {loading && <tr><td colSpan={3}><SkeletonLines lines={4} /></td></tr>}
          {!loading && families.length === 0 && (
            <EmptyRow
              colSpan={3}
              message="No families recorded yet."
              action={hasPermission('relationships.manage') && !showForm ? <button type="button" className="button-sm button-primary" onClick={() => setShowForm(true)}>Add the first family</button> : undefined}
            />
          )}
        </tbody>
      </table>
    </div>
  );
}
