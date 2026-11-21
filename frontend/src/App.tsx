import { MotionConfig } from 'framer-motion';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import Layout from './components/Layout';
import ProtectedRoute from './components/ProtectedRoute';
import { AuthProvider } from './context/AuthContext';
import { DialogProvider } from './context/DialogContext';
import { ThemeProvider } from './context/ThemeContext';
import { ToastProvider } from './context/ToastContext';
import AiSuggestionsPage from './pages/AiSuggestionsPage';
import AssistantAccessPage from './pages/AssistantAccessPage';
import BackupsPage from './pages/BackupsPage';
import ForecastsPage from './pages/ForecastsPage';
import OpportunitiesPage from './pages/OpportunitiesPage';
import PopulationEventsPage from './pages/PopulationEventsPage';
import PrivacyPage from './pages/PrivacyPage';
import RecordCopyPrintPage from './pages/RecordCopyPrintPage';
import { ReliefDetailPage, ReliefListPage } from './pages/ReliefPage';
import ReportGeneratorPage from './pages/ReportGeneratorPage';
import UsersPage from './pages/UsersPage';
import AssistantPage from './pages/AssistantPage';
import AuditLogsPage from './pages/AuditLogsPage';
import ChangePasswordPage from './pages/ChangePasswordPage';
import DashboardPage from './pages/DashboardPage';
import FamilyDetailPage from './pages/FamilyDetailPage';
import HouseholdCreatePage from './pages/HouseholdCreatePage';
import HouseholdDetailPage from './pages/HouseholdDetailPage';
import LoginPage from './pages/LoginPage';
import PurokStatisticsPage from './pages/PurokStatisticsPage';
import ReportsPage from './pages/ReportsPage';
import ResidentCreatePage from './pages/ResidentCreatePage';
import ResidentDetailPage from './pages/ResidentDetailPage';
import RegistryPage, { RegistryRedirect } from './pages/RegistryPage';

export default function App() {
  return (
    // reducedMotion="user": entrance/slide animations are skipped for people who turn motion off in their OS.
    <MotionConfig reducedMotion="user">
    <BrowserRouter>
      <ThemeProvider>
        <ToastProvider>
          <DialogProvider>
          <AuthProvider>
            <Routes>
              <Route path="/login" element={<LoginPage />} />
              <Route path="/change-password" element={<ChangePasswordPage />} />
              {/* Printable documents render without the app shell. */}
              <Route path="/record-copy/print" element={<ProtectedRoute><RecordCopyPrintPage /></ProtectedRoute>} />
              <Route
                element={
                  <ProtectedRoute>
                    <Layout />
                  </ProtectedRoute>
                }
              >
                <Route path="/" element={<DashboardPage />} />
                <Route path="/residents" element={<RegistryPage />} />
                <Route path="/residents/new" element={<ResidentCreatePage />} />
                <Route path="/residents/:id" element={<ResidentDetailPage />} />
                <Route path="/residents/:id/edit" element={<ResidentCreatePage />} />
                <Route path="/households" element={<RegistryRedirect tab="households" />} />
                <Route path="/households/new" element={<HouseholdCreatePage />} />
                <Route path="/households/:id" element={<HouseholdDetailPage />} />
                <Route path="/families" element={<RegistryRedirect tab="families" />} />
                <Route path="/families/:id" element={<FamilyDetailPage />} />
                <Route path="/puroks" element={<RegistryRedirect tab="puroks" />} />
                <Route path="/puroks/:id" element={<PurokStatisticsPage />} />
                <Route path="/reports" element={<ReportsPage />} />
                <Route path="/audit-logs" element={<AuditLogsPage />} />
                <Route path="/assistant" element={<AssistantPage />} />
                <Route path="/assistant-access" element={<AssistantAccessPage />} />
                <Route path="/relief" element={<ReliefListPage />} />
                <Route path="/relief/:id" element={<ReliefDetailPage />} />
                <Route path="/population-events" element={<PopulationEventsPage />} />
                <Route path="/privacy" element={<PrivacyPage />} />
                <Route path="/ai-suggestions" element={<AiSuggestionsPage />} />
                <Route path="/opportunities" element={<OpportunitiesPage />} />
                <Route path="/forecasts" element={<ForecastsPage />} />
                <Route path="/report-generator" element={<ReportGeneratorPage />} />
                <Route path="/users" element={<UsersPage />} />
                <Route path="/backups" element={<BackupsPage />} />
              </Route>
            </Routes>
          </AuthProvider>
          </DialogProvider>
        </ToastProvider>
      </ThemeProvider>
    </BrowserRouter>
    </MotionConfig>
  );
}
