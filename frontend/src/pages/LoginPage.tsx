import { motion } from 'framer-motion';
import { useState, type FormEvent } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { HouseholdsIcon, PuroksIcon, ResidentsIcon } from '../components/icons';
import TailwindPasswordInput from '../components/TailwindPasswordInput';
import ThemeToggleButton from '../components/ThemeToggleButton';
import { useAuth } from '../context/AuthContext';

const FEATURES = [
  { icon: ResidentsIcon, label: 'Resident registry' },
  { icon: HouseholdsIcon, label: 'Household management' },
  { icon: PuroksIcon, label: 'Purok statistics' },
];

export default function LoginPage() {
  const [login, setLogin] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const { login: doLogin } = useAuth();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const justVerified = searchParams.get('verified') === '1';

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const user = await doLogin(login, password);
      navigate(user.must_change_password ? '/change-password' : '/');
    } catch (err: any) {
      setError(err?.response?.data?.message ?? 'Login failed.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="relative flex min-h-screen bg-bg-light font-sans transition-colors duration-300 dark:bg-bg-dark">
      <ThemeToggleButton className="absolute right-4 top-4 z-10 text-gray-500 dark:text-gray-300" />

      <div className="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-charcoal p-12 text-white lg:flex">
        {/* Decorative — a flat charcoal panel with no texture read as a
            placeholder rather than a designed surface. A radial glow plus a
            faint dot grid gives it depth without competing with the text. */}
        <div className="pointer-events-none absolute inset-0">
          <div
            className="absolute inset-0 opacity-[0.07]"
            style={{
              backgroundImage: 'radial-gradient(circle, white 1px, transparent 1px)',
              backgroundSize: '28px 28px',
            }}
          />
          <div className="absolute -right-32 -top-32 h-96 w-96 rounded-full bg-primary/20 blur-3xl" />
          <div className="absolute -bottom-40 -left-20 h-80 w-80 rounded-full bg-primary/10 blur-3xl" />
        </div>

        <motion.div
          initial={{ opacity: 0, y: -8 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.4 }}
          className="relative flex items-center gap-3"
        >
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary text-lg font-bold text-charcoal">B</div>
          <span className="text-xl font-semibold">BRIS</span>
        </motion.div>

        <motion.div
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.5, delay: 0.1 }}
          className="relative"
        >
          <h1 className="text-3xl font-semibold leading-tight">Barangay Resident Information System</h1>
          <p className="mt-3 max-w-sm text-white/80">
            One registry for residents, households, families, and purok statistics — Barangay San Isidro, Bohol.
          </p>

          <ul className="mt-8 space-y-3">
            {FEATURES.map((f, i) => (
              <motion.li
                key={f.label}
                initial={{ opacity: 0, x: -10 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ duration: 0.35, delay: 0.2 + i * 0.08 }}
                className="flex items-center gap-3 text-sm text-white/90"
              >
                <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10">
                  <f.icon width={16} height={16} />
                </span>
                {f.label}
              </motion.li>
            ))}
          </ul>
        </motion.div>

        <p className="relative text-sm text-white/60">© {new Date().getFullYear()} Barangay San Isidro</p>
      </div>

      <div className="flex w-full flex-1 items-center justify-center px-6 py-12">
        <motion.form
          initial={{ opacity: 0, y: 16 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.4 }}
          onSubmit={handleSubmit}
          className="w-full max-w-sm rounded-2xl border border-gray-200 bg-white p-8 shadow-sm transition-colors duration-300 dark:border-white/10 dark:bg-charcoal"
        >
          <h2 className="text-2xl font-semibold text-charcoal dark:text-gray-50">Staff Login</h2>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Sign in to your BRIS account</p>

          {justVerified && !error && (
            <div className="mt-4 rounded-lg border border-success/30 bg-success/10 px-3.5 py-2.5 text-sm text-success-strong dark:text-success">
              Email verified. You can sign in once the secretary approves your account.
            </div>
          )}

          <div className="mt-6 space-y-4">
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
              Username or Email
              <input
                value={login}
                onChange={(e) => setLogin(e.target.value)}
                required
                autoFocus
                className="mt-1.5 w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none focus:border-primary focus:ring-1 focus:ring-primary dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
              />
            </label>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
              Password
              <div className="mt-1.5">
                <TailwindPasswordInput value={password} onChange={setPassword} required />
              </div>
              {error && <p className="mt-1.5 text-sm text-error-strong dark:text-error">{error}</p>}
            </label>
          </div>

          <button
            type="submit"
            disabled={submitting}
            className="mt-6 w-full rounded-lg bg-primary py-2.5 text-sm font-semibold text-charcoal transition-colors hover:bg-primary-dark hover:text-white disabled:opacity-60"
          >
            {submitting ? 'Signing in…' : 'Sign in'}
          </button>

          <p className="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
            Need an account? Ask the Barangay Captain to create one for you.
          </p>
        </motion.form>
      </div>
    </div>
  );
}
