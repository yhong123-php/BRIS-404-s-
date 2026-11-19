import { useState, type FormEvent } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import {
  ArchiveIcon,
  AssistantIcon,
  AuditIcon,
  ChevronDownIcon,
  DashboardIcon,
  HouseholdsIcon,
  LogoutIcon,
  ReportsIcon,
  ResidentsIcon,
  SearchIcon,
  ShieldIcon,
} from './icons';
import ThemeToggleButton from './ThemeToggleButton';
import { REGISTRY_PERMISSIONS } from '../pages/RegistryPage';

const NAV_ITEMS = [
  { to: '/', label: 'Dashboard', icon: DashboardIcon, permission: 'dashboard.view', end: true },
  { to: '/residents', label: 'Residents & Households', icon: ResidentsIcon, permission: REGISTRY_PERMISSIONS },
  { to: '/relief', label: 'Relief Distribution', icon: HouseholdsIcon, permission: 'relief.view' },
  { to: '/population-events', label: 'Births, Deaths & Transfers', icon: ResidentsIcon, permission: 'population_events.view' },
  { to: '/opportunities', label: 'Opportunities', icon: ReportsIcon, permission: 'f2.view' },
  { to: '/reports', label: 'Reports', icon: ReportsIcon, permission: 'reports.generate.all' },
  { to: '/assistant', label: 'AI Assistant', icon: AssistantIcon, permission: 'assistant.use' },
  { to: '/audit-logs', label: 'Audit Log', icon: AuditIcon, permission: 'audit.view' },
  { to: '/privacy', label: 'Data Privacy', icon: ShieldIcon, permission: ['g2.receive', 'g2.decide'] },
  { to: '/users', label: 'User Management', icon: ResidentsIcon, permission: 'users.manage' },
  { to: '/backups', label: 'Backup & Restore', icon: ArchiveIcon, permission: 'backup.restore' },
  { to: '/assistant-access', label: 'Assistant Access', icon: ShieldIcon, permission: 'assistant.configure' },
];

function navLinkClasses(isActive: boolean) {
  // No dark: variants here: the sidebar is charcoal unconditionally (per
  // spec — it doesn't follow the app's light/dark toggle), so its own text
  // colors are fixed against that fixed dark background rather than
  // adapting with the page.
  return [
    'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors',
    isActive ? 'bg-primary/15 text-primary' : 'text-gray-300 hover:bg-primary/10 hover:text-primary',
  ].join(' ');
}

export default function Layout() {
  const { user, logout, hasPermission } = useAuth();
  const navigate = useNavigate();
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [collapsed, setCollapsed] = useState(false);

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  const handleSearch = (e: FormEvent) => {
    e.preventDefault();
    if (!search.trim()) return;
    navigate(`/residents?search=${encodeURIComponent(search.trim())}`);
    setSearch('');
  };

  const visibleItems = NAV_ITEMS.filter((item) =>
    Array.isArray(item.permission) ? item.permission.some(hasPermission) : hasPermission(item.permission)
  );

  return (
    <div className="min-h-screen bg-white font-sans text-gray-900 transition-colors duration-300 dark:bg-bg-dark dark:text-gray-100">
      {mobileNavOpen && (
        <div className="fixed inset-0 z-30 bg-black/40 md:hidden" onClick={() => setMobileNavOpen(false)} />
      )}

      <aside
        className={`fixed inset-y-0 left-0 z-40 flex flex-col overflow-hidden border-r border-white/10 bg-charcoal text-gray-300 transition-[width,transform] duration-300 ease-in-out md:translate-x-0 ${
          mobileNavOpen ? 'translate-x-0' : '-translate-x-full'
        } ${collapsed ? 'w-20' : 'w-64'}`}
      >
        <div className="flex h-16 items-center gap-2 border-b border-white/10 px-5">
          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary text-sm font-bold text-charcoal">
            B
          </div>
          <span className={`whitespace-nowrap font-semibold text-white transition-opacity duration-200 ${collapsed ? 'opacity-0' : 'opacity-100'}`}>
            BRIS
          </span>
        </div>
        <nav className="flex-1 space-y-1 overflow-y-auto p-3">
          {visibleItems.map((item) => (
            <NavLink
              key={item.label}
              to={item.to}
              end={item.end}
              title={collapsed ? item.label : undefined}
              className={({ isActive }) => navLinkClasses(isActive)}
              onClick={() => setMobileNavOpen(false)}
            >
              <item.icon className="shrink-0" />
              <span className={`whitespace-nowrap transition-opacity duration-200 ${collapsed ? 'opacity-0' : 'opacity-100'}`}>
                {item.label}
              </span>
            </NavLink>
          ))}
        </nav>

        {/* Desktop-only collapse toggle — mobile uses the topbar hamburger
            + slide-in instead, so this stays hidden there. */}
        <button
          type="button"
          onClick={() => setCollapsed((v) => !v)}
          className="hidden items-center justify-center gap-2 border-t border-white/10 py-3 text-gray-400 hover:bg-white/5 hover:text-primary md:flex"
          aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        >
          <ChevronDownIcon width={16} height={16} className={`transition-transform duration-300 ${collapsed ? '-rotate-90' : 'rotate-90'}`} />
        </button>
      </aside>

      <div className={`flex min-h-screen flex-col transition-[padding] duration-300 ease-in-out ${collapsed ? 'md:pl-20' : 'md:pl-64'}`}>
        <header className="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-gray-200 bg-bg-light px-4 text-charcoal transition-colors duration-300 dark:border-white/10 dark:bg-charcoal-deep dark:text-gray-100 sm:px-6">
          <button
            type="button"
            className="rounded-lg p-2 text-gray-500 hover:bg-black/5 dark:text-gray-400 dark:hover:bg-white/10 md:hidden"
            onClick={() => setMobileNavOpen((v) => !v)}
            aria-label="Toggle navigation"
          >
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
              <path d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>

          <form onSubmit={handleSearch} className="relative flex-1 max-w-md">
            <SearchIcon className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" width={18} height={18} />
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search residents…"
              className="w-full rounded-lg border border-gray-200 bg-white py-2 pl-9 pr-3 text-sm text-charcoal outline-none focus:border-primary focus:ring-1 focus:ring-primary dark:border-white/10 dark:bg-charcoal dark:text-gray-100"
            />
          </form>

          <div className="ml-auto flex items-center gap-2">
            <ThemeToggleButton className="text-gray-500 dark:text-gray-300" />

            <div className="relative">
              <button
                type="button"
                onClick={() => setProfileOpen((v) => !v)}
                className="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-black/5 dark:hover:bg-white/10"
              >
                <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-xs font-semibold text-charcoal">
                  {user?.full_name?.slice(0, 1).toUpperCase()}
                </div>
                <span className="hidden text-sm font-medium sm:block">{user?.full_name}</span>
                <ChevronDownIcon width={16} height={16} className="hidden text-gray-400 sm:block" />
              </button>

              {profileOpen && (
                <>
                  <div className="fixed inset-0 z-10" onClick={() => setProfileOpen(false)} />
                  <div className="absolute right-0 z-20 mt-2 w-56 rounded-lg border border-gray-200 bg-white py-1 text-gray-900 shadow-lg dark:border-white/10 dark:bg-charcoal dark:text-gray-100">
                    <div className="border-b border-gray-100 px-4 py-3 dark:border-white/10">
                      <p className="text-sm font-medium">{user?.full_name}</p>
                      <p className="text-xs capitalize text-gray-500 dark:text-gray-400">{user?.role}</p>
                    </div>
                    <button
                      type="button"
                      onClick={handleLogout}
                      className="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-error-strong hover:bg-gray-50 dark:text-error dark:hover:bg-gray-800"
                    >
                      <LogoutIcon width={16} height={16} />
                      Log out
                    </button>
                  </div>
                </>
              )}
            </div>
          </div>
        </header>

        <main className="flex-1 p-4 sm:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
