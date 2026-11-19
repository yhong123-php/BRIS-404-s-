import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import client from '../api/client';
import type { Household } from '../api/types';

export default function HouseholdDetailPage() {
  const { id } = useParams();
  const [household, setHousehold] = useState<Household | null>(null);

  useEffect(() => {
    // Reset first: this route doesn't remount when navigating directly
    // between two households (e.g. browser back/forward through history),
    // so without this the previous household would stay on screen under
    // the new URL until the new fetch resolves.
    setHousehold(null);
    client.get(`/admin/households/${id}`).then(({ data }) => setHousehold(data.data));
  }, [id]);

  if (!household) return <p>Loading…</p>;

  return (
    <div>
      <h2>Household {household.household_number}</h2>
      <dl className="detail-grid">
        <dt>Status</dt>
        <dd>{household.status}</dd>
        <dt>Dwelling</dt>
        <dd>{household.dwelling_ownership ?? '—'} / {household.construction_type ?? '—'}</dd>
        <dt>Electricity</dt>
        <dd>{household.electricity_access ? 'Yes' : 'No'}</dd>
      </dl>

      <h3>Members</h3>
      <table className="data-table">
        <thead>
          <tr>
            <th>Resident</th>
            <th>Relationship</th>
            <th>Head?</th>
            <th>Joined</th>
          </tr>
        </thead>
        <tbody>
          {household.members?.map((m) => (
            <tr key={m.id}>
              <td>{m.resident_name}</td>
              <td>{m.relationship_to_head ?? '—'}</td>
              <td>{m.is_head ? '✅' : ''}</td>
              <td>{m.date_joined}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
