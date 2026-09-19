import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function Layout() {
  const { user, logout, hasPermission } = useAuth();
  const navigate = useNavigate();

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  return (
    <div className="app-shell">
      <header className="app-header">
        <h1>BRIS — Barangay San Isidro</h1>
        <div className="app-header-user">
          <span>{user?.full_name} ({user?.role})</span>
          <button onClick={handleLogout}>Log out</button>
        </div>
      </header>
      <div className="app-body">
        <nav className="app-nav">
          {hasPermission('dashboard.view') && <NavLink to="/">Dashboard</NavLink>}
          {hasPermission('residents.view') && <NavLink to="/residents">Residents</NavLink>}
          {hasPermission('households.view') && <NavLink to="/households">Households</NavLink>}
          {hasPermission('puroks.view') && <NavLink to="/puroks">Puroks</NavLink>}
        </nav>
        <main className="app-main">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
