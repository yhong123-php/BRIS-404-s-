import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import client from '../api/client';
import type { Purok, Resident } from '../api/types';

interface DraftMember {
  resident_id: number;
  resident_name: string;
  relationship_to_head: string;
  is_head: boolean;
}

export default function HouseholdCreatePage() {
  const [householdNumber, setHouseholdNumber] = useState('');
  const [purokId, setPurokId] = useState('');
  const [puroks, setPuroks] = useState<Purok[]>([]);
  const [search, setSearch] = useState('');
  const [results, setResults] = useState<Resident[]>([]);
  const [members, setMembers] = useState<DraftMember[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const navigate = useNavigate();

  useEffect(() => {
    client.get('/admin/puroks').then(({ data }) => setPuroks(data.data));
  }, []);

  const runSearch = async () => {
    if (!search.trim()) return setResults([]);
    const { data } = await client.get('/admin/residents', { params: { search, per_page: 5 } });
    setResults(data.data);
  };

  const addMember = (resident: Resident) => {
    if (members.some((m) => m.resident_id === resident.id)) return;
    setMembers((m) => [...m, { resident_id: resident.id, resident_name: resident.full_name, relationship_to_head: '', is_head: m.length === 0 }]);
    setSearch('');
    setResults([]);
  };

  const removeMember = (residentId: number) => {
    setMembers((m) => m.filter((x) => x.resident_id !== residentId));
  };

  const setHead = (residentId: number) => {
    setMembers((m) => m.map((x) => ({ ...x, is_head: x.resident_id === residentId })));
  };

  const setRelationship = (residentId: number, value: string) => {
    setMembers((m) => m.map((x) => (x.resident_id === residentId ? { ...x, relationship_to_head: value } : x)));
  };

  const submit = async () => {
    setError(null);
    if (!members.some((m) => m.is_head)) {
      setError('A household must have exactly one head.');
      return;
    }
    setSubmitting(true);
    try {
      const { data } = await client.post('/admin/households', {
        household_number: householdNumber,
        purok_id: purokId,
        members: members.map((m) => ({
          resident_id: m.resident_id,
          is_head: m.is_head,
          relationship_to_head: m.is_head ? 'head' : m.relationship_to_head,
        })),
      });
      navigate(`/households/${data.data.id}`);
    } catch (err: any) {
      const messages = err?.response?.data?.errors
        ? Object.values(err.response.data.errors).flat().join(' ')
        : err?.response?.data?.message ?? 'Could not save household.';
      setError(messages);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div>
      <h2>New Household</h2>
      {error && <div className="form-error">{error}</div>}

      <div className="form-grid">
        <label>
          Household Number *
          <input value={householdNumber} onChange={(e) => setHouseholdNumber(e.target.value)} required />
        </label>
        <label>
          Purok *
          <select value={purokId} onChange={(e) => setPurokId(e.target.value)} required>
            <option value="">— select —</option>
            {puroks.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      <h3>Members (exactly one head required)</h3>
      <div className="search-bar">
        <input
          placeholder="Search resident to add…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), runSearch())}
        />
        <button type="button" onClick={runSearch}>
          Search
        </button>
      </div>
      {results.length > 0 && (
        <ul className="search-results">
          {results.map((r) => (
            <li key={r.id}>
              {r.full_name} ({r.resident_number}){' '}
              <button type="button" onClick={() => addMember(r)}>
                Add
              </button>
            </li>
          ))}
        </ul>
      )}

      <table className="data-table">
        <thead>
          <tr>
            <th>Resident</th>
            <th>Relationship to Head</th>
            <th>Head?</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {members.map((m) => (
            <tr key={m.resident_id}>
              <td>{m.resident_name}</td>
              <td>
                {m.is_head ? (
                  'head'
                ) : (
                  <input
                    value={m.relationship_to_head}
                    onChange={(e) => setRelationship(m.resident_id, e.target.value)}
                    placeholder="e.g. spouse, child"
                  />
                )}
              </td>
              <td>
                <input type="radio" name="head" checked={m.is_head} onChange={() => setHead(m.resident_id)} />
              </td>
              <td>
                <button type="button" onClick={() => removeMember(m.resident_id)}>
                  Remove
                </button>
              </td>
            </tr>
          ))}
          {members.length === 0 && (
            <tr>
              <td colSpan={4}>No members added yet.</td>
            </tr>
          )}
        </tbody>
      </table>

      <div className="form-actions">
        <button className="button-primary" onClick={submit} disabled={submitting}>
          {submitting ? 'Saving…' : 'Create Household'}
        </button>
      </div>
    </div>
  );
}
