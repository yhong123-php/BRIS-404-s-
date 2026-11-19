import { Fragment, useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import client from '../api/client';
import { EMPLOYMENT_STATUS_LABELS, type EmploymentStatus } from '../api/types';
import { apiError, DocumentHeader, formatDate, humanize, StatusBadge, EmptyRow } from '../components/ui';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';

/**
 * F2 — Livelihood and job opportunity matching (SRS §3.2.1.13, SDD §4.18).
 * Posting an opportunity automatically shortlists the capable residents —
 * active, within the age range, in the target puroks, with a verified required
 * skill, and consenting to opportunity matching — segregated by purok, with the
 * slots shared fairly per purok or barangay-wide.
 */

interface Opportunity {
  id: number; title: string; type: string; provider: string | null; required_skills: number[]; required_skill_names: string[];
  slots: number; closing_date: string | null; status: string; matches_count?: number;
  target_purok_ids: number[] | null; min_age: number; max_age: number; allocation_mode: 'priority' | 'per_purok';
}
interface Match {
  id: number; rank: number; purok_rank: number | null; within_slots: boolean; score: number; outcome: string;
  resident_id: number; resident_name: string; age: number | null; employment_status: EmploymentStatus | null;
  purok_id: number | null; purok: string | null; contact_number: string | null;
}
interface PurokRow { purok_id: number | null; purok: string; capable: number; within_slots: number; contacted: number; hired: number }
interface Detail { opportunity: Opportunity; target_purok_names: string[]; by_purok: PurokRow[]; matches: Match[] }

export default function OpportunitiesPage() {
  const { hasPermission } = useAuth();
  const toast = useToast();
  const canManage = hasPermission('f2.manage');
  const [items, setItems] = useState<Opportunity[]>([]);
  const [skills, setSkills] = useState<{ id: number; name: string }[]>([]);
  const [puroks, setPuroks] = useState<{ id: number; name: string }[]>([]);
  const [selected, setSelected] = useState<Detail | null>(null);
  const [purokFilter, setPurokFilter] = useState('');
  const [printing, setPrinting] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({
    title: '', type: 'job', provider: '', slots: '1', closing_date: '', required_skills: [] as number[],
    target_purok_ids: [] as number[], min_age: '18', max_age: '64', allocation_mode: 'per_purok',
  });

  const load = useCallback(() => client.get('/admin/opportunities').then(({ data }) => setItems(data.data)), []);
  useEffect(() => {
    load();
    client.get('/admin/skill-types').then(({ data }) => setSkills(data.data)).catch(() => undefined);
    client.get('/admin/puroks').then(({ data }) => setPuroks(data.data)).catch(() => undefined);
  }, [load]);

  const open = (id: number) => client.get(`/admin/opportunities/${id}`).then(({ data }) => { setSelected(data.data); setPurokFilter(''); });
  const call = async (fn: () => Promise<{ data: { data: Detail } }>, ok: string) => {
    try { const res = await fn(); setSelected(res.data.data); toast.success(ok); load(); } catch (err) { toast.error(apiError(err)); }
  };

  const create = async (e: FormEvent) => {
    e.preventDefault();
    if (form.required_skills.length === 0) return toast.error('Select at least one required skill.');
    try {
      const { data } = await client.post('/admin/opportunities', {
        ...form, slots: Number(form.slots), min_age: Number(form.min_age), max_age: Number(form.max_age), closing_date: form.closing_date || null,
      });
      toast.success('Opportunity posted. Capable residents were shortlisted and grouped by purok.');
      setShowForm(false);
      load();
      open(data.data.id);
    } catch (err) {
      toast.error(apiError(err));
    }
  };

  const printPurok = (purok: string) => {
    setPrinting(purok);
    setTimeout(() => { window.print(); setPrinting(null); }, 50);
  };

  // Printable contact list per purok (rendered only while printing).
  if (printing && selected) {
    const rows = selected.matches.filter((m) => printing === 'all' || (m.purok ?? 'No purok') === printing);
    const groups = rows.reduce<Record<string, Match[]>>((acc, m) => { (acc[m.purok ?? 'No purok'] ??= []).push(m); return acc; }, {});
    return (
      <div className="print-page" style={{ maxWidth: 'none' }}>
        {Object.entries(groups).map(([purok, list]) => (
          <div key={purok} style={{ pageBreakAfter: 'always' }}>
            <DocumentHeader title="OPPORTUNITY SHORTLIST" />
            <p><strong>{selected.opportunity.title}</strong> ({selected.opportunity.type}){selected.opportunity.provider ? ` · ${selected.opportunity.provider}` : ''} · <strong>{purok}</strong> · Skills: {selected.opportunity.required_skill_names.join(', ')}</p>
            <table className="data-table">
              <thead><tr><th>#</th><th>Resident</th><th>Age</th><th>Contact</th><th>Skill match</th><th>Within slots</th><th>Outcome / remarks</th></tr></thead>
              <tbody>
                {list.map((m) => (
                  <tr key={m.id}><td>{m.purok_rank}</td><td>{m.resident_name}</td><td>{m.age ?? ''}</td><td>{m.contact_number ?? ''}</td><td>{m.score}%</td><td>{m.within_slots ? 'Yes' : 'Waitlist'}</td><td style={{ width: '25%' }}></td></tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}
      </div>
    );
  }

  const visible = selected ? selected.matches.filter((m) => !purokFilter || (m.purok ?? 'No purok') === purokFilter) : [];
  const groups = visible.reduce<Record<string, Match[]>>((acc, m) => { (acc[m.purok ?? 'No purok'] ??= []).push(m); return acc; }, {});
  const perPurok = selected?.opportunity.allocation_mode === 'per_purok';

  return (
    <div>
      <div className="page-header">
        <h2>Livelihood &amp; Job Opportunities</h2>
        {canManage && <button className="button-primary" onClick={() => setShowForm((s) => !s)}>{showForm ? 'Close' : '+ Post opportunity'}</button>}
      </div>
      <p className="note">
        Posting an opportunity automatically shortlists the <strong>capable</strong> residents — active, within the age range, in the target puroks, holding a <strong>verified</strong> required skill, and with consent for opportunity matching — grouped by purok. Residents with more of the required skills come first; among equals, unemployed residents come first.
      </p>

      {showForm && (
        <form className="panel" onSubmit={create}>
          <div className="form-grid">
            <label>Title *<input required value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></label>
            <label>Type *<select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}><option value="job">Job</option><option value="livelihood">Livelihood</option><option value="training">Training</option></select></label>
            <label>Provider<input value={form.provider} onChange={(e) => setForm({ ...form, provider: e.target.value })} /></label>
            <label>Slots *<input type="number" min={1} required value={form.slots} onChange={(e) => setForm({ ...form, slots: e.target.value })} /></label>
            <label>Closing date<input type="date" value={form.closing_date} onChange={(e) => setForm({ ...form, closing_date: e.target.value })} /></label>
            <label>Age from *<input type="number" min={15} max={100} required value={form.min_age} onChange={(e) => setForm({ ...form, min_age: e.target.value })} /></label>
            <label>Age to *<input type="number" min={15} max={100} required value={form.max_age} onChange={(e) => setForm({ ...form, max_age: e.target.value })} /></label>
            <label>
              Required skills *
              <select multiple size={6} value={form.required_skills.map(String)} onChange={(e) => setForm({ ...form, required_skills: Array.from(e.target.selectedOptions).map((o) => Number(o.value)) })}>
                {skills.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </label>
            <label>
              Target puroks (none selected = all)
              <select multiple size={6} value={form.target_purok_ids.map(String)} onChange={(e) => setForm({ ...form, target_purok_ids: Array.from(e.target.selectedOptions).map((o) => Number(o.value)) })}>
                {puroks.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </label>
            <label>
              Share the slots *
              <select value={form.allocation_mode} onChange={(e) => setForm({ ...form, allocation_mode: e.target.value })}>
                <option value="per_purok">Fair share per purok</option>
                <option value="priority">Best-qualified in the whole barangay</option>
              </select>
            </label>
          </div>
          <div className="form-actions"><button type="submit" className="button-primary">Post and shortlist capable residents</button></div>
        </form>
      )}

      <table className="data-table">
        <thead><tr><th>Opportunity</th><th>Type</th><th>Required skills</th><th>Slots</th><th>Closes</th><th>Shortlisted</th><th>Status</th></tr></thead>
        <tbody>
          {items.map((o) => (
            <tr key={o.id} onClick={() => open(o.id)} style={{ cursor: 'pointer' }}>
              <td><strong>{o.title}</strong>{o.provider ? <div className="note">{o.provider}</div> : null}</td>
              <td>{humanize(o.type)}</td>
              <td>{o.required_skill_names.join(', ')}</td>
              <td>{o.slots}</td>
              <td>{formatDate(o.closing_date)}</td>
              <td>{o.matches_count}</td>
              <td><StatusBadge status={o.status} /></td>
            </tr>
          ))}
          {items.length === 0 && <EmptyRow colSpan={7} message="No opportunities posted yet." action={canManage && !showForm ? <button className="button-sm button-primary" onClick={() => setShowForm(true)}>+ Post opportunity</button> : undefined} />}
        </tbody>
      </table>

      {selected && (
        <div className="panel" style={{ marginTop: '1rem' }}>
          <div className="section-head">
            <h4>Shortlist — {selected.opportunity.title}</h4>
            <div className="row-actions">
              {canManage && <button onClick={() => call(() => client.post(`/admin/opportunities/${selected.opportunity.id}/match`), 'Shortlist refreshed from the latest records.')}>Refresh shortlist</button>}
              {canManage && (
                <button onClick={() => call(() => client.patch(`/admin/opportunities/${selected.opportunity.id}`, { status: selected.opportunity.status === 'open' ? 'closed' : 'open' }), 'Updated.')}>
                  {selected.opportunity.status === 'open' ? 'Close opportunity' : 'Reopen'}
                </button>
              )}
              {selected.matches.length > 0 && <button onClick={() => printPurok('all')}>Print lists (all puroks)</button>}
            </div>
          </div>
          <p className="note">
            Ages {selected.opportunity.min_age}–{selected.opportunity.max_age} · Puroks: {selected.target_purok_names.length ? selected.target_purok_names.join(', ') : 'all'} · {selected.opportunity.slots} slot(s), {perPurok ? 'fair share per purok (each purok gets at least one when there are enough slots)' : 'best-qualified in the whole barangay'}
            {canManage && selected.opportunity.status === 'open' && (
              <select style={{ marginLeft: '0.5rem' }} value={selected.opportunity.allocation_mode} onChange={(e) => call(() => client.patch(`/admin/opportunities/${selected.opportunity.id}`, { allocation_mode: e.target.value }), 'Slot sharing updated.')}>
                <option value="per_purok">Fair share per purok</option>
                <option value="priority">Barangay-wide</option>
              </select>
            )}
          </p>

          <table className="data-table">
            <thead><tr><th>Purok</th><th>Capable residents</th><th>Within slots</th><th>Contacted</th><th>Hired</th><th></th></tr></thead>
            <tbody>
              {selected.by_purok.map((r) => (
                <tr key={r.purok} style={purokFilter === r.purok ? { fontWeight: 600 } : undefined}>
                  <td>{r.purok}</td><td>{r.capable}</td><td>{r.within_slots}</td><td>{r.contacted}</td><td>{r.hired}</td>
                  <td>
                    <div className="row-actions">
                      <button onClick={() => setPurokFilter(purokFilter === r.purok ? '' : r.purok)}>{purokFilter === r.purok ? 'Show all' : 'Show list'}</button>
                      <button onClick={() => printPurok(r.purok)}>Print list</button>
                    </div>
                  </td>
                </tr>
              ))}
              {selected.by_purok.length === 0 && <EmptyRow colSpan={6} message="No capable residents: none in the target puroks and age range have these verified skills with consent for opportunity matching." />}
            </tbody>
          </table>

          <div className="table-scroll" style={{ marginTop: '1rem' }}>
            <table className="data-table">
              <thead><tr><th>{perPurok ? 'Rank in purok' : 'Rank'}</th><th>Resident</th><th>Age</th><th>Employment</th><th>Contact</th><th>Skill match</th><th>Slots</th><th>Outcome</th></tr></thead>
              <tbody>
                {Object.entries(groups).map(([purok, list]) => (
                  <Fragment key={purok}>
                    <tr><td colSpan={8} style={{ background: 'color-mix(in srgb, var(--primary) 12%, var(--surface))', fontWeight: 600 }}>{purok} — {list.filter((m) => m.within_slots).length} of {list.length} within slots</td></tr>
                    {list.map((m) => (
                      <tr key={m.id}>
                        <td>{perPurok ? m.purok_rank : m.rank}{perPurok ? <div className="note">#{m.rank} overall</div> : null}</td>
                        <td><Link to={`/residents/${m.resident_id}`}>{m.resident_name}</Link></td>
                        <td>{m.age ?? '—'}</td>
                        <td>{m.employment_status ? EMPLOYMENT_STATUS_LABELS[m.employment_status] : '—'}</td>
                        <td>{m.contact_number ?? '—'}</td>
                        <td>{m.score}%</td>
                        <td>{m.within_slots ? 'Within slots' : 'Waitlist'}</td>
                        <td>
                          {canManage ? (
                            <select value={m.outcome} onChange={(e) => call(() => client.patch(`/admin/opportunity-matches/${m.id}`, { outcome: e.target.value }), 'Outcome recorded.')}>
                              {['shortlisted', 'contacted', 'interested', 'declined', 'hired'].map((o) => <option key={o}>{o}</option>)}
                            </select>
                          ) : <StatusBadge status={m.outcome} />}
                        </td>
                      </tr>
                    ))}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
