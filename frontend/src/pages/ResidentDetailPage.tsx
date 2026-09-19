import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import client from '../api/client';
import type { Resident } from '../api/types';
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
  const [refKey, setRefKey] = useState(0);

  useEffect(() => {
    client.get(`/admin/residents/${id}`).then(({ data }) => setResident(data.data));
  }, [id, refKey]);

  useEffect(() => {
    if (!hasPermission('classifications.manage')) return;
    client.get(`/admin/residents/${id}/classifications`).then(({ data }) => setClassifications(data.data));
    client.get(`/admin/residents/${id}/classifications/suggestions`).then(({ data }) => setSuggestions(data.data));
    client.get('/admin/classification-types').then(({ data }) => setClassificationTypes(data.data));
  }, [id, hasPermission, refKey]);

  useEffect(() => {
    if (!hasPermission('a6.manage')) return;
    client.get(`/admin/residents/${id}/skills`).then(({ data }) => setSkills(data.data));
    client.get('/admin/skill-types').then(({ data }) => setSkillTypes(data.data));
    client.get(`/admin/residents/${id}/achievements`).then(({ data }) => setAchievements(data.data));
  }, [id, hasPermission, refKey]);

  const refresh = () => setRefKey((k) => k + 1);

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
      </div>

      <section className="detail-section">
        <h3>Personal Information</h3>
        <dl className="detail-grid">
          <dt>Sex</dt>
          <dd>{resident.sex}</dd>
          <dt>Birth Date</dt>
          <dd>{resident.birth_date} (age {resident.age})</dd>
          <dt>Civil Status</dt>
          <dd>{resident.civil_status}</dd>
          <dt>Purok</dt>
          <dd>{resident.purok?.name ?? '—'}</dd>
          <dt>Contact</dt>
          <dd>{resident.contact_number ?? '—'}</dd>
          <dt>Occupation</dt>
          <dd>{resident.occupation ?? '—'}</dd>
        </dl>
      </section>

      {hasPermission('classifications.manage') && (
        <section className="detail-section">
          <h3>Sectoral Classifications (A5)</h3>
          {suggestions.length > 0 && (
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
                    {c.status === 'active' && (
                      <button onClick={() => revokeClassification(c.id)}>Revoke</button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <select defaultValue="" onChange={(e) => e.target.value && assignClassification(Number(e.target.value))}>
            <option value="">+ Assign classification…</option>
            {classificationTypes.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </select>
        </section>
      )}

      {hasPermission('a6.manage') && (
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
                  <td>{!s.is_verified && <button onClick={() => verifySkill(s.id)}>Verify</button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <select defaultValue="" onChange={(e) => e.target.value && addSkill(Number(e.target.value))}>
            <option value="">+ Add skill…</option>
            {skillTypes.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </select>

          <h4>Achievements</h4>
          <ul>
            {achievements.map((a) => (
              <li key={a.id}>
                {a.title} {a.issuing_body ? `— ${a.issuing_body}` : ''} {a.date_achieved ? `(${a.date_achieved})` : ''}
              </li>
            ))}
          </ul>
          <button onClick={addAchievement}>+ Add achievement</button>
        </section>
      )}
    </div>
  );
}
