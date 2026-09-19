import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import client from '../api/client';
import type { PaginatedResponse, Resident } from '../api/types';
import { useAuth } from '../context/AuthContext';

export default function ResidentsListPage() {
  const [search, setSearch] = useState('');
  const [residents, setResidents] = useState<Resident[]>([]);
  const [loading, setLoading] = useState(false);
  const { hasPermission } = useAuth();

  const load = async (query: string) => {
    setLoading(true);
    try {
      const { data } = await client.get<PaginatedResponse<Resident>>('/admin/residents', {
        params: query ? { search: query } : {},
      });
      setResidents(data.data);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load('');
  }, []);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    load(search);
  };

  return (
    <div>
      <div className="page-header">
        <h2>Residents</h2>
        {hasPermission('residents.create') && (
          <Link to="/residents/new" className="button">
            + Register Resident
          </Link>
        )}
      </div>

      <form onSubmit={handleSearch} className="search-bar">
        <input
          placeholder="Search by name or resident number…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <button type="submit">Search</button>
      </form>

      {loading ? (
        <p>Loading…</p>
      ) : (
        <table className="data-table">
          <thead>
            <tr>
              <th>Resident #</th>
              <th>Name</th>
              <th>Sex</th>
              <th>Age</th>
              <th>Purok</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {residents.map((r) => (
              <tr key={r.id}>
                <td>{r.resident_number}</td>
                <td>
                  <Link to={`/residents/${r.id}`}>{r.full_name}</Link>
                </td>
                <td>{r.sex}</td>
                <td>{r.age}</td>
                <td>{r.purok?.name ?? '—'}</td>
                <td>
                  <span className={`status-badge status-${r.status}`}>{r.status}</span>
                </td>
              </tr>
            ))}
            {residents.length === 0 && (
              <tr>
                <td colSpan={6}>No residents found.</td>
              </tr>
            )}
          </tbody>
        </table>
      )}
    </div>
  );
}
