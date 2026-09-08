import { Routes, Route, Navigate } from 'react-router-dom';
import Layout from './components/Layout';
import Login from './pages/Login';
import ChangePassword from './pages/ChangePassword';
import Dashboard from './pages/Dashboard';
import ReportingPeriods from './pages/ReportingPeriods';
import DataEntry from './pages/DataEntry';
import Reports from './pages/Reports';
import Approvals from './pages/Approvals';
import Users from './pages/Users';
import Assignments from './pages/Assignments';
import Parameters from './pages/Parameters';
import Categories from './pages/Categories';
import { useAuth } from './context/AuthContext';
import { Spinner } from './components/Ui';

function RequireAuth({ children }) {
  const { user, loading } = useAuth();
  if (loading) return <Spinner label="Loading…" />;
  if (!user) return <Navigate to="/login" replace />;
  // Forced password change: block everything until the user sets a new password.
  if (user.must_change_password) return <Navigate to="/change-password" replace />;
  return children;
}

function RequireAdmin({ children }) {
  const { user } = useAuth();
  if (user?.role !== 'admin') return <Navigate to="/" replace />;
  return children;
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/change-password" element={<ChangePassword />} />
      <Route
        element={
          <RequireAuth>
            <Layout />
          </RequireAuth>
        }
      >
        <Route path="/" element={<Dashboard />} />
        <Route path="/periods" element={<ReportingPeriods />} />
        <Route path="/data-entry" element={<DataEntry />} />
        <Route path="/reports" element={<Reports />} />
        <Route path="/approvals" element={<Approvals />} />
        <Route
          path="/users"
          element={
            <RequireAdmin>
              <Users />
            </RequireAdmin>
          }
        />
        <Route
          path="/assignments"
          element={
            <RequireAdmin>
              <Assignments />
            </RequireAdmin>
          }
        />
        <Route
          path="/parameters"
          element={
            <RequireAdmin>
              <Parameters />
            </RequireAdmin>
          }
        />
        <Route
          path="/categories"
          element={
            <RequireAdmin>
              <Categories />
            </RequireAdmin>
          }
        />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}