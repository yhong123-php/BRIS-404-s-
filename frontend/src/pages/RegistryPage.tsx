import { Tabs } from '../components/ui';
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
      <Tabs tabs={tabs.map((t) => ({ id: t.id, label: t.label }))} active={tab} onChange={(id) => setParams(id === 'residents' ? {} : { tab: id })} />

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
