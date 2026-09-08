import { useState } from 'react';
import { useNavigate, Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { errorMap } from '../lib/api';
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
      setErrors(errorMap(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="relative flex min-h-screen items-center justify-center bg-gradient-to-br from-ocean-50 via-deep-50 to-ocean-100 p-4">
      {/* Background: ocean depth orbs + wave motif */}
      <div className="absolute inset-0 overflow-hidden">
        <div className="absolute -top-40 -left-40 h-96 w-96 rounded-full bg-ocean-200/40 blur-3xl"></div>
        <div className="absolute -bottom-40 -right-40 h-96 w-96 rounded-full bg-aqua-200/40 blur-3xl"></div>
        <div className="absolute top-1/3 left-1/4 h-2 w-2 rounded-full bg-ocean-300/50"></div>
        <div className="absolute top-2/3 right-1/3 h-3 w-3 rounded-full bg-aqua-300/50"></div>
        <div className="absolute bottom-1/4 left-1/3 h-1 w-1 rounded-full bg-ocean-200/60"></div>
        {/* Stylised ocean waves along the bottom */}
        <svg
          className="absolute bottom-0 left-0 w-full text-ocean-200/50"
          viewBox="0 0 1440 120"
          fill="currentColor"
          preserveAspectRatio="none"
        >
          <path d="M0,64 C240,110 480,10 720,54 C960,98 1200,32 1440,70 L1440,120 L0,120 Z" />
        </svg>
        <svg
          className="absolute bottom-0 left-0 w-full text-ocean-300/40"
          viewBox="0 0 1440 90"
          fill="currentColor"
          preserveAspectRatio="none"
        >
          <path d="M0,50 C260,90 520,4 780,40 C1040,76 1240,20 1440,55 L1440,90 L0,90 Z" />
        </svg>
      </div>

      <div className="relative w-full max-w-md">
        {/* Logo & header */}
        <div className="mb-8 text-center">
          <span className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-ocean-500 to-ocean-800 text-3xl font-bold text-white shadow-lg shadow-ocean-600/30 ring-4 ring-ocean-100">
            M
          </span>
          <h1 className="mt-5 text-3xl font-bold text-ocean-950">MUWASCO Reporting</h1>
          <p className="mt-2 text-sm text-deep-500">Monthly Performance Reporting System</p>
          <p className="mt-1 text-xs text-deep-400">ATHI WATER WORKS DEVELOPMENT AGENCY</p>
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
