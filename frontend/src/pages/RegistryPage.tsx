import { Navigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import FamiliesPage from './FamiliesPage';
import HouseholdsListPage from './HouseholdsListPage';
import PuroksPage from './PuroksPage';
import ResidentsListPage from './ResidentsListPage';

/**
 * The resident registry in one place: residents (A1), households (A2),
 * families (A3), and puroks (A4) as tabs of one page. Each tab is shown only
 * to roles that may see it; detail pages keep their own addresses.
 */

type RegistryTab = 'residents' | 'households' | 'families' | 'puroks';

const TABS: { id: RegistryTab; label: string; permission: string }[] = [
  { id: 'residents', label: 'Residents', permission: 'residents.view' },
  { id: 'households', label: 'Households', permission: 'households.view' },
  { id: 'families', label: 'Families', permission: 'relationships.view' },
  { id: 'puroks', label: 'Puroks', permission: 'puroks.view' },
];

/** Permissions that show the combined page in the sidebar. */
export const REGISTRY_PERMISSIONS = TABS.map((t) => t.permission);

export default function RegistryPage() {
  const { hasPermission } = useAuth();
  const [params, setParams] = useSearchParams();
  const tabs = TABS.filter((t) => hasPermission(t.permission));
  const tab = tabs.find((t) => t.id === params.get('tab'))?.id ?? tabs[0]?.id;

  if (!tab) return <p className="text-sm text-gray-500 dark:text-gray-400">You don't have access to the resident registry.</p>;

  return (
    <div>
      <div className="mb-5 flex gap-1 border-b border-gray-200 dark:border-white/10">
        {tabs.map((t) => (
          <button
            key={t.id}
            onClick={() => setParams(t.id === 'residents' ? {} : { tab: t.id })}
            className={`relative px-4 py-2.5 text-sm font-medium transition-colors ${
              tab === t.id ? 'text-primary-dark dark:text-primary' : 'text-gray-500 hover:text-charcoal dark:text-gray-400 dark:hover:text-gray-200'
            }`}
          >
            {t.label}
            {tab === t.id && <span className="absolute inset-x-0 -bottom-px h-0.5 bg-primary" />}
          </button>
        ))}
      </div>

      {tab === 'residents' && <ResidentsListPage />}
      {tab === 'households' && <HouseholdsListPage />}
      {tab === 'families' && <FamiliesPage />}
      {tab === 'puroks' && <PuroksPage />}
    </div>
  );
}

/** Old list addresses (/households, /families, /puroks) open the matching tab. */
export function RegistryRedirect({ tab }: { tab: RegistryTab }) {
  return <Navigate to={`/residents?tab=${tab}`} replace />;
}
