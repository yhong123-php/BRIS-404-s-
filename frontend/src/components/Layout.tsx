import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import {
  AssistantIcon,
  AuditIcon,
  BriefcaseIcon,
  CalendarIcon,
  ChevronDownIcon,
  DashboardIcon,
  DatabaseIcon,
  KeyIcon,
  LockIcon,
  LogoutIcon,
  ReliefIcon,
  ReportsIcon,
  ResidentsIcon,
  UsersCogIcon,
} from './icons';
import ThemeToggleButton from './ThemeToggleButton';
import GlobalSearch from './GlobalSearch';
import { REGISTRY_PERMISSIONS } from '../pages/RegistryPage';

type NavItem = { to: string; label: string; icon: typeof DashboardIcon; permission: string | string[]; end?: boolean };

/** Sidebar sections, in the order of the SDD module groups. */
const NAV_SECTIONS: { title: string; items: NavItem[] }[] = [
  { title: 'Overview', items: [
    { to: '/', label: 'Dashboard', icon: DashboardIcon, permission: 'dashboard.view', end: true },
  ] },
  { title: 'Registry', items: [
    { to: '/residents', label: 'Residents & Households', icon: ResidentsIcon, permission: REGISTRY_PERMISSIONS },
    { to: '/population-events', label: 'Births, Deaths & Transfers', icon: CalendarIcon, permission: 'population_events.view' },
  ] },
  { title: 'Services', items: [
    { to: '/relief', label: 'Relief Distribution', icon: ReliefIcon, permission: 'relief.view' },
    { to: '/opportunities', label: 'Opportunities', icon: BriefcaseIcon, permission: 'f2.view' },
  ] },
  { title: 'Analytics', items: [
    { to: '/reports', label: 'Reports', icon: ReportsIcon, permission: 'reports.generate.all' },
    { to: '/assistant', label: 'AI Assistant', icon: AssistantIcon, permission: 'assistant.use' },
  ] },
  { title: 'Administration', items: [
    { to: '/privacy', label: 'Data Privacy', icon: LockIcon, permission: ['g2.receive', 'g2.decide'] },
    { to: '/users', label: 'User Management', icon: UsersCogIcon, permission: 'users.manage' },
    { to: '/audit-logs', label: 'Audit Log', icon: AuditIcon, permission: 'audit.view' },
    { to: '/backups', label: 'Backup & Restore', icon: DatabaseIcon, permission: 'backup.restore' },
    { to: '/assistant-access', label: 'Assistant Access', icon: KeyIcon, permission: 'assistant.configure' },
  ] },
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

/** "Barangay Captain" → "BC", so the captain and secretary avatars differ at a glance. */
function initials(name: string | null | undefined): string {
  const parts = (name ?? '').trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  return (parts.length === 1 ? parts[0].slice(0, 2) : parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/** BRIS mark: a house, for the households the registry is built around. */
function BrandMark() {
  return (
    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary text-charcoal" aria-hidden="true">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M3 11.5 12 4l9 7.5" />
        <path d="M5.5 9.8V20h13V9.8" />
        <path d="M10 20v-5h4v5" />
      </svg>
    </div>
  );
}

export default function Layout() {
  const { user, logout, hasPermission } = useAuth();
  const navigate = useNavigate();
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  // Remembered between visits; storage can be unavailable (private mode), so failures fall back to expanded.
  const [collapsed, setCollapsed] = useState(() => {
    try { return localStorage.getItem('bris_sidebar_collapsed') === '1'; } catch { return false; }
  });

  useEffect(() => {
    try { localStorage.setItem('bris_sidebar_collapsed', collapsed ? '1' : '0'); } catch { /* not saved */ }
  }, [collapsed]);

  useEffect(() => {
    if (!profileOpen) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setProfileOpen(false); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [profileOpen]);

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };


  const allowed = (item: NavItem) => (Array.isArray(item.permission) ? item.permission.some(hasPermission) : hasPermission(item.permission));
  const visibleSections = NAV_SECTIONS.map((section) => ({ ...section, items: section.items.filter(allowed) })).filter((section) => section.items.length > 0);

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
        <div className="flex h-16 items-center gap-3 border-b border-white/10 px-5">
          <BrandMark />
          <div className={`min-w-0 leading-tight transition-opacity duration-200 ${collapsed ? 'opacity-0' : 'opacity-100'}`}>
            <div className="whitespace-nowrap font-semibold text-white">BRIS</div>
            <div className="whitespace-nowrap text-[11px] text-gray-400">Barangay San Isidro</div>
          </div>
        </div>
        <nav className="flex-1 overflow-y-auto p-3" aria-label="Main">
          {visibleSections.map((section, i) => (
            <div key={section.title} className={i > 0 ? 'mt-4' : ''}>
              {collapsed ? (
                i > 0 && <div className="mx-3 mb-2 border-t border-white/10" />
              ) : (
                <div className="mb-1 px-3 text-[11px] font-semibold uppercase tracking-wider text-gray-500">{section.title}</div>
              )}
              <div className="space-y-1">
                {section.items.map((item) => (
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
              </div>
            </div>
          ))}
        </nav>

        {/* Desktop-only collapse toggle — mobile uses the topbar hamburger
            + slide-in instead, so this stays hidden there. */}
        <button
          type="button"
          onClick={() => setCollapsed((v) => !v)}
          className="sidebar-toggle hidden items-center justify-center gap-2 border-t border-white/10 py-3 text-gray-400 hover:bg-white/5 hover:text-primary md:flex"
          aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        >
          <ChevronDownIcon width={16} height={16} className={`transition-transform duration-300 ${collapsed ? '-rotate-90' : 'rotate-90'}`} />
        </button>
      </aside>

      <div className={`flex min-h-screen flex-col transition-[padding] duration-300 ease-in-out ${collapsed ? 'md:pl-20' : 'md:pl-64'}`}>
        <header className="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-gray-200 bg-bg-light px-4 text-charcoal transition-colors duration-300 dark:border-white/10 dark:bg-charcoal-deep dark:text-gray-100 sm:px-6">
          <button
            type="button"
            className="btn-ghost text-gray-500 dark:text-gray-400 md:hidden"
            onClick={() => setMobileNavOpen((v) => !v)}
            aria-label="Toggle navigation"
          >
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
              <path d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>

          <GlobalSearch />

          <div className="ml-auto flex items-center gap-2">
            <ThemeToggleButton className="text-gray-500 dark:text-gray-300" />

            <div className="relative">
              <button
                type="button"
                onClick={() => setProfileOpen((v) => !v)}
                className="btn-ghost inline-flex items-center gap-2"
                aria-haspopup="menu"
                aria-expanded={profileOpen}
              >
                <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-xs font-semibold text-charcoal">
                  {initials(user?.full_name)}
                </div>
                <span className="hidden text-sm font-medium sm:block">{user?.full_name}</span>
                <ChevronDownIcon width={16} height={16} className="hidden text-gray-400 sm:block" />
              </button>

              {profileOpen && (
                <>
                  <div className="fixed inset-0 z-10" onClick={() => setProfileOpen(false)} />
                  <div role="menu" className="absolute right-0 z-20 mt-2 w-56 rounded-lg border border-gray-200 bg-white py-1 text-gray-900 shadow-lg dark:border-white/10 dark:bg-charcoal dark:text-gray-100">
                    <div className="border-b border-gray-100 px-4 py-3 dark:border-white/10">
                      <p className="text-sm font-medium">{user?.full_name}</p>
                      <p className="text-xs capitalize text-gray-500 dark:text-gray-400">{user?.role}</p>
                    </div>
                    <Link role="menuitem" to="/change-password" className="menu-item" onClick={() => setProfileOpen(false)}>
                      <KeyIcon width={16} height={16} />
                      Change password
                    </Link>
                    <button type="button" role="menuitem" onClick={handleLogout} className="menu-item danger">
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
