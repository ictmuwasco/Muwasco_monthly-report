import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { api, apiError } from '../lib/api';
import { FieldErrors } from '../components/Ui';

export default function ChangePassword() {
  const { user, setUser } = useAuth();
  const navigate = useNavigate();
  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      await api.post('/auth/change-password', {
        current_password: currentPassword,
        password,
        password_confirmation: confirmation,
      });
      // Flag is cleared server-side; update local state and continue.
      setUser({ ...user, must_change_password: false });
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

  const inputCls =
    'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100';

  return (
    <div className="flex min-h-screen items-center justify-center bg-gray-100 p-4">
      <div className="w-full max-w-md">
        <div className="card p-6">
          <h1 className="text-xl font-bold text-gray-900">Change your password</h1>
          <p className="mt-1 mb-5 text-sm text-gray-500">
            For security, you must set a new password before continuing.
          </p>

          <FieldErrors errors={errors} />

          <form onSubmit={submit} className="space-y-4">
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">
                Current password
              </label>
              <input
                className={inputCls}
                type="password"
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
                autoComplete="current-password"
                autoFocus
                required
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">
                New password
              </label>
              <input
                className={inputCls}
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="new-password"
                required
              />
              <p className="mt-1 text-xs text-gray-400">
                At least 8 characters, with letters and numbers.
              </p>
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">
                Confirm new password
              </label>
              <input
                className={inputCls}
                type="password"
                value={confirmation}
                onChange={(e) => setConfirmation(e.target.value)}
                autoComplete="new-password"
                required
              />
            </div>
            <button className="btn btn-primary w-full" disabled={busy}>
              {busy ? 'Saving…' : 'Save new password'}
            </button>
          </form>
        </div>
        <p className="mt-4 text-center text-xs text-gray-400">
          Signed in as {user?.username}
        </p>
      </div>
    </div>
  );
}
