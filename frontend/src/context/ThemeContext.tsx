import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';

interface ThemeContextValue {
  dark: boolean;
  toggle: () => void;
}

const ThemeContext = createContext<ThemeContextValue | undefined>(undefined);

function getInitialDark(): boolean {
  try {
    const stored = localStorage.getItem('bris_theme');
    if (stored) return stored === 'dark';
  } catch {
    // localStorage unavailable (private mode, etc.) — fall through to system preference.
  }
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [dark, setDark] = useState(getInitialDark);

  useEffect(() => {
    document.documentElement.classList.toggle('dark', dark);
    try {
      localStorage.setItem('bris_theme', dark ? 'dark' : 'light');
    } catch {
      // per-viewer convenience only; fine if it doesn't persist.
    }
  }, [dark]);

  const toggle = () => setDark((d) => !d);

  return <ThemeContext.Provider value={{ dark, toggle }}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeContextValue {
  const ctx = useContext(ThemeContext);
  if (!ctx) throw new Error('useTheme must be used within ThemeProvider');
  return ctx;
}
