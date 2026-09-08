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
    <div className="flex min-h-screen items-center justify-center bg-gray-100 p-4">
      <div className="w-full max-w-md">
        <div className="mb-6 text-center">
          <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-xl bg-blue-600 text-2xl font-bold text-white">
            M
          </span>
          <h1 className="mt-4 text-2xl font-bold text-gray-900">MUWASCO Reporting</h1>
          <p className="mt-1 text-sm text-gray-500">
            Monthly performance reporting system
          </p>
        </div>

        <div className="card p-6">
          <h2 className="mb-4 text-lg font-semibold text-gray-800">Sign in</h2>
          <FieldErrors errors={errors} />
          <form onSubmit={submit} className="space-y-4">
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">Username or email</label>
              <input
                className="input"
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                autoComplete="username"
                autoFocus
                required
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">Password</label>
              <input
                className="input"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="current-password"
                required
              />
            </div>
            <button className="btn btn-primary w-full" disabled={busy}>
              {busy ? 'Signing in…' : 'Sign in'}
            </button>
          </form>
        </div>

        <p className="mt-4 text-center text-xs text-gray-400">
          MUWASCO · ATHI WATER WORKS DEVELOPMENT AGENCY
        </p>
      </div>
    </div>
  );
}