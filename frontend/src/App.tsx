import { BrowserRouter, Route, Routes } from 'react-router-dom';
import Layout from './components/Layout';
import ProtectedRoute from './components/ProtectedRoute';
import { AuthProvider } from './context/AuthContext';
import ChangePasswordPage from './pages/ChangePasswordPage';
import DashboardPage from './pages/DashboardPage';
import HouseholdCreatePage from './pages/HouseholdCreatePage';
import HouseholdDetailPage from './pages/HouseholdDetailPage';
import HouseholdsListPage from './pages/HouseholdsListPage';
import LoginPage from './pages/LoginPage';
import PuroksPage from './pages/PuroksPage';
import ResidentCreatePage from './pages/ResidentCreatePage';
import ResidentDetailPage from './pages/ResidentDetailPage';
import ResidentsListPage from './pages/ResidentsListPage';

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/change-password" element={<ChangePasswordPage />} />
          <Route
            element={
              <ProtectedRoute>
                <Layout />
              </ProtectedRoute>
            }
          >
            <Route path="/" element={<DashboardPage />} />
            <Route path="/residents" element={<ResidentsListPage />} />
            <Route path="/residents/new" element={<ResidentCreatePage />} />
            <Route path="/residents/:id" element={<ResidentDetailPage />} />
            <Route path="/households" element={<HouseholdsListPage />} />
            <Route path="/households/new" element={<HouseholdCreatePage />} />
            <Route path="/households/:id" element={<HouseholdDetailPage />} />
            <Route path="/puroks" element={<PuroksPage />} />
          </Route>
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
