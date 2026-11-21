import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import client from '../api/client';
import { EDUCATION_LEVELS, EMPLOYMENT_STATUS_LABELS, INCOME_BRACKETS, formatIncome, type Purok, type Resident } from '../api/types';
import { Breadcrumb, PageSkeleton, StatusBadge, apiError, formatDate, humanize } from '../components/ui';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';

/**
 * A1 — Resident registration (SDD §4.3, Table 3.1). The full resident record in
 * one form: personal, address and residency, contact, livelihood and education,
 * household membership (step 2), sector classifications, and the signed consent
 * form (step 6). Duplicate detection runs before the record is saved (steps 3–4).
 */

interface DuplicateCandidate {
  // The backend nests this resource inside a plain array, so there is no {"data": ...} envelope.
  resident: Resident;
  similarity_percent: number;
  same_birth_date: boolean;
}
interface ClassificationType { id: number; name: string; requires_reference_number: boolean; requires_document: boolean; is_age_derived: boolean; min_age: number | null; is_active?: boolean }
interface HouseholdOption { id: number; household_number: string; house_number: string | null; street: string | null }
interface ConsentPurpose { id: number; code: string; name: string; description: string | null; is_active: boolean }

const RELATIONSHIPS = ['head', 'spouse', 'son', 'daughter', 'father', 'mother', 'grandchild', 'grandparent', 'sibling', 'relative', 'boarder', 'helper', 'other'];
const RELIGIONS = ['Roman Catholic', 'Iglesia ni Cristo', 'Seventh-day Adventist', 'Baptist', 'Born Again Christian', 'Islam', 'Jehovah’s Witness', 'Aglipayan', 'Other', 'None'];

const initialForm = {
  last_name: '', first_name: '', middle_name: '', suffix: '',
  sex: 'male', birth_date: '', birth_place: '', civil_status: 'single', citizenship: 'Filipino', religion: '',
  purok_id: '', house_number: '', street: '', residency_start_date: '', voter_status: '',
  contact_number: '', email: '',
  occupation: '', employment_status: '', monthly_income_bracket: '', educational_attainment: '',
  remarks: '',
};

function ageFrom(birthDate: string): number | null {
  if (!birthDate) return null;
  const b = new Date(birthDate);
  if (Number.isNaN(b.getTime())) return null;
  const now = new Date();
  let age = now.getFullYear() - b.getFullYear();
  if (now.getMonth() < b.getMonth() || (now.getMonth() === b.getMonth() && now.getDate() < b.getDate())) age--;
  return age;
}

function Fieldset({ title, note, children }: { title: string; note?: string; children: ReactNode }) {
  return (
    <fieldset className="panel" style={{ border: '1px solid var(--border)' }}>
      <legend style={{ fontWeight: 600, padding: '0 0.4rem' }}>{title}</legend>
      {note && <p className="note" style={{ marginTop: 0 }}>{note}</p>}
      <div className="form-grid">{children}</div>
    </fieldset>
  );
}

export default function ResidentCreatePage() {
  const { hasPermission } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();
  // /residents/:id/edit reuses this form: same fields, saved with PATCH. Household,
  // sectors and consent are managed on the resident's page, so they're hidden here.
  const { id: editId } = useParams();
  const editing = !!editId;
  const [loaded, setLoaded] = useState(!editing);
  const [editName, setEditName] = useState('');
  const [form, setForm] = useState(initialForm);
  const [puroks, setPuroks] = useState<Purok[]>([]);
  const [households, setHouseholds] = useState<HouseholdOption[]>([]);
  const [household, setHousehold] = useState({ household_id: '', relationship_to_head: '' });
  const [types, setTypes] = useState<ClassificationType[]>([]);
  const [sectors, setSectors] = useState<Record<number, { checked: boolean; reference_number: string; document_path: string }>>({});
  const [purposes, setPurposes] = useState<ConsentPurpose[]>([]);
  const [consents, setConsents] = useState<Record<number, boolean>>({});
  const [consentForm, setConsentForm] = useState('');
  const [candidates, setCandidates] = useState<DuplicateCandidate[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!editId) return;
    client.get(`/admin/residents/${editId}`).then(({ data }) => {
      const r = data.data as Resident & Record<string, unknown>;
      const next = { ...initialForm };
      (Object.keys(initialForm) as (keyof typeof initialForm)[]).forEach((k) => {
        const v = r[k];
        next[k] = v === null || v === undefined ? '' : String(v);
      });
      next.birth_date = next.birth_date.slice(0, 10);
      next.residency_start_date = next.residency_start_date.slice(0, 10);
      next.purok_id = String(r.purok_id ?? r.purok?.id ?? '');
      setForm(next);
      setEditName(r.full_name);
      setLoaded(true);
    }).catch((err) => { setError(apiError(err, 'Could not load the resident.')); setLoaded(true); });
  }, [editId]);

  useEffect(() => {
    client.get('/admin/puroks').then(({ data }) => setPuroks(data.data));
    if (editing) return;
    if (hasPermission('classifications.manage')) client.get('/admin/classification-types').then(({ data }) => setTypes(data.data.filter((t: ClassificationType) => t.is_active !== false))).catch(() => undefined);
    if (hasPermission('g1.record')) client.get('/admin/consent-purposes').then(({ data }) => setPurposes(data.data.filter((p: ConsentPurpose) => p.is_active))).catch(() => undefined);
  }, [hasPermission, editing]);

  // Households of the chosen purok, for SDD §4.3 step 2.
  useEffect(() => {
    if (editing || !form.purok_id || !hasPermission('households.manage')) return;
    client.get('/admin/households', { params: { purok_id: form.purok_id, per_page: 200 } }).then(({ data }) => setHouseholds(data.data)).catch(() => setHouseholds([]));
  }, [form.purok_id, hasPermission, editing]);

  const age = useMemo(() => ageFrom(form.birth_date), [form.birth_date]);
  const update = (field: keyof typeof initialForm) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [field]: e.target.value }));
  const setSector = (id: number, patch: Partial<{ checked: boolean; reference_number: string; document_path: string }>) =>
    setSectors((s) => ({ ...s, [id]: { ...(s[id] ?? { checked: false, reference_number: '', document_path: '' }), ...patch } }));

  // Senior Citizen is suggested from age (SDD §4.7: derived, but recorded explicitly).
  const suggested = types.filter((t) => t.is_age_derived && t.min_age !== null && age !== null && age >= t.min_age);
  const purokHouseholds = form.purok_id ? households : [];

  /** After the resident is saved: household, sectors, consent. Problems are reported, not fatal. */
  const finishRegistration = async (resident: Resident) => {
    const problems: string[] = [];
    if (household.household_id) {
      try {
        await client.post(`/admin/households/${household.household_id}/members`, {
          resident_id: resident.id, relationship_to_head: household.relationship_to_head || null, is_head: household.relationship_to_head === 'head',
        });
      } catch (err) {
        problems.push(`Household: ${apiError(err)}`);
      }
    }
    for (const t of types) {
      const s = sectors[t.id];
      if (!s?.checked) continue;
      try {
        await client.post(`/admin/residents/${resident.id}/classifications`, {
          classification_type_id: t.id, reference_number: s.reference_number || null, document_path: s.document_path || null,
        });
      } catch (err) {
        problems.push(`${t.name}: ${apiError(err)}`);
      }
    }
    for (const p of purposes) {
      if (!consents[p.id]) continue;
      try {
        await client.post(`/admin/residents/${resident.id}/consents`, { consent_purpose_id: p.id, status: 'granted', form_path: consentForm || null });
      } catch (err) {
        problems.push(`Consent (${p.name}): ${apiError(err)}`);
      }
    }
    if (problems.length) {
      toast.error(`Resident registered, but some details need attention on the resident's page: ${problems.join(' · ')}`);
    } else {
      toast.success(`${resident.full_name} registered.`);
    }
    navigate(`/residents/${resident.id}`);
  };

  const submit = async (acknowledged: boolean) => {
    setError(null);
    // Checks that are easier to fix before saving.
    for (const t of types) {
      const s = sectors[t.id];
      if (s?.checked && t.requires_reference_number && !s.reference_number.trim()) return setError(`${t.name} needs a reference number (e.g. ID number).`);
      if (s?.checked && t.requires_document && !s.document_path.trim()) return setError(`${t.name} needs a supporting document reference.`);
    }
    if (household.household_id && !household.relationship_to_head) return setError('Choose the relationship to the household head.');
    if (Object.values(consents).some(Boolean) && !consentForm.trim()) return setError('Enter the reference of the signed consent form.');

    setSubmitting(true);
    if (editing) {
      try {
        const payload = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]));
        await client.patch(`/admin/residents/${editId}`, payload);
        toast.success('Resident record updated.');
        navigate(`/residents/${editId}`);
      } catch (err) {
        setError(apiError(err, 'Could not save the changes.'));
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } finally {
        setSubmitting(false);
      }
      return;
    }
    try {
      const payload = Object.fromEntries(Object.entries({ ...form, duplicate_check_acknowledged: acknowledged }).map(([k, v]) => [k, v === '' ? null : v]));
      const { data } = await client.post('/admin/residents', payload);
      await finishRegistration(data.data);
    } catch (err: unknown) {
      const res = (err as { response?: { status?: number; data?: { candidates?: DuplicateCandidate[] } } }).response;
      if (res?.status === 409 && res.data?.candidates) {
        setCandidates(res.data.candidates);
      } else {
        setError(apiError(err, 'Could not save the resident.'));
        window.scrollTo({ top: 0, behavior: 'smooth' });
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

  if (!loaded) return <PageSkeleton cards={0} />;

  if (candidates) {
    const purokName = puroks.find((p) => String(p.id) === form.purok_id)?.name ?? '—';
    const typedName = [form.last_name + ',', form.first_name, form.middle_name].filter(Boolean).join(' ');
    return (
      <div>
        <Breadcrumb items={[{ label: 'Residents & Households', to: '/residents' }, { label: 'Register resident' }]} />
        <div className="page-header"><h2>Possible duplicate found</h2></div>
        <div className="callout">
          {candidates.length === 1 ? 'An existing resident looks' : `${candidates.length} existing residents look`} like the person you are registering.
          Compare them before saving, so the same person isn’t registered twice.
        </div>

        <div className="compare-grid">
          <div className="compare-card entered">
            <h4>You entered <span className="badge badge-info">New</span></h4>
            <dl className="detail-grid">
              <dt>Name</dt><dd>{typedName}</dd>
              <dt>Birth date</dt><dd>{formatDate(form.birth_date)}</dd>
              <dt>Sex</dt><dd>{humanize(form.sex)}</dd>
              <dt>Purok</dt><dd>{purokName}</dd>
            </dl>
          </div>
          {candidates.map((c) => {
            const samePurok = String(c.resident.purok_id ?? c.resident.purok?.id ?? '') === form.purok_id;
            return (
              <div key={c.resident.id} className="compare-card">
                <h4>
                  <a href={`/residents/${c.resident.id}`} target="_blank" rel="noreferrer">{c.resident.resident_number}</a>
                  <span className={`badge ${c.similarity_percent >= 90 ? 'badge-bad' : 'badge-warn'}`}>{c.similarity_percent}% match</span>
                </h4>
                <dl className="detail-grid">
                  <dt>Name</dt><dd><span className={c.similarity_percent >= 90 ? 'match' : ''}>{c.resident.full_name}</span></dd>
                  <dt>Birth date</dt><dd><span className={c.same_birth_date ? 'match' : ''}>{formatDate(c.resident.birth_date)}</span>{c.same_birth_date && ' · same'}</dd>
                  <dt>Sex</dt><dd><span className={c.resident.sex === form.sex ? 'match' : ''}>{humanize(c.resident.sex)}</span></dd>
                  <dt>Purok</dt><dd><span className={samePurok && c.resident.purok ? 'match' : ''}>{c.resident.purok?.name ?? '—'}</span></dd>
                  <dt>Status</dt><dd><StatusBadge status={c.resident.status} /></dd>
                </dl>
              </div>
            );
          })}
        </div>
        <p className="note"><span className="match">Highlighted</span> details are the same as what you entered. Open a record number to see the full record in a new tab.</p>

        <div className="form-actions">
          <button onClick={() => setCandidates(null)}>Go back and edit</button>
          <button className="button-primary" disabled={submitting} onClick={() => submit(true)}>
            {submitting ? 'Saving…' : 'This is a different person — register'}
          </button>
        </div>
      </div>
    );
  }

  return (
    <div>
      <Breadcrumb items={[{ label: 'Residents & Households', to: '/residents' }, ...(editing ? [{ label: editName, to: `/residents/${editId}` }, { label: 'Edit' }] : [{ label: 'Register resident' }])]} />
      <div className="page-header">
        <h2>{editing ? `Edit resident — ${editName}` : 'Register Resident'}</h2>
      </div>
      <p className="note">
        {editing
          ? 'Every change is recorded in the audit log. Household, sector classifications and consent are updated from the resident’s page.'
          : 'Fields marked * are required. Everything else can be added later from the resident’s page.'}
      </p>
      <form onSubmit={handleSubmit} className="resident-form">
        {error && <div className="form-error">{error}</div>}

        <Fieldset title="1. Personal information">
          <label>Last name *<input value={form.last_name} onChange={update('last_name')} required /></label>
          <label>First name *<input value={form.first_name} onChange={update('first_name')} required /></label>
          <label>Middle name<input value={form.middle_name} onChange={update('middle_name')} /></label>
          <label>
            Suffix
            <select value={form.suffix} onChange={update('suffix')}>
              <option value="">—</option>
              {['Jr.', 'Sr.', 'II', 'III', 'IV'].map((s) => <option key={s}>{s}</option>)}
            </select>
          </label>
          <label>
            Sex *
            <select value={form.sex} onChange={update('sex')}><option value="male">Male</option><option value="female">Female</option></select>
          </label>
          <label>
            Birth date *
            <input type="date" value={form.birth_date} onChange={update('birth_date')} max={new Date().toISOString().slice(0, 10)} required />
            {age !== null && <span className="note">Age: {age}</span>}
          </label>
          <label>Birthplace<input value={form.birth_place} onChange={update('birth_place')} placeholder="Town / city, province" /></label>
          <label>
            Civil status *
            <select value={form.civil_status} onChange={update('civil_status')}>
              {['single', 'married', 'widowed', 'separated', 'divorced', 'annulled'].map((c) => <option key={c} value={c}>{c[0].toUpperCase() + c.slice(1)}</option>)}
            </select>
          </label>
          <label>Citizenship<input value={form.citizenship} onChange={update('citizenship')} /></label>
          <label>
            Religion
            <select value={form.religion} onChange={update('religion')}>
              <option value="">—</option>
              {RELIGIONS.map((r) => <option key={r}>{r}</option>)}
            </select>
          </label>
        </Fieldset>

        <Fieldset title="2. Address and residency" note="A resident may be registered before being assigned to a household.">
          <label>
            Purok *
            <select value={form.purok_id} onChange={(e) => { update('purok_id')(e); setHousehold({ household_id: '', relationship_to_head: '' }); }} required>
              <option value="">Select purok…</option>
              {puroks.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </label>
          <label>House number<input value={form.house_number} onChange={update('house_number')} /></label>
          <label>Street / sitio<input value={form.street} onChange={update('street')} /></label>
          <label>
            Resident of the barangay since
            <input type="date" value={form.residency_start_date} onChange={update('residency_start_date')} min={form.birth_date || undefined} max={new Date().toISOString().slice(0, 10)} />
          </label>
          <label>
            Voter status
            <select value={form.voter_status} onChange={update('voter_status')}>
              <option value="">—</option>
              <option value="registered">Registered voter</option>
              <option value="not_registered">Not registered</option>
            </select>
          </label>
          {!editing && hasPermission('households.manage') && (
            <>
              <label>
                Household
                <select value={household.household_id} onChange={(e) => setHousehold({ ...household, household_id: e.target.value })} disabled={!form.purok_id}>
                  <option value="">{form.purok_id ? (purokHouseholds.length ? 'Not yet assigned' : 'No households in this purok yet') : 'Choose the purok first'}</option>
                  {purokHouseholds.map((h) => <option key={h.id} value={h.id}>{h.household_number}{h.house_number || h.street ? ` — ${[h.house_number, h.street].filter(Boolean).join(' ')}` : ''}</option>)}
                </select>
              </label>
              {household.household_id && (
                <label>
                  Relationship to household head *
                  <select value={household.relationship_to_head} onChange={(e) => setHousehold({ ...household, relationship_to_head: e.target.value })}>
                    <option value="">Select…</option>
                    {RELATIONSHIPS.map((r) => <option key={r} value={r}>{r === 'head' ? 'Is the household head' : r[0].toUpperCase() + r.slice(1)}</option>)}
                  </select>
                </label>
              )}
            </>
          )}
        </Fieldset>

        <Fieldset title="3. Contact">
          <label>Mobile / phone number<input value={form.contact_number} onChange={update('contact_number')} placeholder="09171234567" inputMode="tel" /></label>
          <label>Email<input type="email" value={form.email} onChange={update('email')} /></label>
        </Fieldset>

        <Fieldset title="4. Livelihood and education" note="Used for employment, skills, and education reports and the AI assistant.">
          <label>Occupation<input value={form.occupation} onChange={update('occupation')} placeholder="e.g. Farmer, Vendor, Teacher" /></label>
          <label>
            Employment status
            <select value={form.employment_status} onChange={update('employment_status')}>
              <option value="">— not recorded —</option>
              {Object.entries(EMPLOYMENT_STATUS_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </label>
          <label>
            Monthly household income
            <select value={form.monthly_income_bracket} onChange={update('monthly_income_bracket')}>
              <option value="">— not recorded —</option>
              {INCOME_BRACKETS.map((b) => <option key={b} value={b}>{formatIncome(b)}</option>)}
            </select>
          </label>
          <label>
            Highest educational attainment
            <select value={form.educational_attainment} onChange={update('educational_attainment')}>
              <option value="">— not recorded —</option>
              {Object.entries(EDUCATION_LEVELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </label>
        </Fieldset>

        {!editing && types.length > 0 && (
          <Fieldset title="5. Sector classifications" note="Tick only sectors supported by a document or ID. Senior Citizen is suggested from age but still needs the OSCA ID.">
            {types.map((t) => {
              const s = sectors[t.id];
              const isSuggested = suggested.some((x) => x.id === t.id);
              return (
                <div key={t.id} style={{ display: 'flex', flexDirection: 'column', gap: '0.3rem', fontSize: '0.85rem' }}>
                  <label style={{ flexDirection: 'row', alignItems: 'center', gap: '0.4rem' }}>
                    <input type="checkbox" checked={!!s?.checked} onChange={(e) => setSector(t.id, { checked: e.target.checked })} />
                    {t.name}
                    {isSuggested && !s?.checked && <span className="badge badge-info">suggested (age {age})</span>}
                  </label>
                  {s?.checked && t.requires_reference_number && <input placeholder="Reference / ID number *" value={s.reference_number} onChange={(e) => setSector(t.id, { reference_number: e.target.value })} />}
                  {s?.checked && t.requires_document && <input placeholder="Supporting document *" value={s.document_path} onChange={(e) => setSector(t.id, { document_path: e.target.value })} />}
                </div>
              );
            })}
          </Fieldset>
        )}

        {!editing && purposes.length > 0 && (
          <Fieldset title="6. Consent (Data Privacy Act)" note="Record only what the resident opted into on the signed consent form. Registration itself does not depend on consent.">
            {purposes.map((p) => (
              <label key={p.id} style={{ flexDirection: 'row', alignItems: 'flex-start', gap: '0.4rem' }}>
                <input type="checkbox" checked={!!consents[p.id]} onChange={(e) => setConsents({ ...consents, [p.id]: e.target.checked })} style={{ marginTop: '0.2rem' }} />
                <span><strong>{p.name}</strong>{p.description ? <span className="note"> — {p.description}</span> : null}</span>
              </label>
            ))}
            {Object.values(consents).some(Boolean) && (
              <label>Signed consent form reference *<input value={consentForm} onChange={(e) => setConsentForm(e.target.value)} placeholder="e.g. scanned file name or form no." /></label>
            )}
          </Fieldset>
        )}

        <Fieldset title={editing ? "5. Remarks" : "7. Remarks"}>
          <label style={{ gridColumn: '1 / -1' }}>
            Notes
            <textarea rows={3} value={form.remarks} onChange={update('remarks')} />
          </label>
        </Fieldset>

        <div className="form-actions">
          <button type="button" onClick={() => navigate(-1)}>Cancel</button>
          <button type="submit" className="button-primary" disabled={submitting}>
            {editing ? (submitting ? 'Saving…' : 'Save changes') : submitting ? 'Checking for duplicates…' : 'Register resident'}
          </button>
        </div>
      </form>
    </div>
  );
}
