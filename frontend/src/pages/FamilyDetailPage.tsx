import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import client from '../api/client';
import type { Family } from '../api/types';

export default function FamilyDetailPage() {
  const { id } = useParams();
  const [family, setFamily] = useState<Family | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    // Reset first: this route doesn't remount when navigating directly
    // between two families (e.g. browser back/forward through history), so
    // without this the previous family would stay on screen under the new
    // URL until the new fetch resolves.
    setFamily(null);
    setError(null);
    client
      .get(`/admin/families/${id}`)
      .then(({ data }) => setFamily(data.data))
      .catch((err) => setError(err?.response?.data?.message ?? 'Could not load family.'));
  }, [id]);

  if (error) return <div className="form-error">{error}</div>;
  if (!family) return <p>Loading…</p>;

  return (
    <div>
      <div className="page-header">
        <h2>{family.family_name}</h2>
      </div>
      <p>
        <strong>Head:</strong> {family.family_head_name ?? '—'}
      </p>
      {family.remarks && (
        <p>
          <strong>Remarks:</strong> {family.remarks}
        </p>
      )}

      <div className="detail-section">
        <h3>Relationships</h3>
        <table className="data-table">
          <thead>
            <tr>
              <th>Resident</th>
              <th>Relationship</th>
              <th>Related resident</th>
              <th>Start date</th>
              <th>End date</th>
            </tr>
          </thead>
          <tbody>
            {family.relationships?.map((r) => (
              <tr key={r.id}>
                <td>{r.resident_name}</td>
                <td>{r.relationship_type}</td>
                <td>{r.related_resident_name}</td>
                <td>{r.start_date ?? '—'}</td>
                <td>{r.end_date ?? '—'}</td>
              </tr>
            ))}
            {(!family.relationships || family.relationships.length === 0) && (
              <tr>
                <td colSpan={5}>No relationships linked to this family yet.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
