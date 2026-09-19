import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import client from '../api/client';
import type { Household, PaginatedResponse } from '../api/types';
import { useAuth } from '../context/AuthContext';

export default function HouseholdsListPage() {
  const [households, setHouseholds] = useState<Household[]>([]);
  const { hasPermission } = useAuth();

  useEffect(() => {
    client.get<PaginatedResponse<Household>>('/admin/households').then(({ data }) => setHouseholds(data.data));
  }, []);

  return (
    <div>
      <div className="page-header">
        <h2>Households</h2>
        {hasPermission('households.manage') && (
          <Link to="/households/new" className="button">
            + New Household
          </Link>
        )}
      </div>
      <table className="data-table">
        <thead>
          <tr>
            <th>Household #</th>
            <th>Members</th>
            <th>Head</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          {households.map((h) => (
            <tr key={h.id}>
              <td>
                <Link to={`/households/${h.id}`}>{h.household_number}</Link>
              </td>
              <td>{h.size}</td>
              <td>{h.members?.find((m) => m.is_head)?.resident_name ?? '—'}</td>
              <td>{h.status}</td>
            </tr>
          ))}
          {households.length === 0 && (
            <tr>
              <td colSpan={4}>No households found.</td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}
