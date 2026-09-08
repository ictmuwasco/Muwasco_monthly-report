import { useState } from 'react';
import { useNavigate, Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { apiError } from '../lib/api';
import { FieldErrors } from '../components/Ui';

export default function Login() {
  const { user, login } = useAuth();
  const navigate = useNavigate();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  if (user) return <Navigate to="/" replace />;

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      await login(username, password);
      navigate('/', { replace: true });
    } catch (err) {
      const map = {};
      const data = err?.response?.data;
      if (data?.errors) Object.assign(map, data.errors);
      else map._server = [apiError(err)];
      setErrors(map);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="relative flex min-h-screen items-center justify-center bg-gradient-to-br from-slate-50 via-gray-50 to-blue-50 p-4">
      {/* Background pattern */}
      <div className="absolute inset-0 overflow-hidden">
        <div className="absolute -top-40 -left-40 h-80 w-80 rounded-full bg-blue-200/30 blur-3xl"></div>
        <div className="absolute -bottom-40 -right-40 h-80 w-80 rounded-full bg-indigo-200/30 blur-3xl"></div>
        <div className="absolute top-1/3 left-1/4 h-2 w-2 rounded-full bg-blue-400/40"></div>
        <div className="absolute top-2/3 right-1/3 h-3 w-3 rounded-full bg-indigo-400/40"></div>
        <div className="absolute bottom-1/4 left-1/3 h-1 w-1 rounded-full bg-gray-300/50"></div>
      </div>

      <div className="relative w-full max-w-md">
        {/* Logo & header */}
        <div className="mb-8 text-center">
          <span className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 text-3xl font-bold text-white shadow-lg ring-4 ring-blue-100">
            M
          </span>
          <h1 className="mt-5 text-3xl font-bold text-gray-900">MUWASCO Reporting</h1>
          <p className="mt-2 text-sm text-gray-500">Monthly Performance Reporting System</p>
          <p className="mt-1 text-xs text-gray-400">ATHI WATER WORKS DEVELOPMENT AGENCY</p>
        </div>

        {/* Login card */}
        <div className="card border border-gray-200/60 p-8 shadow-xl backdrop-blur-sm">
          <div className="mb-6">
            <h2 className="text-2xl font-bold text-gray-900">Welcome back</h2>
            <p className="mt-1 text-sm text-gray-500">Sign in to your account to continue</p>
          </div>

          <FieldErrors errors={errors} />

          <form onSubmit={submit} className="space-y-5">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Username or email
              </label>
              <div className="relative">
                <input
                  className="input pl-10"
                  placeholder="Enter your username or email"
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  autoComplete="username"
                  autoFocus
                  required
                />
                <svg
                  className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  strokeWidth={2}
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M16 12H8m0 0l3-3m-3 3l3 3"
                  />
                </svg>
              </div>
            </div>

            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Password
              </label>
              <div className="relative">
                <input
                  className="input pl-10"
                  type="password"
                  placeholder="Enter your password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  autoComplete="current-password"
                  required
                />
                <svg
                  className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  strokeWidth={2}
                >
                  <rect x="5" y="11" width="14" height="10" rx="2" ry="2" />
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 11V7a4 4 0 10-8 0v4" />
                </svg>
              </div>
            </div>

            <button
              className="btn btn-primary w-full py-2.5 text-base shadow-md transition-all hover:shadow-lg"
              disabled={busy}
            >
              {busy ? (
                <div className="flex items-center justify-center gap-2">
                  <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                  </svg>
                  <span>Signing in…</span>
                </div>
              ) : (
                'Sign in'
              )}
            </button>
          </form>
        </div>

        {/* Footer */}
        <div className="mt-6 text-center">
          <p className="text-xs text-gray-400">
            MUWASCO · Monthly Reporting Portal · v2.0
          </p>
        </div>
      </div>
    </div>
  );
}
