import { NavLink as RRNavLink, Outlet, Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { Spinner } from './Ui';

const adminLinks = [
  { to: '/', label: 'Dashboard', icon: '🏠' },
  { to: '/periods', label: 'Reporting Periods', icon: '📅' },
  { to: '/data-entry', label: 'Data Entry', icon: '✍️' },
  { to: '/reports', label: 'Reports', icon: '📄' },
  { to: '/approvals', label: 'Approvals', icon: '✅' },
  { to: '/users', label: 'Users', icon: '👥' },
  { to: '/assignments', label: 'Assignments', icon: '🔗' },
  { to: '/parameters', label: 'Parameters', icon: '🧮' },
  { to: '/categories', label: 'Categories', icon: '🗂️' },
];

const userLinks = [
  { to: '/', label: 'Dashboard', icon: '🏠' },
  { to: '/data-entry', label: 'Data Entry', icon: '✍️' },
  { to: '/reports', label: 'Reports', icon: '📄' },
];

function SidebarLink({ to, children }) {
  return (
    <RRNavLink
      to={to}
      className={({ isActive }) =>
        `flex items-center rounded-md px-3 py-2 text-sm font-medium transition ${
          isActive ? 'bg-blue-700 text-white' : 'text-blue-100 hover:bg-blue-700/50'
        }`
      }
    >
      {children}
    </RRNavLink>
  );
}

export default function Layout() {
  const { user, loading, logout } = useAuth();

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-gray-100">
        <Spinner label="Checking session…" />
      </div>
    );
  }

  if (!user) return <Navigate to="/login" replace />;

  const isAdmin = user.role === 'admin';
  const links = isAdmin ? adminLinks : userLinks;

  const handleLogout = (e) => {
    e.preventDefault();
    logout();
  };

  return (
    <div className="flex min-h-screen bg-gray-100">
      {/* Sidebar */}
      <aside className="fixed inset-y-0 left-0 z-30 flex w-60 flex-col bg-gray-900 text-white">
        <div className="flex items-center gap-2 border-b border-gray-700 px-5 py-4">
          <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-600 font-bold">
            M
          </span>
          <div className="leading-tight">
            <p className="text-sm font-semibold">MUWASCO</p>
            <p className="text-xs text-blue-300">Reporting</p>
          </div>
        </div>
        <div className="flex-1 space-y-1 overflow-y-auto px-2 py-2">
          {links.map((l) => (
            <SidebarLink key={l.to} to={l.to}>
              <span className="mr-3 opacity-80">{l.icon}</span>
              {l.label}
            </SidebarLink>
          ))}
        </div>
        <div className="border-t border-gray-700 p-4">
          <p className="truncate text-sm font-medium text-gray-200">{user.full_name || user.username}</p>
          <p className="text-xs text-gray-400">{user.role}</p>
          <button
            onClick={handleLogout}
            className="mt-3 w-full rounded-md bg-gray-700 px-3 py-2 text-sm text-gray-200 hover:bg-gray-600"
          >
            Sign out
          </button>
        </div>
      </aside>

      {/* Main */}
      <main className="ml-60 flex-1 p-6">
        <Outlet />
      </main>
    </div>
  );
}