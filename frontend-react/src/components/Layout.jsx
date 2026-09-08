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
          isActive
            ? 'bg-ocean-500/90 text-white shadow-sm shadow-ocean-900/50'
            : 'text-ocean-100/80 hover:bg-white/10 hover:text-white'
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
      <div className="flex min-h-screen items-center justify-center bg-deep-50">
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
    <div className="flex min-h-screen bg-deep-50">
      {/* Sidebar — deep ocean gradient */}
      <aside className="fixed inset-y-0 left-0 z-30 flex w-60 flex-col bg-gradient-to-b from-deep-950 via-ocean-950 to-ocean-900 text-white">
        {/* Brand */}
        <div className="flex items-center gap-3 border-b border-white/10 px-5 py-4">
          <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-ocean-400 to-ocean-600 font-bold shadow-md shadow-ocean-500/30">
            M
          </span>
          <div className="leading-tight">
            <p className="text-sm font-semibold tracking-wide">MUWASCO</p>
            <p className="text-xs text-ocean-300">Reporting</p>
          </div>
        </div>

        {/* Nav */}
        <div className="flex-1 space-y-1 overflow-y-auto px-2 py-3">
          {links.map((l) => (
            <SidebarLink key={l.to} to={l.to}>
              <span className="mr-3 opacity-80">{l.icon}</span>
              {l.label}
            </SidebarLink>
          ))}
        </div>

        {/* User footer */}
        <div className="border-t border-white/10 p-4">
          <p className="truncate text-sm font-medium text-white">{user.full_name || user.username}</p>
          <p className="text-xs capitalize text-ocean-300">{user.role}</p>
          <div className="mt-3 flex gap-2">
            <button
              onClick={handleLogout}
              className="flex-1 rounded-md bg-white/10 px-3 py-2 text-sm text-ocean-100 transition hover:bg-white/20"
            >
              Sign out
            </button>
            <RRNavLink
              to="/change-password"
              title="Change password"
              className={({ isActive }) =>
                `flex items-center justify-center rounded-md px-3 py-2 text-sm font-medium transition ${
                  isActive ? 'bg-ocean-500 text-white' : 'bg-white/10 text-ocean-100 hover:bg-white/20'
                }`
              }
            >
              🔑
            </RRNavLink>
          </div>
        </div>
      </aside>

      {/* Main */}
      <main className="ml-60 flex-1 p-6">
        <Outlet />
      </main>
    </div>
  );
}