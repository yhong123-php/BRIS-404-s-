import { useAuth } from '../context/AuthContext';

export default function DashboardPage() {
  const { user } = useAuth();

  return (
    <div>
      <h2>Welcome, {user?.full_name}</h2>
      <p>
        This is the Foundation + A-series vertical slice: authentication, resident registration,
        household management, family relationships, purok monitoring, sectoral classification, and
        the skills/achievements registry. The full C1 dashboard (snapshots, action list) lands with
        the C-series.
      </p>
    </div>
  );
}
