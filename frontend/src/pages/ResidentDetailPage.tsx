import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import client from '../api/client';
import { EDUCATION_LEVELS, EMPLOYMENT_STATUS_LABELS, type EmploymentStatus, type Resident, type SocialAssistanceRecord } from '../api/types';
import ResidentServicePanels from '../components/ResidentServicePanels';
import { useAuth } from '../context/AuthContext';

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
  const { hasPermission } = useAuth();
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

  const restore = async () => {
    if (!window.confirm('Restore this archived record to active?')) return;
    await client.post(`/admin/residents/${id}/restore`);
    refresh();
  };

  const addAssistance = async () => {
    const assistance_type = window.prompt('Type of assistance (medical, educational, livelihood, or other):')?.trim().toLowerCase();
    if (!assistance_type) return;
    if (!['medical', 'educational', 'livelihood', 'other'].includes(assistance_type)) {
      window.alert('Type must be medical, educational, livelihood, or other.');
      return;
    }
    const program_name = window.prompt('Program name (e.g. "Medical Aid", "Educational Assistance"):')?.trim();
    if (!program_name) return;
    const date_received = window.prompt('Date received (YYYY-MM-DD):', new Date().toISOString().slice(0, 10))?.trim();
    if (!date_received) return;
    const description_or_amount = window.prompt('Amount or description (optional):')?.trim() || null;
    try {
      await client.post(`/admin/residents/${id}/assistance`, { assistance_type, program_name, date_received, description_or_amount });
      refresh();
    } catch (err: any) {
      window.alert(err?.response?.data?.message ?? 'Could not record the assistance.');
    }
  };

  const assignClassification = async (typeId: number) => {
    const type = classificationTypes.find((t) => t.id === typeId);
    let reference_number: string | undefined;
    if (type?.requires_reference_number) {
      reference_number = window.prompt('Reference number (required):') ?? undefined;
      if (!reference_number) return;
    }
    let document_path: string | undefined;
    if (type?.requires_document) {
      document_path = window.prompt('Document path/filename (required):') ?? undefined;
      if (!document_path) return;
    }
    await client.post(`/admin/residents/${id}/classifications`, {
      classification_type_id: typeId,
      reference_number,
      document_path,
    });
    refresh();
  };

  const revokeClassification = async (classificationId: number) => {
    await client.post(`/admin/classifications/${classificationId}/revoke`);
    refresh();
  };

  const addSkill = async (skillTypeId: number) => {
    await client.post(`/admin/residents/${id}/skills`, { skill_type_id: skillTypeId });
    refresh();
  };

  const verifySkill = async (skillId: number) => {
    await client.post(`/admin/skills/${skillId}/verify`);
    refresh();
  };

  const addAchievement = async () => {
    const title = window.prompt('Achievement title:');
    if (!title) return;
    await client.post(`/admin/residents/${id}/achievements`, { title });
    refresh();
  };

  if (!resident) return <p>Loading…</p>;

  return (
    <div>
      <div className="page-header">
        <h2>
          {resident.full_name} <span className="muted">({resident.resident_number})</span>
        </h2>
        <span className={`status-badge status-${resident.status}`}>{resident.status}</span>
        {resident.status === 'archived' && hasPermission('residents.restore') && <button onClick={restore}>Restore record</button>}
      </div>

      <section className="detail-section">
        <h3>Personal Information</h3>
        <dl className="detail-grid">
          <dt>Sex</dt>
          <dd>{resident.sex}</dd>
          <dt>Birth Date</dt>
          <dd>{resident.birth_date} (age {resident.age})</dd>
          <dt>Birthplace</dt>
          <dd>{resident.birth_place ?? '—'}</dd>
          <dt>Civil Status</dt>
          <dd>{resident.civil_status}</dd>
          <dt>Citizenship</dt>
          <dd>{resident.citizenship ?? '—'}</dd>
          <dt>Religion</dt>
          <dd>{resident.religion ?? '—'}</dd>
          <dt>Purok</dt>
          <dd>{resident.purok?.name ?? '—'}</dd>
          <dt>Address</dt>
          <dd>{[resident.house_number, resident.street].filter(Boolean).join(' ') || '—'}</dd>
          <dt>Resident Since</dt>
          <dd>{resident.residency_start_date ?? '—'}</dd>
          <dt>Voter Status</dt>
          <dd>{resident.voter_status === 'registered' ? 'Registered voter' : resident.voter_status === 'not_registered' ? 'Not registered' : '—'}</dd>
          <dt>Contact</dt>
          <dd>{resident.contact_number ?? '—'}</dd>
          <dt>Email</dt>
          <dd>{resident.email ?? '—'}</dd>
          <dt>Occupation</dt>
          <dd>{resident.occupation ?? '—'}</dd>
          <dt>Monthly Household Income</dt>
          <dd>{resident.monthly_income_bracket ? (resident.monthly_income_bracket === 'No income' ? 'No income' : `₱${resident.monthly_income_bracket}`) : '—'}</dd>
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
        </dl>
      </section>

      {hasPermission('assistance.view') && (
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
                  <td>{a.assistance_type}</td>
                  <td>{a.program_name}</td>
                  <td>{a.description_or_amount ?? '—'}</td>
                  <td>{a.date_received}</td>
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

      {hasPermission('classifications.view') && (
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
                  <td>{c.status}</td>
                  <td>
                    {c.status === 'active' && hasPermission('classifications.manage') && (
                      <button onClick={() => revokeClassification(c.id)}>Revoke</button>
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

      {hasPermission('a6.view') && (
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
                  <td>{s.proficiency_level ?? '—'}</td>
                  <td>{s.is_verified ? '✅' : '⚠ unverified'}</td>
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
                {a.title} {a.issuing_body ? `— ${a.issuing_body}` : ''} {a.date_achieved ? `(${a.date_achieved})` : ''}
              </li>
            ))}
          </ul>
          {hasPermission('a6.manage') && <button onClick={addAchievement}>+ Add achievement</button>}
        </section>
      )}
      <ResidentServicePanels residentId={resident.id} />
    </div>
  );
}
