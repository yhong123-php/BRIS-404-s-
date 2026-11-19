import { useEffect, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import client from '../api/client';
import { EDUCATION_LEVELS, EMPLOYMENT_STATUS_LABELS, formatIncome, type EmploymentStatus, type Resident, type SocialAssistanceRecord } from '../api/types';
import ResidentServicePanels from '../components/ResidentServicePanels';
import { apiError, formatDate, humanize, PageSkeleton, StatusBadge } from '../components/ui';
import { useDialog } from '../context/DialogContext';
import { useToast } from '../context/ToastContext';
import { useAuth } from '../context/AuthContext';

type ResidentTab = 'profile' | 'sectors' | 'assistance' | 'records';

interface ClassificationRow {
  id: number;
  status: string;
  reference_number: string | null;
  classification_type: { id: number; name: string };
}
interface ClassificationTypeOption {
  id: number;
  name: string;
  requires_reference_number: boolean;
  requires_document: boolean;
}
interface SkillRow {
  id: number;
  is_verified: boolean;
  proficiency_level: string | null;
  skill_type: { id: number; name: string };
}
interface SkillTypeOption {
  id: number;
  name: string;
}
interface AchievementRow {
  id: number;
  title: string;
  issuing_body: string | null;
  date_achieved: string | null;
}
interface Suggestion {
  classification_type_id: number;
  name: string;
  reason: string;
}

export default function ResidentDetailPage() {
  const { id } = useParams();
  const [params, setParams] = useSearchParams();
  const { hasPermission } = useAuth();
  const dialog = useDialog();
  const toast = useToast();
  const [resident, setResident] = useState<Resident | null>(null);
  const [classifications, setClassifications] = useState<ClassificationRow[]>([]);
  const [classificationTypes, setClassificationTypes] = useState<ClassificationTypeOption[]>([]);
  const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
  const [skills, setSkills] = useState<SkillRow[]>([]);
  const [skillTypes, setSkillTypes] = useState<SkillTypeOption[]>([]);
  const [achievements, setAchievements] = useState<AchievementRow[]>([]);
  const [assistance, setAssistance] = useState<SocialAssistanceRecord[]>([]);
  const [refKey, setRefKey] = useState(0);

  useEffect(() => {
    // Reset on a real resident change (not on refKey-only refreshes after an
    // edit): this route doesn't remount when navigating directly between two
    // residents (e.g. browser back/forward through history), so without this
    // the previous resident's classifications/skills/achievements would stay
    // on screen — attributed to the new resident — until each refetch lands.
    setResident(null);
    setClassifications([]);
    setSuggestions([]);
    setSkills([]);
    setAchievements([]);
    setAssistance([]);
  }, [id]);

  useEffect(() => {
    client.get(`/admin/residents/${id}`).then(({ data }) => setResident(data.data));
  }, [id, refKey]);

  useEffect(() => {
    if (!hasPermission('classifications.view')) return;
    client.get(`/admin/residents/${id}/classifications`).then(({ data }) => setClassifications(data.data));
    client.get(`/admin/residents/${id}/classifications/suggestions`).then(({ data }) => setSuggestions(data.data));
    client.get('/admin/classification-types').then(({ data }) => setClassificationTypes(data.data));
  }, [id, hasPermission, refKey]);

  useEffect(() => {
    if (!hasPermission('a6.view')) return;
    client.get(`/admin/residents/${id}/skills`).then(({ data }) => setSkills(data.data));
    client.get('/admin/skill-types').then(({ data }) => setSkillTypes(data.data));
    client.get(`/admin/residents/${id}/achievements`).then(({ data }) => setAchievements(data.data));
  }, [id, hasPermission, refKey]);

  useEffect(() => {
    if (!hasPermission('assistance.view')) return;
    client.get(`/admin/residents/${id}/assistance`).then(({ data }) => setAssistance(data.data));
  }, [id, hasPermission, refKey]);

  const refresh = () => setRefKey((k) => k + 1);

  const updateProfileField = async (field: 'employment_status' | 'educational_attainment', value: string) => {
    await client.patch(`/admin/residents/${id}`, { [field]: value || null });
    refresh();
  };

  const run = async (fn: () => Promise<unknown>, ok: string) => {
    try {
      await fn();
      toast.success(ok);
      refresh();
    } catch (err) {
      toast.error(apiError(err));
    }
  };

  const today = new Date().toISOString().slice(0, 10);

  const restore = async () => {
    const ok = await dialog.confirm({
      title: 'Restore archived record',
      message: <>Restore <strong>{resident?.full_name}</strong> to an active resident record?</>,
      confirmLabel: 'Restore record',
    });
    if (ok) run(() => client.post(`/admin/residents/${id}/restore`), 'Record restored.');
  };

  const addAssistance = async () => {
    const v = await dialog.form({
      title: 'Record social assistance received',
      fields: [
        { name: 'assistance_type', label: 'Type of assistance', type: 'select', required: true, options: [
          { value: 'medical', label: 'Medical' }, { value: 'educational', label: 'Educational' },
          { value: 'livelihood', label: 'Livelihood' }, { value: 'other', label: 'Other' },
        ] },
        { name: 'program_name', label: 'Program name', required: true, placeholder: 'e.g. Medical Aid, Educational Assistance' },
        { name: 'date_received', label: 'Date received', type: 'date', required: true, defaultValue: today, max: today },
        { name: 'description_or_amount', label: 'Amount or description', placeholder: 'e.g. ₱2,000 or 1 sack of rice' },
        { name: 'source', label: 'Source', placeholder: 'e.g. DSWD, Municipal LGU, Barangay' },
      ],
      submitLabel: 'Record assistance',
    });
    if (v) run(() => client.post(`/admin/residents/${id}/assistance`, { ...v, description_or_amount: v.description_or_amount || null, source: v.source || null }), 'Assistance recorded.');
  };

  const assignClassification = async (typeId: number) => {
    const type = classificationTypes.find((t) => t.id === typeId);
    if (!type) return;
    const fields = [
      ...(type.requires_reference_number ? [{ name: 'reference_number', label: 'Reference / ID number', required: true, placeholder: 'e.g. OSCA ID, PWD ID number' }] : []),
      ...(type.requires_document ? [{ name: 'document_path', label: 'Supporting document', required: true, placeholder: 'Scanned file name or document reference' }] : []),
      { name: 'date_granted', label: 'Date granted', type: 'date' as const, defaultValue: today, max: today },
    ];
    const v = await dialog.form({ title: `Assign classification: ${type.name}`, fields, submitLabel: 'Assign' });
    if (v) run(() => client.post(`/admin/residents/${id}/classifications`, { classification_type_id: typeId, ...v }), `${type.name} assigned.`);
  };

  const revokeClassification = async (c: ClassificationRow) => {
    const ok = await dialog.confirm({
      title: 'Revoke classification',
      message: <>Revoke <strong>{c.classification_type.name}</strong> from this resident? It stays in the history as revoked.</>,
      confirmLabel: 'Revoke', tone: 'danger',
    });
    if (ok) run(() => client.post(`/admin/classifications/${c.id}/revoke`), 'Classification revoked.');
  };

  const addSkill = async (skillTypeId: number) => {
    run(() => client.post(`/admin/residents/${id}/skills`, { skill_type_id: skillTypeId }), 'Skill added. Verify it after checking the supporting document.');
  };

  const verifySkill = async (skillId: number) => {
    run(() => client.post(`/admin/skills/${skillId}/verify`), 'Skill verified.');
  };

  const addAchievement = async () => {
    const v = await dialog.form({
      title: 'Record achievement or training',
      fields: [
        { name: 'title', label: 'Title', required: true, placeholder: 'e.g. NC II in Welding, Most Outstanding Farmer' },
        { name: 'category', label: 'Category', type: 'select', options: [
          { value: 'Award', label: 'Award' }, { value: 'Training', label: 'Training' }, { value: 'Certification', label: 'Certification' },
          { value: 'Sports', label: 'Sports' }, { value: 'Academic', label: 'Academic' }, { value: 'Other', label: 'Other' },
        ] },
        { name: 'issuing_body', label: 'Issued by', placeholder: 'e.g. TESDA, DepEd, Municipality of Pilar' },
        { name: 'date_achieved', label: 'Date', type: 'date', max: today },
      ],
      submitLabel: 'Record achievement',
    });
    if (v) run(() => client.post(`/admin/residents/${id}/achievements`, Object.fromEntries(Object.entries(v).map(([k, x]) => [k, x || null]))), 'Achievement recorded.');
  };

  if (!resident) return <PageSkeleton />;

  const tabs: { id: ResidentTab; label: string; show: boolean }[] = [
    { id: 'profile', label: 'Profile', show: true },
    { id: 'sectors', label: 'Sectors & Skills', show: hasPermission('classifications.view') || hasPermission('a6.view') },
    { id: 'assistance', label: 'Assistance', show: hasPermission('assistance.view') },
    { id: 'records', label: 'Consent & Records', show: true },
  ];
  const tab: ResidentTab = tabs.find((t) => t.id === params.get('tab') && t.show)?.id ?? 'profile';

  return (
    <div>
      <div className="page-header">
        <h2>
          {resident.full_name} <span className="muted">({resident.resident_number})</span> <StatusBadge status={resident.status} />
        </h2>
        <div className="row-actions">
          {resident.status === 'archived' && hasPermission('residents.restore') && <button onClick={restore}>Restore record</button>}
          {resident.status === 'active' && hasPermission('residents.edit') && <Link to={`/residents/${resident.id}/edit`} className="button button-primary">Edit resident</Link>}
        </div>
      </div>

      <div className="tabs">
        {tabs.filter((t) => t.show).map((t) => (
          <button key={t.id} className={tab === t.id ? 'active' : ''} onClick={() => setParams(t.id === 'profile' ? {} : { tab: t.id }, { replace: true })}>{t.label}</button>
        ))}
      </div>

      {tab === 'profile' && (
      <section className="detail-section">
        <h3>Personal Information</h3>
        <dl className="detail-grid">
          <dt>Sex</dt>
          <dd>{humanize(resident.sex)}</dd>
          <dt>Birth Date</dt>
          <dd>{formatDate(resident.birth_date)} (age {resident.age})</dd>
          <dt>Birthplace</dt>
          <dd>{resident.birth_place ?? '—'}</dd>
          <dt>Civil Status</dt>
          <dd>{humanize(resident.civil_status)}</dd>
          <dt>Citizenship</dt>
          <dd>{resident.citizenship ?? '—'}</dd>
          <dt>Religion</dt>
          <dd>{resident.religion ?? '—'}</dd>
          <dt>Purok</dt>
          <dd>{resident.purok?.name ?? '—'}</dd>
          <dt>Address</dt>
          <dd>{[resident.house_number, resident.street].filter(Boolean).join(' ') || '—'}</dd>
          <dt>Resident Since</dt>
          <dd>{formatDate(resident.residency_start_date)}</dd>
          <dt>Voter Status</dt>
          <dd>{resident.voter_status === 'registered' ? 'Registered voter' : resident.voter_status === 'not_registered' ? 'Not registered' : '—'}</dd>
          <dt>Contact</dt>
          <dd>{resident.contact_number ?? '—'}</dd>
          <dt>Email</dt>
          <dd>{resident.email ?? '—'}</dd>
          <dt>Occupation</dt>
          <dd>{resident.occupation ?? '—'}</dd>
          <dt>Monthly Household Income</dt>
          <dd>{resident.monthly_income_bracket ? formatIncome(resident.monthly_income_bracket) : '—'}</dd>
          <dt>Employment Status</dt>
          <dd>
            {hasPermission('residents.edit') ? (
              <select value={resident.employment_status ?? ''} onChange={(e) => updateProfileField('employment_status', e.target.value)}>
                <option value="">— not recorded —</option>
                {Object.entries(EMPLOYMENT_STATUS_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            ) : resident.employment_status ? (
              EMPLOYMENT_STATUS_LABELS[resident.employment_status as EmploymentStatus]
            ) : (
              '—'
            )}
          </dd>
          <dt>Highest Education</dt>
          <dd>
            {hasPermission('residents.edit') ? (
              <select value={resident.educational_attainment ?? ''} onChange={(e) => updateProfileField('educational_attainment', e.target.value)}>
                <option value="">— not recorded —</option>
                {Object.entries(EDUCATION_LEVELS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            ) : resident.educational_attainment ? (
              EDUCATION_LEVELS[resident.educational_attainment] ?? resident.educational_attainment
            ) : (
              '—'
            )}
          </dd>
          <dt>Remarks</dt>
          <dd>{resident.remarks || '—'}</dd>
        </dl>
      </section>
      )}

      {tab === 'assistance' && hasPermission('assistance.view') && (
        <section className="detail-section">
          <h3>Social Assistance Received</h3>
          <table className="data-table">
            <thead>
              <tr>
                <th>Type</th>
                <th>Program</th>
                <th>Amount / description</th>
                <th>Date received</th>
                <th>Recorded by</th>
              </tr>
            </thead>
            <tbody>
              {assistance.map((a) => (
                <tr key={a.id}>
                  <td>{humanize(a.assistance_type)}</td>
                  <td>{a.program_name}</td>
                  <td>{a.description_or_amount ?? '—'}</td>
                  <td>{formatDate(a.date_received)}</td>
                  <td>{a.recorded_by ?? '—'}</td>
                </tr>
              ))}
              {assistance.length === 0 && (
                <tr>
                  <td colSpan={5}>No assistance recorded.</td>
                </tr>
              )}
            </tbody>
          </table>
          {hasPermission('assistance.record') && <button onClick={addAssistance}>+ Record assistance</button>}
        </section>
      )}

      {tab === 'sectors' && hasPermission('classifications.view') && (
        <section className="detail-section">
          <h3>Sectoral Classifications (A5)</h3>
          {hasPermission('classifications.manage') && suggestions.length > 0 && (
            <div className="suggestion-box">
              {suggestions.map((s) => (
                <div key={s.classification_type_id} className="suggestion-row">
                  <span>💡 {s.reason}</span>
                  <button onClick={() => assignClassification(s.classification_type_id)}>Assign</button>
                </div>
              ))}
            </div>
          )}
          <table className="data-table">
            <thead>
              <tr>
                <th>Type</th>
                <th>Reference #</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {classifications.map((c) => (
                <tr key={c.id}>
                  <td>{c.classification_type.name}</td>
                  <td>{c.reference_number ?? '—'}</td>
                  <td><StatusBadge status={c.status} /></td>
                  <td>
                    {c.status === 'active' && hasPermission('classifications.manage') && (
                      <button onClick={() => revokeClassification(c)}>Revoke</button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {hasPermission('classifications.manage') && (
            <select defaultValue="" onChange={(e) => e.target.value && assignClassification(Number(e.target.value))}>
              <option value="">+ Assign classification…</option>
              {classificationTypes.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </select>
          )}
        </section>
      )}

      {tab === 'sectors' && hasPermission('a6.view') && (
        <section className="detail-section">
          <h3>Skills &amp; Livelihood (A6)</h3>
          <table className="data-table">
            <thead>
              <tr>
                <th>Skill</th>
                <th>Proficiency</th>
                <th>Verified</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {skills.map((s) => (
                <tr key={s.id}>
                  <td>{s.skill_type.name}</td>
                  <td>{humanize(s.proficiency_level)}</td>
                  <td>{s.is_verified ? <StatusBadge status="verified" /> : <StatusBadge status="unverified" />}</td>
                  <td>{!s.is_verified && hasPermission('a6.manage') && <button onClick={() => verifySkill(s.id)}>Verify</button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {hasPermission('a6.manage') && (
            <select defaultValue="" onChange={(e) => e.target.value && addSkill(Number(e.target.value))}>
              <option value="">+ Add skill…</option>
              {skillTypes.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </select>
          )}

          <h4>Achievements</h4>
          <ul>
            {achievements.map((a) => (
              <li key={a.id}>
                {a.title} {a.issuing_body ? `— ${a.issuing_body}` : ''} {a.date_achieved ? `(${formatDate(a.date_achieved)})` : ''}
              </li>
            ))}
          </ul>
          {hasPermission('a6.manage') && <button onClick={addAchievement}>+ Add achievement</button>}
        </section>
      )}
      {tab === 'records' && <ResidentServicePanels residentId={resident.id} />}
    </div>
  );
}
