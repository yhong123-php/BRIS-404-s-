import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import client from '../api/client';
import type { Purok, Resident } from '../api/types';

interface DuplicateCandidate {
  // Note: the backend nests this ResidentResource inside a plain array
  // response, so Laravel does not apply its usual {"data": ...} envelope
  // here (that only happens when a resource is the top-level controller
  // return value) — this is the raw resident object, not resident.data.
  resident: Resident;
  similarity_percent: number;
  same_birth_date: boolean;
}

const initialForm = {
  last_name: '',
  first_name: '',
  middle_name: '',
  suffix: '',
  sex: 'male',
  birth_date: '',
  civil_status: 'single',
  purok_id: '',
  contact_number: '',
  occupation: '',
};

export default function ResidentCreatePage() {
  const [form, setForm] = useState(initialForm);
  const [puroks, setPuroks] = useState<Purok[]>([]);
  const [candidates, setCandidates] = useState<DuplicateCandidate[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const navigate = useNavigate();

  useEffect(() => {
    client.get('/admin/puroks').then(({ data }) => setPuroks(data.data));
  }, []);

  const update = (field: keyof typeof initialForm) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    setForm((f) => ({ ...f, [field]: e.target.value }));
  };

  const submit = async (acknowledged: boolean) => {
    setError(null);
    setSubmitting(true);
    try {
      const payload = {
        ...form,
        purok_id: form.purok_id || null,
        duplicate_check_acknowledged: acknowledged,
      };
      const { data } = await client.post('/admin/residents', payload);
      navigate(`/residents/${data.data.id}`);
    } catch (err: any) {
      if (err?.response?.status === 409) {
        setCandidates(err.response.data.candidates);
      } else {
        const messages = err?.response?.data?.errors
          ? Object.values(err.response.data.errors).flat().join(' ')
          : err?.response?.data?.message ?? 'Could not save resident.';
        setError(messages);
      }
    } finally {
      setSubmitting(false);
    }
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    setCandidates(null);
    submit(false);
  };

  if (candidates) {
    return (
      <div>
        <h2>Possible Duplicate Residents Found</h2>
        <p>
          The system found {candidates.length} existing resident(s) that may match "{form.first_name} {form.last_name}".
          Review before continuing (SDD §4.3).
        </p>
        <table className="data-table">
          <thead>
            <tr>
              <th>Resident #</th>
              <th>Name</th>
              <th>Birth Date</th>
              <th>Similarity</th>
            </tr>
          </thead>
          <tbody>
            {candidates.map((c) => (
              <tr key={c.resident.id}>
                <td>{c.resident.resident_number}</td>
                <td>{c.resident.full_name}</td>
                <td>{c.resident.birth_date}</td>
                <td>{c.similarity_percent}%{c.same_birth_date ? ' (same DOB)' : ''}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="form-actions">
          <button onClick={() => setCandidates(null)}>Cancel, let me edit</button>
          <button className="button-primary" disabled={submitting} onClick={() => submit(true)}>
            {submitting ? 'Saving…' : 'This is a distinct resident — register anyway'}
          </button>
        </div>
      </div>
    );
  }

  return (
    <div>
      <h2>Register Resident</h2>
      <form onSubmit={handleSubmit} className="resident-form">
        {error && <div className="form-error">{error}</div>}
        <div className="form-grid">
          <label>
            Last Name *
            <input value={form.last_name} onChange={update('last_name')} required />
          </label>
          <label>
            First Name *
            <input value={form.first_name} onChange={update('first_name')} required />
          </label>
          <label>
            Middle Name
            <input value={form.middle_name} onChange={update('middle_name')} />
          </label>
          <label>
            Suffix
            <input value={form.suffix} onChange={update('suffix')} />
          </label>
          <label>
            Sex *
            <select value={form.sex} onChange={update('sex')}>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
          </label>
          <label>
            Birth Date *
            <input type="date" value={form.birth_date} onChange={update('birth_date')} required />
          </label>
          <label>
            Civil Status *
            <select value={form.civil_status} onChange={update('civil_status')}>
              <option value="single">Single</option>
              <option value="married">Married</option>
              <option value="widowed">Widowed</option>
              <option value="separated">Separated</option>
              <option value="divorced">Divorced</option>
              <option value="annulled">Annulled</option>
            </select>
          </label>
          <label>
            Purok
            <select value={form.purok_id} onChange={update('purok_id')}>
              <option value="">— none —</option>
              {puroks.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            Contact Number
            <input value={form.contact_number} onChange={update('contact_number')} />
          </label>
          <label>
            Occupation
            <input value={form.occupation} onChange={update('occupation')} />
          </label>
        </div>
        <div className="form-actions">
          <button type="submit" className="button-primary" disabled={submitting}>
            {submitting ? 'Checking…' : 'Register Resident'}
          </button>
        </div>
      </form>
    </div>
  );
}
