import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import client from '../api/client';
import type { StaffUser } from '../api/types';

interface AuthContextValue {
  user: StaffUser | null;
  loading: boolean;
  login: (login: string, password: string) => Promise<StaffUser>;
  logout: () => Promise<void>;
  hasPermission: (permission: string) => boolean;
  refreshUser: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

// Client-side role→permission mirror, purely for hiding nav/buttons; the
// backend re-checks every request, so this list drifting from Table 9.0
// only affects UI convenience, never security (SDD §5.2).
const ROLE_PERMISSIONS: Record<string, string[]> = {
  captain: ['dashboard.view', 'residents.view', 'households.view', 'puroks.view'],
  secretary: [
    'dashboard.view', 'residents.view', 'residents.create', 'residents.edit', 'residents.merge_archive',
    'households.view', 'households.manage', 'relationships.manage', 'puroks.view', 'puroks.manage',
    'classifications.manage', 'a6.manage', 'users.manage', 'audit.view',
  ],
  staff: [
    'dashboard.view', 'residents.view', 'residents.create', 'residents.edit',
    'households.view', 'households.manage', 'relationships.manage', 'puroks.view',
    'classifications.manage', 'a6.manage',
  ],
  treasurer: ['dashboard.view', 'residents.view'],
  health_worker: [
    'dashboard.view', 'residents.view', 'residents.edit_health_fields',
    'households.view', 'puroks.view', 'classifications.manage', 'a6.manage_health_linked',
  ],
};

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<StaffUser | null>(() => {
    const stored = localStorage.getItem('bris_user');
    return stored ? (JSON.parse(stored) as StaffUser) : null;
  });
  const [loading, setLoading] = useState(true);

  const fetchMe = async () => {
    const token = localStorage.getItem('bris_token');
    if (!token) {
      setLoading(false);
      return;
    }
    try {
      const { data } = await client.get('/admin/me');
      setUser(data.user);
      localStorage.setItem('bris_user', JSON.stringify(data.user));
    } catch {
      localStorage.removeItem('bris_token');
      localStorage.removeItem('bris_user');
      setUser(null);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchMe();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const login = async (loginValue: string, password: string) => {
    const { data } = await client.post('/admin/login', { login: loginValue, password });
    localStorage.setItem('bris_token', data.token);
    localStorage.setItem('bris_user', JSON.stringify(data.user));
    setUser(data.user);
    return data.user as StaffUser;
  };

  const logout = async () => {
    try {
      await client.post('/admin/logout');
    } finally {
      localStorage.removeItem('bris_token');
      localStorage.removeItem('bris_user');
      setUser(null);
    }
  };

  const hasPermission = (permission: string) => {
    if (!user) return false;
    return ROLE_PERMISSIONS[user.role]?.includes(permission) ?? false;
  };

  const value = useMemo(
    () => ({ user, loading, login, logout, hasPermission, refreshUser: fetchMe }),
    [user, loading]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
