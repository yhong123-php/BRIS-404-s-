import { useEffect, useState } from 'react';
import { EmptyRow, SkeletonLines, StatusBadge } from '../components/ui';
import { Link } from 'react-router-dom';
import client from '../api/client';
import type { Household, PaginatedResponse } from '../api/types';
import { useAuth } from '../context/AuthContext';

export default function HouseholdsListPage() {
  const [households, setHouseholds] = useState<Household[]>([]);
  const [loading, setLoading] = useState(true);
  const { hasPermission } = useAuth();

  useEffect(() => {
    client.get<PaginatedResponse<Household>>('/admin/households').then(({ data }) => setHouseholds(data.data)).finally(() => setLoading(false));
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
              <td><StatusBadge status={h.status ?? 'active'} /></td>
            </tr>
          ))}
          {loading && <tr><td colSpan={4}><SkeletonLines lines={4} /></td></tr>}
          {!loading && households.length === 0 && (
            <EmptyRow
              colSpan={4}
              message="No households recorded yet."
              action={hasPermission('households.manage') ? <Link to="/households/new" className="button button-sm button-primary">Create the first household</Link> : undefined}
            />
          )}
        </tbody>
      </table>
    </div>
  );
}
