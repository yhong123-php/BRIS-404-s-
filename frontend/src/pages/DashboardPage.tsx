import { motion, type Variants } from 'framer-motion';
import { BarChart, formatDate, formatDateTime, Tabs } from '../components/ui';
import { useEffect, useState, type ComponentType, type SVGProps } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import client from '../api/client';
import {
  ArchiveIcon,
  EditIcon,
  HouseholdsIcon,
  LoginIcon,
  LogoutIcon,
  PlusIcon,
  PuroksIcon,
  ReportsIcon,
  ResidentsIcon,
  WarningIcon,
} from '../components/icons';
import type { DashboardActivity, DashboardSummary } from '../api/types';
import { useAuth } from '../context/AuthContext';
import { PredictiveDashboard } from './ForecastsPage';

// table_name -> a detail route this app actually has. Several audited
// tables (puroks, relationships, resident_classifications, skills…) have no
// per-record detail page yet, so those stay plain text instead of a broken
// link.
const RECORD_ROUTES: Record<string, string> = {
  residents: '/residents',
  households: '/households',
  families: '/families',
};

function activityLink(entry: DashboardActivity): string | null {
  const base = RECORD_ROUTES[entry.table_name];
  return base && entry.record_id ? `${base}/${entry.record_id}` : null;
}

function activityMeta(action: string): { icon: ComponentType<SVGProps<SVGSVGElement>>; classes: string } {
  if (action === 'login_failed') return { icon: WarningIcon, classes: 'bg-error/10 text-error-strong dark:text-error' };
  if (action === 'login_success') return { icon: LoginIcon, classes: 'bg-primary/10 text-primary-dark dark:text-primary' };
  if (action === 'logout') return { icon: LogoutIcon, classes: 'bg-gray-200/60 text-gray-600 dark:bg-gray-700/60 dark:text-gray-300' };
  if (action.includes('delete') || action.includes('reject') || action.includes('archive')) {
    return { icon: ArchiveIcon, classes: 'bg-error/10 text-error-strong dark:text-error' };
  }
  if (action.includes('create') || action.includes('approved') || action.includes('provisioned') || action.includes('verified')) {
    return { icon: PlusIcon, classes: 'bg-success/10 text-success-strong dark:text-success' };
  }
  return { icon: EditIcon, classes: 'bg-primary/10 text-primary-dark dark:text-primary' };
}

// Plain-language wording for the activity feed, instead of raw audit codes
// like "login_success · Auth".
const ACTION_TEXT: Record<string, string> = {
  login_success: 'signed in',
  login_failed: 'had a failed sign-in attempt',
  logout: 'signed out',
  password_changed: 'changed their password',
  password_reset_by_captain: 'reset a staff password',
  password_reset_by_admin_cli: 'had their password reset',
  user_created: 'created a staff account',
  user_updated: 'updated a staff account',
  role_permissions_changed: 'changed role permissions',
  report_generated: 'generated a report',
  ai_resident_analysis_run: 'ran the AI resident analysis',
  backup_success: 'completed a backup',
  backup_failed: 'had a backup fail',
  backup_downloaded: 'downloaded a backup',
  backup_restored: 'restored a backup',
  relief_plan_submitted: 'submitted a relief plan',
  relief_distribution_closed: 'closed a relief distribution',
  data_subject_request_received: 'logged a data privacy request',
};

const TABLE_NOUN: Record<string, string> = {
  residents: 'a resident', households: 'a household', household_members: 'a household member', families: 'a family',
  relationships: 'a family relationship', puroks: 'a purok', resident_classifications: 'a resident classification',
  classification_types: 'a classification type', skills: 'a resident skill', skill_types: 'a skill type',
  achievements: 'an achievement', certificate_types: 'a certificate type', relief_distributions: 'a relief distribution',
  opportunities: 'an opportunity', consent_purposes: 'a consent purpose', assistant_access: 'AI assistant access',
  population_events: 'a population event', users: 'a staff account', backups: 'a backup',
};

const VERB: Record<string, string> = { create: 'added', update: 'updated', delete: 'deleted', archive: 'archived', restore: 'restored', approve: 'approved', reject: 'rejected' };

function describeActivity(entry: DashboardActivity): string {
  if (ACTION_TEXT[entry.action]) return ACTION_TEXT[entry.action];
  const noun = TABLE_NOUN[entry.table_name] ?? (entry.table_name && entry.table_name !== 'n/a' ? `a ${entry.table_name.replace(/_/g, ' ').replace(/s$/, '')} record` : 'a record');
  if (VERB[entry.action]) return `${VERB[entry.action]} ${noun}`;
  return entry.action.replace(/_/g, ' ');
}

function timeAgo(dateString: string): string {
  const seconds = Math.floor((Date.now() - new Date(dateString).getTime()) / 1000);
  if (seconds < 60) return 'just now';
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.floor(hours / 24);
  if (days < 7) return `${days}d ago`;
  return formatDate(dateString);
}

const activityListVariants: Variants = {
  hidden: {},
  visible: { transition: { staggerChildren: 0.05 } },
};

const activityItemVariants: Variants = {
  hidden: { opacity: 0, x: -12 },
  visible: { opacity: 1, x: 0, transition: { duration: 0.25 } },
};

interface StatCard {
  label: string;
  value: number | string;
  breakdown?: string;
  icon: ComponentType<SVGProps<SVGSVGElement>>;
  accent: string;
  link?: string;
}

// Entrance only — hover stays on Tailwind (scale + shadow-glow below), so
// Framer Motion isn't fighting Tailwind's own hover transition on the same
// property. staggerChildren gives the "cards cascade in" feel from a single
// parent animation rather than hand-timing each card's delay.
const cardGridVariants: Variants = {
  hidden: {},
  visible: { transition: { staggerChildren: 0.08 } },
};

const cardVariants: Variants = {
  hidden: { opacity: 0, y: 16 },
  visible: { opacity: 1, y: 0, transition: { duration: 0.35, ease: 'easeOut' } },
};

/**
 * Residents per purok. Puroks with residents are shown largest first; empty
 * puroks are folded into one line so a few populated ones aren't buried
 * under rows of zeros.
 */
function PurokChart({ puroks }: { puroks: { id: number; name: string; residents_count: number }[] }) {
  const [showAll, setShowAll] = useState(false);
  const sorted = [...puroks].sort((a, b) => b.residents_count - a.residents_count || a.name.localeCompare(b.name));
  const populated = sorted.filter((p) => p.residents_count > 0);
  const empty = sorted.length - populated.length;
  const shown = showAll || populated.length === 0 ? sorted : populated;
  return (
    <section className="detail-section" style={{ margin: 0 }}>
      <div className="section-head"><h3>Residents per purok</h3><Link to="/residents?tab=puroks" className="note">View puroks →</Link></div>
      <BarChart data={shown.map((p) => ({ label: p.name, value: p.residents_count }))} unit=" residents" />
      {empty > 0 && populated.length > 0 && (
        <button type="button" className="link-btn" style={{ marginTop: '0.5rem', paddingLeft: 0 }} onClick={() => setShowAll((v) => !v)}>
          {showAll ? 'Hide puroks with no residents' : `+ ${empty} ${empty === 1 ? 'purok' : 'puroks'} with no residents yet`}
        </button>
      )}
    </section>
  );
}

function DashboardOverview() {
  const [summary, setSummary] = useState<DashboardSummary | null>(null);
  const [puroks, setPuroks] = useState<{ id: number; name: string; residents_count: number; households_count: number }[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    client
      .get('/admin/dashboard/summary')
      .then(({ data }) => setSummary(data.data))
      .catch((err) => setError(err?.response?.data?.message ?? 'Could not load dashboard.'));
    client
      .get('/admin/puroks')
      .then(({ data }) => setPuroks(data.data))
      .catch(() => undefined);
  }, []);

  const cards: StatCard[] = summary
    ? [
        {
          label: 'Active residents',
          value: summary.residents_total,
          breakdown: `${summary.residents_by_sex.male ?? 0} male · ${summary.residents_by_sex.female ?? 0} female`,
          icon: ResidentsIcon,
          accent: 'bg-primary/10 text-primary-dark dark:text-primary',
          link: '/residents?status=active',
        },
        {
          label: 'Households',
          link: '/residents?tab=households',
          value: summary.households_total,
          icon: HouseholdsIcon,
          // Neutral, not charcoal: charcoal is very dark, so it only works
          // as text in light mode (same shape as primary/success/error) —
          // but unlike those, charcoal has no bright dark-mode counterpart
          // to revert to, so it can't fill a "-strong dark:X" pair. Plain
          // gray covers the same "soft gray = neutral" role from the spec.
          accent: 'bg-gray-200/60 text-gray-600 dark:bg-gray-700/60 dark:text-gray-300',
        },
        {
          label: 'Puroks',
          link: '/residents?tab=puroks',
          value: summary.puroks_total,
          icon: PuroksIcon,
          accent: 'bg-success/10 text-success-strong dark:text-success',
        },
        ...(summary.seniors_total !== undefined
          ? [
              {
                label: 'Senior citizens (60+)',
                value: summary.seniors_total,
                icon: ResidentsIcon,
                accent: 'bg-gray-200/60 text-gray-600 dark:bg-gray-700/60 dark:text-gray-300',
              },
            ]
          : []),
        ...(summary.top_skill !== undefined
          ? [
              {
                label: 'Top skill in the barangay',
                value: summary.top_skill ? summary.top_skill.name : '—',
                breakdown: summary.top_skill ? `${summary.top_skill.count} ${summary.top_skill.count === 1 ? 'resident' : 'residents'}` : 'No skills recorded yet',
                icon: ReportsIcon,
                accent: 'bg-primary/10 text-primary-dark dark:text-primary',
                link: '/reports',
              },
            ]
          : []),
        ...(summary.unemployed_count !== undefined
          ? [
              {
                label: 'Unemployed residents',
                value: summary.unemployed_count,
                icon: WarningIcon,
                accent: 'bg-error/10 text-error-strong dark:text-error',
                link: '/reports',
              },
            ]
          : []),
      ]
    : [];

  const primaryCards = cards.slice(0, 3);
  const secondaryCards = cards.slice(3);

  return (
    <div>
      {error && (
        <div className="mt-4 rounded-lg border border-error/30 bg-error/10 px-4 py-3 text-sm text-error-strong dark:text-error">{error}</div>
      )}

      {summary?.action_list && summary.action_list.length > 0 && (
        <div className="mt-6 rounded-xl border border-amber-300/60 bg-amber-50 px-5 py-4 dark:border-amber-400/30 dark:bg-amber-400/10">
          <h2 className="m-0 text-sm font-semibold text-amber-900 dark:text-amber-200">Needs attention</h2>
          <ul className="mt-2 space-y-1.5">
            {summary.action_list.map((a) => (
              <li key={a.label} className="flex items-center justify-between gap-3 text-sm">
                <span>{a.label}</span>
                <Link to={a.link} className="shrink-0 rounded-md bg-amber-200/70 px-2.5 py-0.5 font-semibold text-amber-900 hover:bg-amber-300 dark:bg-amber-400/20 dark:text-amber-100">
                  {a.count} →
                </Link>
              </li>
            ))}
          </ul>
        </div>
      )}

      {(summary?.events_this_month || (summary?.active_relief && summary.active_relief.length > 0)) && (
        <div className="mt-4 grid gap-4 sm:grid-cols-2">
          {summary?.events_this_month && (
            <Link to="/population-events" className={`${summary.active_relief?.length ? '' : 'sm:col-span-2 '}rounded-xl border border-gray-200 bg-bg-light px-5 py-4 text-sm transition-colors hover:border-primary/60 dark:border-white/10 dark:bg-charcoal`}>
              <div className="font-semibold">Population events this month</div>
              <div className="mt-1 text-gray-600 dark:text-gray-300">
                Births {summary.events_this_month.birth ?? 0} · Deaths {summary.events_this_month.death ?? 0} · Transfers in {summary.events_this_month.transfer_in ?? 0} · Transfers out {summary.events_this_month.transfer_out ?? 0}
              </div>
            </Link>
          )}
          {summary?.active_relief?.map((d) => (
            <Link key={d.id} to={`/relief/${d.id}`} className="rounded-xl border border-gray-200 bg-bg-light px-5 py-4 text-sm dark:border-white/10 dark:bg-charcoal">
              <div className="font-semibold">Open relief: {d.title}</div>
              <div className="mt-2 h-2 rounded bg-gray-200 dark:bg-white/10"><div className="h-2 rounded bg-primary" style={{ width: `${d.allocated ? (d.claimed / d.allocated) * 100 : 0}%` }} /></div>
              <div className="mt-1 text-gray-600 dark:text-gray-300">{d.claimed} of {d.allocated} households claimed</div>
            </Link>
          ))}
        </div>
      )}

      {/* Headline numbers: large cards. Supporting figures: one compact strip below. */}
      <motion.div
        key={primaryCards.length}
        variants={cardGridVariants}
        initial="hidden"
        animate="visible"
        className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3"
      >
        {primaryCards.map((card) => (
          <motion.div
            key={card.label}
            variants={cardVariants}
            className={`relative rounded-2xl border border-gray-200 bg-bg-light p-5 shadow-sm dark:border-white/10 dark:bg-charcoal ${
              // Only cards that go somewhere react to the pointer, so the hover never promises a click that does nothing.
              card.link ? 'group transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/60 hover:shadow-lg hover:shadow-primary/20 dark:hover:shadow-primary/10' : ''
            }`}
          >
            {card.link && <Link to={card.link} className="absolute inset-0 rounded-2xl" aria-label={`${card.label}: ${card.value}. Open details`} />}
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-gray-500 dark:text-gray-400">{card.label}</span>
              <div className={`flex h-9 w-9 items-center justify-center rounded-lg ${card.accent}`}>
                <card.icon width={18} height={18} />
              </div>
            </div>
            <div className="mt-2 truncate text-4xl font-semibold tracking-tight text-charcoal dark:text-gray-50">{card.value}</div>
            <div className="mt-1 flex items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
              <span>{card.breakdown}</span>
              {card.link && <span className="font-medium text-primary-dark transition-transform group-hover:translate-x-0.5 dark:text-primary">View →</span>}
            </div>
          </motion.div>
        ))}
      </motion.div>

      {secondaryCards.length > 0 && (
        <div className="mt-4 grid grid-cols-1 divide-y divide-gray-200 rounded-2xl border border-gray-200 bg-bg-light sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-white/10 dark:border-white/10 dark:bg-charcoal">
          {secondaryCards.map((card) => {
            const body = (
              <div className="flex items-center gap-3 px-5 py-3.5">
                <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${card.accent}`}>
                  <card.icon width={16} height={16} />
                </div>
                <div className="min-w-0 flex-1">
                  <div className="truncate text-xs text-gray-500 dark:text-gray-400">{card.label}</div>
                  <div className="truncate text-lg font-semibold text-charcoal dark:text-gray-50">
                    {card.value}
                    {card.breakdown && <span className="ml-2 text-xs font-normal text-gray-500 dark:text-gray-400">{card.breakdown}</span>}
                  </div>
                </div>
                {card.link && <span className="text-sm text-primary-dark dark:text-primary" aria-hidden="true">→</span>}
              </div>
            );
            return card.link ? (
              <Link key={card.label} to={card.link} className="block transition-colors first:rounded-t-2xl last:rounded-b-2xl hover:bg-primary/5 sm:first:rounded-l-2xl sm:first:rounded-tr-none sm:last:rounded-r-2xl sm:last:rounded-bl-none dark:hover:bg-primary/10">{body}</Link>
            ) : (
              <div key={card.label}>{body}</div>
            );
          })}
        </div>
      )}

      <div className="mt-6 grid items-start gap-6 lg:grid-cols-2">
      {puroks.length > 0 && <PurokChart puroks={puroks} />}

      {summary?.recent_activity && (
        <div className="rounded-2xl border border-gray-200 bg-bg-light p-5 shadow-sm dark:border-white/10 dark:bg-charcoal">
          <div className="flex items-center justify-between">
            <h2 className="m-0 text-base font-semibold text-charcoal dark:text-gray-50">Recent activity</h2>
            <Link to="/audit-logs" className="text-xs font-medium text-primary-dark hover:underline dark:text-primary">
              View all
            </Link>
          </div>

          {summary.recent_activity.length === 0 ? (
            <p className="mb-0 mt-3 text-sm text-gray-500 dark:text-gray-400">Nothing recorded yet.</p>
          ) : (
            <motion.ul
              variants={activityListVariants}
              initial="hidden"
              animate="visible"
              className="dash-activity mb-0 mt-3 divide-y divide-gray-200 dark:divide-white/10"
            >
              {summary.recent_activity.slice(0, 5).map((entry, i) => {
                const { icon: ActivityIcon, classes } = activityMeta(entry.action);
                const link = activityLink(entry);
                const initials = entry.user
                  .split(' ')
                  .map((part) => part[0])
                  .slice(0, 2)
                  .join('')
                  .toUpperCase();

                const row = (
                  <div className="flex items-center gap-3 py-2.5">
                    <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${classes}`}>
                      <ActivityIcon width={15} height={15} />
                    </div>
                    <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-200 text-[10px] font-semibold text-gray-600 dark:bg-white/10 dark:text-gray-300">
                      {initials || '?'}
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="m-0 truncate text-sm text-charcoal dark:text-gray-100">
                        <span className="font-medium">{entry.user}</span>{' '}
                        <span className="text-gray-500 dark:text-gray-400">{describeActivity(entry)}</span>
                      </p>
                    </div>
                    <span className="shrink-0 text-xs text-gray-400" title={formatDateTime(entry.created_at)}>
                      {timeAgo(entry.created_at)}
                    </span>
                  </div>
                );

                return (
                  <motion.li key={i} variants={activityItemVariants}>
                    {link ? (
                      <Link to={link} className="-mx-2 block rounded-lg px-2 transition-colors hover:bg-primary/5 dark:hover:bg-primary/10">
                        {row}
                      </Link>
                    ) : (
                      row
                    )}
                  </motion.li>
                );
              })}
            </motion.ul>
          )}
        </div>
      )}
      </div>
    </div>
  );
}

type DashboardView = 'overview' | 'predictive';

export default function DashboardPage() {
  const { user, hasPermission } = useAuth();
  const [params, setParams] = useSearchParams();
  const canPredict = hasPermission('f4.view');
  const view: DashboardView = canPredict && params.get('view') === 'predictive' ? 'predictive' : 'overview';
  const views: { id: DashboardView; label: string }[] = [
    { id: 'overview', label: 'Overview' },
    ...(canPredict ? [{ id: 'predictive' as const, label: 'Predictive & demographic' }] : []),
  ];

  return (
    <div>
      <h2 className="page-title">Welcome, {user?.full_name}</h2>
      <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Here's what's happening in the barangay today.</p>

      {views.length > 1 && (
        <Tabs className="mt-5" tabs={views} active={view} onChange={(id) => setParams(id === 'overview' ? {} : { view: id })} />
      )}

      {view === 'overview' ? <DashboardOverview /> : <div className="mt-5"><PredictiveDashboard /></div>}
    </div>
  );
}
