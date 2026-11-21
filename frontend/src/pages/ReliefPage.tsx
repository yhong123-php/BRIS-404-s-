import { Fragment, useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import client from '../api/client';
import { apiError, DocumentHeader, formatDate, formatDateTime, humanize, StatusBadge, PageSkeleton, Breadcrumb, EmptyRow, Drawer } from '../components/ui';
import { useAuth } from '../context/AuthContext';
import { useDialog } from '../context/DialogContext';
import { useToast } from '../context/ToastContext';

/**
 * B2 — Relief distribution management and B4 — Smart relief allocation
 * (SDD §4.9, §4.19). The Secretary prepares a draft and its beneficiary list
 * (duplicate flags + priority ranking); the Captain approves the plan, which
 * freezes the list and opens claims; one claim per household.
 */

interface Distribution {
  id: number;
  title: string;
  description: string | null;
  source_of_funds: string | null;
  goods_description: string;
  quantity_available: number;
  quantity_per_beneficiary: number;
  target_rule: { type: string; purok_ids?: number[]; classification_type_id?: number };
  priority_weights: Record<string, number> | null;
  allocation_mode: 'priority' | 'per_purok';
  start_date: string;
  end_date: string;
  venue: string | null;
  status: string;
  listed_count?: number;
  claimed_count?: number;
  approver?: { full_name: string } | null;
  approved_at?: string | null;
}
interface Beneficiary {
  id: number;
  household_id: number;
  household_number: string;
  purok_id: number | null;
  purok: string | null;
  purok_rank: number | null;
  representative: string | null;
  priority_score: number;
  score_breakdown: Record<string, number> | null;
  rank: number | null;
  duplicate_flag: string[] | null;
  inclusion: string;
  is_excluded: boolean;
  within_allocation: boolean;
  override_reason: string | null;
  entitlement_quantity: number;
  status: string;
  claims: { id: number; claimant_name: string; relationship_to_head: string | null; claimed_at: string; released_by: string | null; reversed_at: string | null; reversal_reason: string | null }[];
}

/** The life of a distribution, shown above the list with how many are at each stage. */
const RELIEF_STEPS = [
  { status: 'draft', title: 'Draft', text: 'Secretary sets the goods and rule; eligible households are listed automatically.' },
  { status: 'pending_approval', title: 'Awaiting approval', text: 'Captain reviews the list. Approval freezes it.' },
  { status: 'open', title: 'Open for claims', text: 'Release by purok. One claim per household.' },
  { status: 'closed', title: 'Closed', text: 'Unclaimed households are recorded for follow-up.' },
];

export function ReliefListPage() {
  const { hasPermission } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();
  const [items, setItems] = useState<Distribution[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving] = useState(false);
  const [puroks, setPuroks] = useState<{ id: number; name: string }[]>([]);
  const [types, setTypes] = useState<{ id: number; name: string }[]>([]);
  const [form, setForm] = useState({
    title: '', goods_description: '', quantity_available: '', quantity_per_beneficiary: '1', source_of_funds: '', venue: '',
    start_date: new Date().toISOString().slice(0, 10), end_date: new Date().toISOString().slice(0, 10), rule: 'priority', purok_ids: [] as number[], classification_type_id: '',
    allocation_mode: 'per_purok',
  });

  const load = () => client.get('/admin/relief').then(({ data }) => setItems(data.data));
  useEffect(() => {
    load();
    client.get('/admin/puroks').then(({ data }) => setPuroks(data.data));
    client.get('/admin/classification-types').then(({ data }) => setTypes(data.data)).catch(() => undefined);
  }, []);

  const create = async (e: FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      const { data } = await client.post('/admin/relief', {
        ...form,
        quantity_available: Number(form.quantity_available),
        quantity_per_beneficiary: Number(form.quantity_per_beneficiary),
        target_rule: { type: form.rule, purok_ids: form.rule === 'puroks' ? form.purok_ids : undefined, classification_type_id: form.rule === 'classification' ? Number(form.classification_type_id) : undefined },
      });
      toast.success('Eligible households listed automatically and grouped by purok. Review the list, then submit it for the Captain’s approval.');
      navigate(`/relief/${data.data.id}`);
    } catch (err) {
      toast.error(apiError(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div>
      <div className="page-header">
        <h2>Relief Distribution</h2>
        {hasPermission('relief.create') && <button className="button-primary" onClick={() => setShowForm(true)}>+ New distribution</button>}
      </div>
      <ol className="steps" aria-label="How a relief distribution moves">
        {RELIEF_STEPS.map((step, i) => {
          const count = items.filter((d) => d.status === step.status).length;
          return (
            <li key={step.status} className={count > 0 ? 'has-items' : ''}>
              <span className="step-num">{i + 1}</span>
              <span className="step-body">
                <span className="step-title">{step.title} <span className="step-count">{count}</span></span>
                <span className="step-text">{step.text}</span>
              </span>
            </li>
          );
        })}
      </ol>

      <Drawer open={showForm} title="New distribution" onClose={() => setShowForm(false)} description="The system lists the eligible households automatically, ranks them by priority, and groups them by purok. Nothing is released until the Barangay Captain approves the list.">
        <form className="drawer-form" onSubmit={create}>
          <div className="form-grid">
            <label>Title *<input required value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="e.g. Typhoon response — food packs" /></label>
            <label>Goods *<input required value={form.goods_description} onChange={(e) => setForm({ ...form, goods_description: e.target.value })} /></label>
            <label>Quantity available *<input required type="number" min={1} value={form.quantity_available} onChange={(e) => setForm({ ...form, quantity_available: e.target.value })} /></label>
            <label>Per household *<input required type="number" min={1} value={form.quantity_per_beneficiary} onChange={(e) => setForm({ ...form, quantity_per_beneficiary: e.target.value })} /></label>
            <label>Source of funds<input value={form.source_of_funds} onChange={(e) => setForm({ ...form, source_of_funds: e.target.value })} /></label>
            <label>Venue<input value={form.venue} onChange={(e) => setForm({ ...form, venue: e.target.value })} /></label>
            <label>Start date *<input required type="date" value={form.start_date} onChange={(e) => setForm({ ...form, start_date: e.target.value })} /></label>
            <label>End date *<input required type="date" value={form.end_date} onChange={(e) => setForm({ ...form, end_date: e.target.value })} /></label>
            <label>
              Eligible households *
              <select value={form.rule} onChange={(e) => setForm({ ...form, rule: e.target.value })}>
                <option value="priority">Households with vulnerable members (senior, PWD, solo parent, child, pregnant, indigent)</option>
                <option value="all">All households</option>
                <option value="puroks">Households in selected puroks</option>
                <option value="classification">Households with a member in a sector</option>
              </select>
            </label>
            {form.rule === 'puroks' && (
              <label>
                Puroks
                <select multiple value={form.purok_ids.map(String)} onChange={(e) => setForm({ ...form, purok_ids: Array.from(e.target.selectedOptions).map((o) => Number(o.value)) })}>
                  {puroks.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
              </label>
            )}
            {form.rule === 'classification' && (
              <label>
                Sector
                <select required value={form.classification_type_id} onChange={(e) => setForm({ ...form, classification_type_id: e.target.value })}>
                  <option value="">Select…</option>
                  {types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
              </label>
            )}
            <label>
              If supply is short *
              <select value={form.allocation_mode} onChange={(e) => setForm({ ...form, allocation_mode: e.target.value })}>
                <option value="per_purok">Fair share per purok (every purok gets its portion)</option>
                <option value="priority">Highest priority households in the whole barangay first</option>
              </select>
            </label>
          </div>
          <div className="form-actions"><button type="submit" className="button-primary" disabled={saving}>{saving ? 'Listing eligible households…' : 'Create and list eligible households'}</button></div>
        </form>
      </Drawer>

      <table className="data-table">
        <thead><tr><th>Distribution</th><th>Goods</th><th>Dates</th><th>Households allocated</th><th>Claimed</th><th>Status</th></tr></thead>
        <tbody>
          {items.map((d) => (
            <tr key={d.id}>
              <td><Link to={`/relief/${d.id}`}>{d.title}</Link></td>
              <td>{d.goods_description} ({d.quantity_available})</td>
              <td>{formatDate(d.start_date)} – {formatDate(d.end_date)}</td>
              <td>{d.listed_count}</td>
              <td>{d.claimed_count}</td>
              <td><StatusBadge status={d.status} /></td>
            </tr>
          ))}
          {items.length === 0 && <EmptyRow colSpan={6} message="No relief distributions yet." action={hasPermission('relief.create') && !showForm ? <button className="button-sm button-primary" onClick={() => setShowForm(true)}>+ New distribution</button> : undefined} />}
        </tbody>
      </table>
    </div>
  );
}

interface PurokSummary { purok_id: number | null; purok: string; eligible: number; served: number; waitlisted: number; excluded: number; quantity_needed: number; claimed: number }
interface DetailData { distribution: Distribution; capacity: number; by_purok: PurokSummary[]; beneficiaries: Beneficiary[] }

export function ReliefDetailPage() {
  const { id } = useParams();
  const { hasPermission } = useAuth();
  const toast = useToast();
  const dialog = useDialog();
  const [data, setData] = useState<DetailData | null>(null);
  const [search, setSearch] = useState('');
  const [purokFilter, setPurokFilter] = useState('');
  const [printing, setPrinting] = useState<string | null>(null);

  const load = useCallback(() => client.get(`/admin/relief/${id}`).then(({ data }) => setData(data.data)), [id]);
  useEffect(() => { load(); }, [load]);

  const call = async (fn: () => Promise<{ data: { data: DetailData } }>, ok: string) => {
    try {
      const res = await fn();
      setData(res.data.data);
      toast.success(ok);
    } catch (err) {
      toast.error(apiError(err));
    }
  };


  const reasonForm = (title: string, label: string, submitLabel: string) =>
    dialog.form({ title, fields: [{ name: 'reason', label, type: 'textarea', required: true }], submitLabel });

  const addHousehold = async () => {
    const res = await client.get('/admin/households', { params: { per_page: 500 } }).catch(() => null);
    const listed = new Set(data?.beneficiaries.map((b) => b.household_id));
    const options = ((res?.data.data ?? []) as { id: number; household_number: string; purok?: { name: string } | null }[])
      .filter((h) => !listed.has(h.id))
      .map((h) => ({ value: String(h.id), label: `${h.household_number}${h.purok?.name ? ` — ${h.purok.name}` : ''}` }));
    if (options.length === 0) return dialog.notice({ title: 'Add household', message: 'Every household is already on this list.' });
    const v = await dialog.form({
      title: 'Add a household manually',
      description: 'For a household the eligibility rule did not list but that should receive relief.',
      fields: [
        { name: 'household_id', label: 'Household', type: 'select', required: true, options },
        { name: 'reason', label: 'Reason for including it', type: 'textarea', required: true },
      ],
      submitLabel: 'Add household',
    });
    if (v) call(() => client.post(`/admin/relief/${id}/households`, { household_id: Number(v.household_id), reason: v.reason }), 'Household added.');
  };

  const approve = async () => {
    const ok = await dialog.confirm({
      title: 'Approve relief list',
      message: 'Approve this list? It will be frozen and claims opened. Later changes to the registry will not change it.',
      confirmLabel: 'Approve and open',
    });
    if (ok) call(() => client.post(`/admin/relief/${id}/decide`, { approve: true }), 'List approved — distribution open.');
  };

  const returnToSecretary = async () => {
    const v = await reasonForm('Return list to the Secretary', 'What needs to change?', 'Return list');
    if (v) call(() => client.post(`/admin/relief/${id}/decide`, { approve: false, reason: v.reason }), 'List returned to draft.');
  };

  const closeDistribution = async () => {
    const ok = await dialog.confirm({
      title: 'Close distribution',
      message: 'Close this distribution? Unclaimed entitlements will be reported and the summary locked against further edits.',
      confirmLabel: 'Close distribution', tone: 'danger',
    });
    if (ok) call(() => client.post(`/admin/relief/${id}/close`), 'Distribution closed.');
  };

  const toggleExclusion = async (b: Beneficiary) => {
    const v = await reasonForm(
      b.is_excluded ? `Include household ${b.household_number} again` : `Exclude household ${b.household_number}`,
      b.is_excluded ? 'Reason for including it again' : 'Reason for excluding it',
      b.is_excluded ? 'Include' : 'Exclude',
    );
    if (v) call(() => client.post(`/admin/relief-beneficiaries/${b.id}/exclude`, { is_excluded: !b.is_excluded, reason: v.reason }), 'Updated.');
  };

  const recordClaim = async (b: Beneficiary) => {
    const v = await dialog.form({
      title: `Record claim — household ${b.household_number}`,
      description: `${b.entitlement_quantity} × ${data?.distribution.goods_description ?? 'goods'}${b.purok ? ` · ${b.purok}` : ''}`,
      fields: [
        { name: 'claimant_name', label: 'Received by', required: true, defaultValue: b.representative ?? '' },
        { name: 'relationship_to_head', label: 'Relationship to the household head', type: 'select', required: true, defaultValue: 'head', options: [
          { value: 'head', label: 'Household head' }, { value: 'spouse', label: 'Spouse' }, { value: 'son', label: 'Son' }, { value: 'daughter', label: 'Daughter' },
          { value: 'parent', label: 'Parent' }, { value: 'sibling', label: 'Sibling' }, { value: 'relative', label: 'Other relative' }, { value: 'authorized representative', label: 'Authorized representative' },
        ] },
      ],
      submitLabel: 'Record claim',
    });
    if (v) call(() => client.post(`/admin/relief-beneficiaries/${b.id}/claim`, v), 'Claim recorded.');
  };

  const reverseClaim = async (claimId: number) => {
    const v = await reasonForm('Reverse claim', 'Reason for reversing this claim', 'Reverse claim');
    if (v) call(() => client.post(`/admin/relief-claims/${claimId}/reverse`, { reason: v.reason }), 'Claim reversed.');
  };

  /** Prints the release list of one purok (or all puroks), with a signature column. */
  const printPurok = (purok: string) => {
    setPrinting(purok);
    setTimeout(() => { window.print(); setPrinting(null); }, 50);
  };

  if (!data) return <PageSkeleton />;
  const d = data.distribution;
  const draft = d.status === 'draft';
  const open = d.status === 'open';
  const perPurok = d.allocation_mode === 'per_purok';
  const purokKey = (b: Beneficiary) => b.purok ?? 'No purok';
  const visible = data.beneficiaries.filter(
    (b) => (!purokFilter || purokKey(b) === purokFilter) && (!search || `${b.household_number} ${b.representative ?? ''}`.toLowerCase().includes(search.toLowerCase())),
  );
  const groups = visible.reduce<Record<string, Beneficiary[]>>((acc, b) => {
    (acc[purokKey(b)] ??= []).push(b);
    return acc;
  }, {});
  const flagged = data.beneficiaries.filter((b) => b.duplicate_flag?.length).length;
  const totals = data.by_purok.reduce((t, r) => ({ eligible: t.eligible + r.eligible, served: t.served + r.served, qty: t.qty + r.quantity_needed, claimed: t.claimed + r.claimed }), { eligible: 0, served: 0, qty: 0, claimed: 0 });

  // Printable release list for one purok (rendered only while printing).
  if (printing) {
    const rows = data.beneficiaries.filter((b) => !b.is_excluded && b.within_allocation && (printing === 'all' || purokKey(b) === printing));
    const byPurok = rows.reduce<Record<string, Beneficiary[]>>((acc, b) => { (acc[purokKey(b)] ??= []).push(b); return acc; }, {});
    return (
      <div className="print-page" style={{ maxWidth: 'none' }}>
        {Object.entries(byPurok).map(([purok, list]) => (
          <div key={purok} style={{ pageBreakAfter: 'always' }}>
            <DocumentHeader title="RELIEF RELEASE LIST" />
            <p><strong>{d.title}</strong> · {d.goods_description} ({list[0]?.entitlement_quantity ?? d.quantity_per_beneficiary} per household) · <strong>{purok}</strong> · {formatDate(d.start_date)} – {formatDate(d.end_date)}{d.venue ? ` · ${d.venue}` : ''}</p>
            <table className="data-table">
              <thead><tr><th>#</th><th>Household</th><th>Representative</th><th>Qty</th><th>Received by (name)</th><th>Signature</th><th>Date</th></tr></thead>
              <tbody>
                {list.map((b, i) => (
                  <tr key={b.id}><td>{i + 1}</td><td>{b.household_number}</td><td>{b.representative ?? ''}</td><td>{b.entitlement_quantity}</td><td style={{ width: '22%' }}></td><td style={{ width: '18%' }}></td><td style={{ width: '10%' }}></td></tr>
                ))}
              </tbody>
            </table>
            <p className="doc-meta">{list.length} household(s) · {list.reduce((s, b) => s + b.entitlement_quantity, 0)} unit(s) of {d.goods_description}. Approved by {d.approver?.full_name ?? '—'}.</p>
          </div>
        ))}
      </div>
    );
  }

  return (
    <div>
      <Breadcrumb items={[{ label: 'Relief Distribution', to: '/relief' }, { label: d.title }]} />
      <div className="page-header">
        <h2>{d.title} <StatusBadge status={d.status} /></h2>
        <div className="row-actions">
          {draft && hasPermission('f3.prepare') && <button onClick={() => call(() => client.post(`/admin/relief/${id}/generate`), 'List refreshed from the latest records.')}>Refresh list</button>}
          {draft && hasPermission('relief.create') && (
            <button onClick={addHousehold}>
              Add household manually
            </button>
          )}
          {draft && hasPermission('f3.prepare') && <button className="button-primary" onClick={() => call(() => client.post(`/admin/relief/${id}/submit`), 'List submitted for the Captain’s approval.')}>Submit for approval</button>}
          {d.status === 'pending_approval' && hasPermission('f3.approve') && (
            <>
              <button className="button-primary" onClick={approve}>Approve list</button>
              <button onClick={returnToSecretary}>Return to Secretary</button>
            </>
          )}
          {open && hasPermission('f3.approve') && <button onClick={closeDistribution}>Close distribution</button>}
          {(open || d.status === 'closed' || d.status === 'pending_approval') && <button onClick={() => printPurok('all')}>Print release lists (all puroks)</button>}
        </div>
      </div>

      <div className="stat-grid">
        <div className="stat-tile"><div className="stat-tile-label">Goods</div><div className="stat-tile-value" style={{ fontSize: '1.1rem' }}>{d.goods_description}</div></div>
        <div className="stat-tile"><div className="stat-tile-label">Eligible households</div><div className="stat-tile-value">{totals.eligible}</div></div>
        <div className="stat-tile"><div className="stat-tile-label">Supply covers</div><div className="stat-tile-value">{data.capacity}</div></div>
        <div className="stat-tile"><div className="stat-tile-label">Households served</div><div className="stat-tile-value">{totals.served}</div></div>
        <div className="stat-tile"><div className="stat-tile-label">Claimed</div><div className="stat-tile-value">{totals.claimed}</div></div>
      </div>

      <div className="callout">
        <strong>Allocation:</strong>{' '}
        {perPurok ? 'Fair share per purok — each purok gets at least one, the rest is shared by number of eligible households; ranked by priority within each purok.' : 'Highest priority households in the whole barangay first.'}
        {draft && hasPermission('relief.create') && (
          <select style={{ marginLeft: '0.5rem' }} value={d.allocation_mode} onChange={(e) => call(() => client.patch(`/admin/relief/${id}`, { allocation_mode: e.target.value }), 'Allocation updated.')}>
            <option value="per_purok">Fair share per purok</option>
            <option value="priority">Barangay-wide priority</option>
          </select>
        )}
        <br />
        <strong>Priority weights:</strong> {Object.entries(d.priority_weights ?? {}).map(([k, v]) => `${k.replace(/_/g, ' ')} ${v > 0 ? '+' : ''}${v}`).join(' · ')}.
        {flagged > 0 && <> <strong>{flagged}</strong> household(s) flagged as possible duplicates across programs — review them; they are not excluded automatically.</>}
        {d.approver && <> Approved by {d.approver.full_name}.</>}
      </div>

      <h3>By purok</h3>
      <div className="table-scroll">
        <table className="data-table">
          <thead><tr><th>Purok</th><th>Eligible</th><th>Served</th><th>Waitlist</th><th>Excluded</th><th>Goods needed</th><th>Claimed</th><th></th></tr></thead>
          <tbody>
            {data.by_purok.map((r) => (
              <tr key={r.purok} style={purokFilter === r.purok ? { fontWeight: 600 } : undefined}>
                <td>{r.purok}</td><td>{r.eligible}</td><td>{r.served}</td><td>{r.waitlisted}</td><td>{r.excluded}</td><td>{r.quantity_needed}</td><td>{r.claimed}</td>
                <td>
                  <div className="row-actions">
                    <button onClick={() => setPurokFilter(purokFilter === r.purok ? '' : r.purok)}>{purokFilter === r.purok ? 'Show all' : 'Show list'}</button>
                    {r.served > 0 && !draft && <button onClick={() => printPurok(r.purok)}>Print release list</button>}
                  </div>
                </td>
              </tr>
            ))}
            {data.by_purok.length > 0 && <tr style={{ fontWeight: 600 }}><td>Total</td><td>{totals.eligible}</td><td>{totals.served}</td><td></td><td></td><td>{totals.qty}</td><td>{totals.claimed}</td><td></td></tr>}
            {data.by_purok.length === 0 && <EmptyRow colSpan={8} message="No eligible households found. Check the eligibility rule, or that households and classifications are recorded." />}
          </tbody>
        </table>
      </div>

      <div className="search-bar" style={{ marginTop: '1rem' }}>
        <select value={purokFilter} onChange={(e) => setPurokFilter(e.target.value)}>
          <option value="">All puroks</option>
          {data.by_purok.map((r) => <option key={r.purok} value={r.purok}>{r.purok}</option>)}
        </select>
        <input placeholder="Search household # or representative…" value={search} onChange={(e) => setSearch(e.target.value)} style={{ flex: 1 }} />
      </div>

      <div className="table-scroll">
        <table className="data-table">
          <thead><tr><th>{perPurok ? 'Rank in purok' : 'Rank'}</th><th>Household</th><th>Representative</th><th>Score</th><th>Duplicate flags</th><th>Allocation</th><th>Status / claim</th><th></th></tr></thead>
          <tbody>
            {Object.entries(groups).map(([purok, list]) => (
              <Fragment key={purok}>
                <tr><td colSpan={8} style={{ background: 'color-mix(in srgb, var(--primary) 12%, var(--surface))', fontWeight: 600 }}>{purok} — {list.filter((b) => b.within_allocation && !b.is_excluded).length} of {list.filter((b) => !b.is_excluded).length} eligible served</td></tr>
                {list.map((b) => {
                  const claim = b.claims.find((c) => !c.reversed_at);
                  return (
                    <tr key={b.id} style={b.is_excluded ? { opacity: 0.55 } : undefined}>
                      <td>{(perPurok ? b.purok_rank : b.rank) ?? '—'}{perPurok && b.rank ? <div className="note">#{b.rank} overall</div> : null}</td>
                      <td>{b.household_number}{b.inclusion === 'manual' ? <div className="note">manual</div> : null}</td>
                      <td>{b.representative ?? '—'}</td>
                      <td title={Object.entries(b.score_breakdown ?? {}).map(([k, v]) => `${k}: ${v}`).join('\n')}>{b.priority_score}</td>
                      <td>{b.duplicate_flag?.length ? b.duplicate_flag.map((f) => <div key={f} className="note">⚠ {f}</div>) : '—'}</td>
                      <td>{b.is_excluded ? <>Excluded<div className="note">{b.override_reason}</div></> : b.within_allocation ? 'Served' : 'Waitlist'}</td>
                      <td>
                        <StatusBadge status={b.status} />
                        {claim && <div className="note">{claim.claimant_name}{claim.relationship_to_head ? ` (${humanize(claim.relationship_to_head)})` : ''} · {formatDateTime(claim.claimed_at)} · by {claim.released_by}</div>}
                      </td>
                      <td>
                        <div className="row-actions">
                          {draft && hasPermission('relief.create') && (
                            <button onClick={() => toggleExclusion(b)}>
                              {b.is_excluded ? 'Include' : 'Exclude'}
                            </button>
                          )}
                          {open && !claim && b.within_allocation && !b.is_excluded && hasPermission('relief.record_claim') && (
                            <button onClick={() => recordClaim(b)}>
                              Record claim
                            </button>
                          )}
                          {open && claim && hasPermission('relief.reverse_claim') && (
                            <button onClick={() => reverseClaim(claim.id)}>Reverse claim</button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </Fragment>
            ))}
            {visible.length === 0 && <EmptyRow colSpan={8} message="No households match." />}
          </tbody>
        </table>
      </div>
    </div>
  );
}
