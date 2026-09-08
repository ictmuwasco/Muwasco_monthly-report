import { useEffect, useState } from 'react';
import { api, apiError, errorMap } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import { useToasts, Toaster } from '../components/Toasts';
import { Spinner, EmptyState, Pagination, FieldErrors } from '../components/Ui';

export default function Users() {
  const { user: me } = useAuth();
  const { toasts, push } = useToasts();
  const [rows, setRows] = useState(null);
  const [meta, setMeta] = useState(null);
  const [search, setSearch] = useState('');
  const [role, setRole] = useState('');
  const [page, setPage] = useState(1);

  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ username: '', email: '', full_name: '', password: '', role: 'user' });
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const [pwUser, setPwUser] = useState(null);
  const [pw, setPw] = useState('');

  const load = () => {
    const params = { page };
    if (search) params.search = search;
    if (role) params.role = role;
    setRows(null);
    api.get('/users', { params }).then(({ data }) => {
      setRows(data.data ?? []);
      setMeta(data.meta ?? null);
    }).catch((e) => push(apiError(e), 'error'));
  };

  useEffect(() => {
    const t = setTimeout(load, search ? 250 : 0);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, search, role]);

  const create = async (e) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api.post('/users', {
        username: form.username, email: form.email || null,
        full_name: form.full_name, password: form.password, role: form.role,
      });
      push('User created.');
      setShowForm(false);
      load();
    } catch (err) {
      setErrors(errorMap(err));
    } finally {
      setSaving(false);
    }
  };

  const toggleActive = async (u) => {
    try {
      const action = u.is_active ? 'deactivate' : 'activate';
      await api.post(`/users/${u.id}/${action}`);
      push(`User ${action}d.`);
      load();
    } catch (e) {
      push(apiError(e), 'error');
    }
  };

  const resetPassword = async (e) => {
    e.preventDefault();
    try {
      await api.post(`/users/${pwUser.id}/password`, { password: pw, password_confirmation: pw });
      push('Password updated.');
      setPwUser(null);
      setPw('');
    } catch (err) {
      push(apiError(err), 'error');
    }
  };

  return (
    <div className="space-y-5">
      <Toaster toasts={toasts} />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Users</h1>
          <p className="text-sm text-gray-500">Manage system users, roles and access.</p>
        </div>
        <button className="btn btn-primary" onClick={() => { setForm({ username: '', email: '', full_name: '', password: '', role: 'user' }); setErrors({}); setShowForm(true); }}>
          + New user
        </button>
      </div>

      <div className="card flex flex-wrap items-end gap-3 p-4">
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-600">Search</label>
          <input className="input" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name, username, email" />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-600">Role</label>
          <select className="input" value={role} onChange={(e) => setRole(e.target.value)}>
            <option value="">All</option>
            <option value="admin">Admin</option>
            <option value="user">User</option>
          </select>
        </div>
      </div>
      <div className="card overflow-hidden">
        {rows === null ? <Spinner /> : rows.length === 0 ? (
          <EmptyState title="No users found" />
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="th">Username</th>
                <th className="th">Full name</th>
                <th className="th">Email</th>
                <th className="th">Role</th>
                <th className="th">Status</th>
                <th className="th">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {rows.map((u) => (
                <tr key={u.id} className="hover:bg-gray-50">
                  <td className="td font-medium text-gray-900">{u.username}</td>
                  <td className="td">{u.full_name}</td>
                  <td className="td">{u.email ?? '—'}</td>
                  <td className="td">
                    <span className={`badge ${u.role === 'admin' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-700'}`}>{u.role}</span>
                  </td>
                  <td className="td">
                    <span className={`badge ${u.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                      {u.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </td>
                  <td className="td">
                    <div className="flex flex-wrap gap-2">
                      <button className="text-xs font-medium text-blue-600 hover:underline" onClick={() => { setPwUser(u); setPw(''); }}>Reset password</button>
                      {me.id !== u.id && (
                        <button className="text-xs font-medium text-red-600 hover:underline" onClick={() => toggleActive(u)}>
                          {u.is_active ? 'Deactivate' : 'Activate'}
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Pagination meta={meta} onPage={setPage} />
      </div>

      {showForm && (
        <Modal title="New user" onClose={() => setShowForm(false)}>
          <FieldErrors errors={errors} />
          <form onSubmit={create} className="space-y-4">
            <Field label="Username"><input className="input" required value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} /></Field>
            <Field label="Full name"><input className="input" required value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} /></Field>
            <Field label="Email (optional)"><input className="input" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></Field>
            <Field label="Password (min 8)"><input className="input" type="password" required value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} /></Field>
            <Field label="Role">
              <select className="input" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
                <option value="user">User</option>
                <option value="admin">Admin</option>
              </select>
            </Field>
            <div className="flex justify-end gap-2">
              <button type="button" className="btn btn-secondary" onClick={() => setShowForm(false)}>Cancel</button>
              <button className="btn btn-primary" disabled={saving}>{saving ? 'Creating…' : 'Create'}</button>
            </div>
          </form>
        </Modal>
      )}

      {pwUser && (
        <Modal title={`Reset password: ${pwUser.username}`} onClose={() => setPwUser(null)}>
          <form onSubmit={resetPassword} className="space-y-4">
            <Field label="New password (min 8)"><input className="input" type="password" required value={pw} onChange={(e) => setPw(e.target.value)} /></Field>
            <div className="flex justify-end gap-2">
              <button type="button" className="btn btn-secondary" onClick={() => setPwUser(null)}>Cancel</button>
              <button className="btn btn-primary">Reset</button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  );
}

function Modal({ title, children, onClose }) {
  return (
    <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div className="card w-full max-w-lg p-6" onClick={(e) => e.stopPropagation()}>
        {title && <h2 className="mb-4 text-lg font-semibold text-gray-800">{title}</h2>}
        {children}
      </div>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <div>
      <label className="mb-1 block text-sm font-medium text-gray-700">{label}</label>
      {children}
    </div>
  );
}