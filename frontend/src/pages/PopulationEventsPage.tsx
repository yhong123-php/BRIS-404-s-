import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import client from '../api/client';
import { ResidentPicker, apiError, formatDate, type ResidentOption } from '../components/ui';
import { useAuth } from '../context/AuthContext';
import { useDialog } from '../context/DialogContext';
import { useToast } from '../context/ToastContext';

/**
 * B3 — Population monitoring: births, deaths, transfers (SRS §3.2.2.9, SDD §4.10).
 * Each event is dated and applies its effect to the registry (SDD Table 4.2).
 */

type EventType = 'birth' | 'death' | 'transfer_in' | 'transfer_out';
interface EventRow {
  id: number; event_type: EventType; event_date: string; reported_date: string; resident: { id: number; name: string };
  purok: string | null; document_type: string | null; document_number: string | null; previous_address: string | null;
  destination_address: string | null; cause_or_reason: string | null; recorded_by: string | null;
}

const LABELS: Record<EventType, string> = { birth: 'Birth', death: 'Death', transfer_in: 'Transfer in', transfer_out: 'Transfer out' };
const EFFECTS: Record<EventType, string> = {
  birth: 'Creates the newborn’s resident record, adds the newborn to the mother’s household, and records the parent relationships.',
  death: 'Sets the record to deceased, closes household membership and active classifications. If the person was household head, promote a new head.',
  transfer_in: 'Reactivates a former resident, or registers a new one, records the previous address, and assigns purok and household.',
  transfer_out: 'Sets the record to transferred out with the destination, and closes household membership.',
};

export default function PopulationEventsPage() {
  const { hasPermission } = useAuth();
  const toast = useToast();
  const dialog = useDialog();
  const [rows, setRows] = useState<EventRow[]>([]);
  const [filter, setFilter] = useState('');
  const [type, setType] = useState<EventType | null>(null);

  const load = useCallback(() => client.get('/admin/population-events', { params: { event_type: filter || undefined } }).then(({ data }) => setRows(data.data)), [filter]);
  useEffect(() => { load(); }, [load]);

  return (
    <div>
      <div className="page-header">
        <h2>Births, Deaths &amp; Transfers</h2>
        {hasPermission('population_events.record') && (
          <div className="row-actions">
            {(Object.keys(LABELS) as EventType[]).map((t) => (
              <button key={t} className={type === t ? 'button-primary' : ''} onClick={() => setType(type === t ? null : t)}>+ {LABELS[t]}</button>
            ))}
          </div>
        )}
      </div>

      {type && <EventForm type={type} onSaved={(warning) => { setType(null); load(); toast.success('Event recorded and the registry updated.'); if (warning) dialog.notice({ title: 'Action needed', message: warning, tone: 'warning' }); }} />}

      <div className="search-bar">
        <select value={filter} onChange={(e) => setFilter(e.target.value)}>
          <option value="">All events</option>
          {(Object.keys(LABELS) as EventType[]).map((t) => <option key={t} value={t}>{LABELS[t]}</option>)}
        </select>
      </div>
      <table className="data-table">
        <thead><tr><th>Date</th><th>Event</th><th>Resident</th><th>Purok</th><th>Details</th><th>Document</th><th>Recorded by</th></tr></thead>
        <tbody>
          {rows.map((e) => (
            <tr key={e.id}>
              <td>{formatDate(e.event_date)}</td>
              <td>{LABELS[e.event_type]}</td>
              <td><Link to={`/residents/${e.resident.id}`}>{e.resident.name}</Link></td>
              <td>{e.purok ?? '—'}</td>
              <td>{e.previous_address ? `From: ${e.previous_address}` : e.destination_address ? `To: ${e.destination_address}` : e.cause_or_reason ?? '—'}</td>
              <td>{[e.document_type, e.document_number].filter(Boolean).join(' ') || '—'}</td>
              <td>{e.recorded_by ?? '—'}</td>
            </tr>
          ))}
          {rows.length === 0 && <tr><td colSpan={7}>No population events recorded.</td></tr>}
        </tbody>
      </table>
    </div>
  );
}

function EventForm({ type, onSaved }: { type: EventType; onSaved: (warning: string | null) => void }) {
  const toast = useToast();
  const [resident, setResident] = useState<ResidentOption | null>(null);
  const [mother, setMother] = useState<ResidentOption | null>(null);
  const [father, setFather] = useState<ResidentOption | null>(null);
  const [puroks, setPuroks] = useState<{ id: number; name: string }[]>([]);
  const [f, setF] = useState<Record<string, string>>({ event_date: new Date().toISOString().slice(0, 10), sex: 'male', civil_status: 'single' });
  const set = (k: string) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value });
  useEffect(() => { client.get('/admin/puroks').then(({ data }) => setPuroks(data.data)); }, []);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const body: Record<string, unknown> = { ...f, event_type: type };
    if (type === 'death' || type === 'transfer_out') body.resident_id = resident?.id;
    if (type === 'transfer_in' && resident) body.resident_id = resident.id;
    if (type === 'birth') { body.mother_resident_id = mother?.id; body.father_resident_id = father?.id; }
    Object.keys(body).forEach((k) => body[k] === '' && delete body[k]);
    try {
      const { data } = await client.post('/admin/population-events', body);
      onSaved(data.warning ?? null);
    } catch (err) {
      toast.error(apiError(err));
    }
  };

  return (
    <form className="panel" onSubmit={submit}>
      <h4>Record {LABELS[type].toLowerCase()}</h4>
      <p className="note">{EFFECTS[type]}</p>
      <div className="form-grid">
        <label>Date of event *<input type="date" required value={f.event_date} onChange={set('event_date')} /></label>
        {(type === 'death' || type === 'transfer_out') && <label style={{ gridColumn: '1 / -1' }}>Resident *<ResidentPicker value={resident} onChange={setResident} /></label>}
        {type === 'transfer_in' && (
          <label style={{ gridColumn: '1 / -1' }}>
            Returning former resident (leave empty to register a new resident)
            <ResidentPicker value={resident} onChange={setResident} status={null} placeholder="Search former residents…" />
          </label>
        )}
        {(type === 'birth' || (type === 'transfer_in' && !resident)) && (
          <>
            <label>Last name *<input required value={f.last_name ?? ''} onChange={set('last_name')} /></label>
            <label>First name *<input required value={f.first_name ?? ''} onChange={set('first_name')} /></label>
            <label>Middle name<input value={f.middle_name ?? ''} onChange={set('middle_name')} /></label>
            <label>Sex *<select value={f.sex} onChange={set('sex')}><option value="male">Male</option><option value="female">Female</option></select></label>
          </>
        )}
        {type === 'transfer_in' && !resident && (
          <>
            <label>Birth date *<input type="date" required value={f.birth_date ?? ''} onChange={set('birth_date')} /></label>
            <label>Civil status *<select value={f.civil_status} onChange={set('civil_status')}>{['single', 'married', 'widowed', 'separated', 'divorced', 'annulled'].map((c) => <option key={c} value={c}>{c[0].toUpperCase() + c.slice(1)}</option>)}</select></label>
          </>
        )}
        {type === 'birth' && (
          <>
            <label>Place of birth<input value={f.birth_place ?? ''} onChange={set('birth_place')} /></label>
            <label style={{ gridColumn: '1 / -1' }}>Mother<ResidentPicker value={mother} onChange={setMother} /></label>
            <label style={{ gridColumn: '1 / -1' }}>Father<ResidentPicker value={father} onChange={setFather} /></label>
          </>
        )}
        {(type === 'transfer_in' || (type === 'birth' && !mother)) && (
          <label>Purok<select value={f.purok_id ?? ''} onChange={set('purok_id')}><option value="">—</option>{puroks.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
        )}
        {type === 'transfer_in' && <label>Previous address<input value={f.previous_address ?? ''} onChange={set('previous_address')} /></label>}
        {type === 'transfer_in' && <label>Household ID (optional)<input value={f.household_id ?? ''} onChange={set('household_id')} /></label>}
        {type === 'transfer_out' && <label>Destination address *<input required value={f.destination_address ?? ''} onChange={set('destination_address')} /></label>}
        {(type === 'death' || type === 'transfer_out' || type === 'transfer_in') && <label>{type === 'death' ? 'Cause of death' : 'Reason'}<input value={f.cause_or_reason ?? ''} onChange={set('cause_or_reason')} /></label>}
        <label>Supporting document<input value={f.document_type ?? ''} onChange={set('document_type')} placeholder={type === 'birth' ? 'Certificate of Live Birth' : type === 'death' ? 'Death certificate' : 'Certificate of transfer'} /></label>
        <label>Document number<input value={f.document_number ?? ''} onChange={set('document_number')} /></label>
      </div>
      <div className="form-actions"><button type="submit" className="button-primary">Record event</button></div>
    </form>
  );
}
