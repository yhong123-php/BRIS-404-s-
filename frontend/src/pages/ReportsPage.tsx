import { AnimatePresence, motion } from 'framer-motion';
import { BarChart, PageSkeleton, Tabs } from '../components/ui';
import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import client from '../api/client';
import type { AchievementsReport, BeneficiariesReport, EmploymentReport, SkillsReport } from '../api/types';
import { useAuth } from '../context/AuthContext';
import { AiResidentAnalysis } from './AiSuggestionsPage';
import { ReportGenerator } from './ReportGeneratorPage';

type Tab = 'all' | 'ai' | 'skills' | 'employment' | 'beneficiaries' | 'achievements';

const cardClass = 'rounded-2xl border border-gray-200 bg-bg-light p-5 shadow-sm dark:border-white/10 dark:bg-charcoal';
const tableWrapClass = 'mt-5 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-charcoal-deep';

function SkillsTab() {
  const [report, setReport] = useState<SkillsReport | null>(null);
  useEffect(() => {
    client.get('/admin/reports/skills').then(({ data }) => setReport(data.data));
  }, []);
  if (!report) return <PageSkeleton cards={4} />;

  return (
    <div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className={cardClass}>
          <span className="text-sm font-medium text-gray-500 dark:text-gray-400">Skilled residents</span>
          <div className="mt-2 text-3xl font-semibold text-charcoal dark:text-gray-50">{report.total_skilled_residents}</div>
        </div>
        <div className={cardClass}>
          <span className="text-sm font-medium text-gray-500 dark:text-gray-400">Distinct skill types recorded</span>
          <div className="mt-2 text-3xl font-semibold text-charcoal dark:text-gray-50">{report.by_type.length}</div>
        </div>
      </div>

      <section className="detail-section mt-5">
        <h3>Residents per skill</h3>
        {report.by_type.length === 0 ? <p className="note">No skills recorded yet.</p> : (
          <BarChart data={[...report.by_type].sort((x, y) => y.count - x.count).map((r) => ({ label: r.name, value: r.count }))} unit=" residents" />
        )}
      </section>
    </div>
  );
}

function EmploymentTab() {
  const [report, setReport] = useState<EmploymentReport | null>(null);
  useEffect(() => {
    client.get('/admin/reports/employment').then(({ data }) => setReport(data.data));
  }, []);
  if (!report) return <PageSkeleton cards={4} />;

  return (
    <div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div className={cardClass}>
          <span className="text-sm font-medium text-gray-500 dark:text-gray-400">Employed</span>
          <div className="mt-2 text-3xl font-semibold text-success-strong dark:text-success">{report.employed_count}</div>
        </div>
        <div className={cardClass}>
          <span className="text-sm font-medium text-gray-500 dark:text-gray-400">Unemployed</span>
          <div className="mt-2 text-3xl font-semibold text-error-strong dark:text-error">{report.unemployed_count}</div>
        </div>
        <div className={cardClass}>
          <span className="text-sm font-medium text-gray-500 dark:text-gray-400">Unspecified</span>
          <div className="mt-2 text-3xl font-semibold text-charcoal dark:text-gray-50">{report.unspecified_count}</div>
        </div>
      </div>
      <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
        Based on each resident's recorded employment status (self-employed counts as employed). "Unspecified" residents
        have no employment status recorded yet — they are not assumed to be either.
      </p>

      {report.unemployed_residents.length > 0 && (
        <section className="detail-section mt-5">
          <h3>Unemployed residents by purok</h3>
          <BarChart data={Object.entries(report.unemployed_residents.reduce<Record<string, number>>((acc, r) => { const k = r.purok ?? 'No purok'; acc[k] = (acc[k] ?? 0) + 1; return acc; }, {}))
            .sort(([a], [b]) => a.localeCompare(b, undefined, { numeric: true }))
            .map(([label, value]) => ({ label, value }))} unit=" unemployed" />
        </section>
      )}

      <div className={tableWrapClass}>
        <table className="w-full border-collapse text-left text-sm">
          <thead className="bg-charcoal text-gray-300">
            <tr className="text-xs uppercase tracking-wide">
              <th className="px-5 py-3 font-medium">Resident</th>
              <th className="px-5 py-3 font-medium">Purok</th>
              <th className="px-5 py-3 font-medium">Age</th>
            </tr>
          </thead>
          <tbody>
            {report.unemployed_residents.length === 0 ? (
              <tr>
                <td colSpan={3} className="px-5 py-8 text-center text-gray-500 dark:text-gray-400">
                  No residents currently classified as unemployed.
                </td>
              </tr>
            ) : (
              report.unemployed_residents.map((r, i) => (
                <tr
                  key={r.resident_id}
                  className={`border-b border-gray-100 text-charcoal last:border-0 dark:border-white/10 dark:text-gray-100 ${
                    i % 2 === 1 ? 'bg-bg-light dark:bg-white/5' : 'bg-white dark:bg-transparent'
                  }`}
                >
                  <td className="px-5 py-3">{r.resident_name}</td>
                  <td className="px-5 py-3 text-gray-600 dark:text-gray-300">{r.purok ?? '—'}</td>
                  <td className="px-5 py-3 text-gray-600 dark:text-gray-300">{r.age ?? '—'}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function BeneficiariesTab() {
  const [report, setReport] = useState<BeneficiariesReport | null>(null);
  const [typeId, setTypeId] = useState<number | null>(null);

  const load = (id?: number) => {
    client.get('/admin/reports/beneficiaries', { params: id ? { classification_type_id: id } : {} }).then(({ data }) => {
      setReport(data.data);
      setTypeId(data.data.selected_classification_type_id);
    });
  };

  useEffect(() => load(), []);

  if (!report) return <PageSkeleton cards={4} />;

  return (
    <div>
      <p className="text-xs text-gray-500 dark:text-gray-400">
        "Beneficiaries" reflects sectoral classifications that denote an assistance program (currently 4Ps Member —
        the only one this system tracks). Relief distributions have their own per-purok summary on the Relief Distribution page.
      </p>

      <div className={`${cardClass} mt-4`}>
        <h3 className="text-sm font-semibold text-charcoal dark:text-gray-50">By classification</h3>
        <ul className="mt-3 divide-y divide-gray-200 dark:divide-white/10">
          {report.by_type.length === 0 ? (
            <li className="py-2 text-sm text-gray-500 dark:text-gray-400">No sectoral classifications recorded yet.</li>
          ) : (
            report.by_type.map((row) => (
              <li key={row.classification_type_id} className="flex items-center justify-between py-2 text-sm">
                <button
                  type="button"
                  onClick={() => load(row.classification_type_id)}
                  className={`text-left hover:underline ${
                    row.classification_type_id === typeId ? 'font-semibold text-primary-dark dark:text-primary' : 'text-charcoal dark:text-gray-100'
                  }`}
                >
                  {row.name}
                </button>
                <span className="font-medium text-charcoal dark:text-gray-50">{row.count}</span>
              </li>
            ))
          )}
        </ul>
      </div>

      <div className={tableWrapClass}>
        <table className="w-full border-collapse text-left text-sm">
          <thead className="bg-charcoal text-gray-300">
            <tr className="text-xs uppercase tracking-wide">
              <th className="px-5 py-3 font-medium">Resident</th>
              <th className="px-5 py-3 font-medium">Purok</th>
              <th className="px-5 py-3 font-medium">Date granted</th>
            </tr>
          </thead>
          <tbody>
            {report.beneficiaries.length === 0 ? (
              <tr>
                <td colSpan={3} className="px-5 py-8 text-center text-gray-500 dark:text-gray-400">
                  No residents under this classification yet.
                </td>
              </tr>
            ) : (
              report.beneficiaries.map((b, i) => (
                <tr
                  key={b.resident_id}
                  className={`border-b border-gray-100 text-charcoal last:border-0 dark:border-white/10 dark:text-gray-100 ${
                    i % 2 === 1 ? 'bg-bg-light dark:bg-white/5' : 'bg-white dark:bg-transparent'
                  }`}
                >
                  <td className="px-5 py-3">{b.resident_name}</td>
                  <td className="px-5 py-3 text-gray-600 dark:text-gray-300">{b.purok ?? '—'}</td>
                  <td className="px-5 py-3 text-gray-600 dark:text-gray-300">{b.date_granted ?? '—'}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function AchievementsTab() {
  const [report, setReport] = useState<AchievementsReport | null>(null);
  useEffect(() => {
    client.get('/admin/reports/achievements').then(({ data }) => setReport(data.data));
  }, []);
  if (!report) return <PageSkeleton cards={4} />;

  return (
    <div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className={cardClass}>
          <span className="text-sm font-medium text-gray-500 dark:text-gray-400">Total achievements recorded</span>
          <div className="mt-2 text-3xl font-semibold text-charcoal dark:text-gray-50">{report.total}</div>
        </div>
      </div>

      {report.by_category.length > 0 && (
        <section className="detail-section mt-5">
          <h3>Achievements by category</h3>
          <BarChart data={[...report.by_category].sort((x, y) => y.count - x.count).map((r) => ({ label: r.category || 'Uncategorized', value: r.count }))} />
        </section>
      )}

      <div className={tableWrapClass}>
        <table className="w-full border-collapse text-left text-sm">
          <thead className="bg-charcoal text-gray-300">
            <tr className="text-xs uppercase tracking-wide">
              <th className="px-5 py-3 font-medium">Resident</th>
              <th className="px-5 py-3 font-medium">Achievement</th>
              <th className="px-5 py-3 font-medium">Category</th>
              <th className="px-5 py-3 font-medium">Date</th>
            </tr>
          </thead>
          <tbody>
            {report.recent.length === 0 ? (
              <tr>
                <td colSpan={4} className="px-5 py-8 text-center text-gray-500 dark:text-gray-400">
                  No achievements recorded yet.
                </td>
              </tr>
            ) : (
              report.recent.map((a, i) => (
                <tr
                  key={a.id}
                  className={`border-b border-gray-100 text-charcoal last:border-0 dark:border-white/10 dark:text-gray-100 ${
                    i % 2 === 1 ? 'bg-bg-light dark:bg-white/5' : 'bg-white dark:bg-transparent'
                  }`}
                >
                  <td className="px-5 py-3">{a.resident_name}</td>
                  <td className="px-5 py-3">{a.title}</td>
                  <td className="px-5 py-3 text-gray-600 dark:text-gray-300">{a.category ?? '—'}</td>
                  <td className="px-5 py-3 text-gray-600 dark:text-gray-300">{a.date_achieved ?? '—'}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

export default function ReportsPage() {
  const { hasPermission } = useAuth();
  // SDD Table 5.1: "Generate reports — All / All" for both roles.
  const canReports = hasPermission('reports.generate.all');
  const canSkills = canReports && hasPermission('a6.view');
  const canClassifications = canReports && hasPermission('classifications.view');

  const availableTabs: { id: Tab; label: string }[] = [
    ...(canReports ? ([{ id: 'all', label: 'All reports & export' }] as const) : []),
    ...(canReports && hasPermission('f1.read') ? ([{ id: 'ai', label: 'AI Resident Analysis' }] as const) : []),
    ...(canSkills ? ([{ id: 'skills', label: 'Skills' }] as const) : []),
    ...(canClassifications ? ([{ id: 'employment', label: 'Employment' }, { id: 'beneficiaries', label: 'Beneficiaries' }] as const) : []),
    ...(canSkills ? ([{ id: 'achievements', label: 'Achievements' }] as const) : []),
  ];

  const [params] = useSearchParams();
  const requested = availableTabs.find((t) => t.id === params.get('tab'))?.id;
  const [tab, setTab] = useState<Tab | null>(requested ?? availableTabs[0]?.id ?? null);

  if (!tab) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">You don't have access to any reports.</p>;
  }

  return (
    <div>
      <div className="no-print">
        <h2 className="page-title">Reports</h2>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Print or export any report, and see quick breakdowns of skills, employment, sectors and achievements.
        </p>
      </div>

      <Tabs className="no-print mt-5" tabs={availableTabs.map((t) => ({ id: t.id as Tab, label: t.label }))} active={tab} onChange={setTab} />

      <AnimatePresence mode="wait">
        <motion.div
          key={tab}
          initial={{ opacity: 0, y: 8 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.2 }}
          className="mt-5"
        >
          {tab === 'all' && <ReportGenerator />}
          {tab === 'ai' && <AiResidentAnalysis />}
          {tab === 'skills' && <SkillsTab />}
          {tab === 'employment' && <EmploymentTab />}
          {tab === 'beneficiaries' && <BeneficiariesTab />}
          {tab === 'achievements' && <AchievementsTab />}
        </motion.div>
      </AnimatePresence>
    </div>
  );
}
