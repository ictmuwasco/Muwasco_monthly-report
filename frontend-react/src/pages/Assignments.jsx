import { useEffect, useState } from 'react';
import { api, apiError } from '../lib/api';
import { useToasts, Toaster } from '../components/Toasts';
import { Spinner, EmptyState } from '../components/Ui';

export default function Assignments() {
  const { toasts, push } = useToasts();

  const [users, setUsers] = useState([]);
  const [allParams, setAllParams] = useState([]);
  const [allCats, setAllCats] = useState([]);
  const [userId, setUserId] = useState('');
  const [assign, setAssign] = useState({ parameters: [], categories: [] });
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(null);

  useEffect(() => {
    api.get('/users?per_page=100').then(({ data }) => setUsers(data.data ?? [])).catch(() => {});
    api.get('/parameters?per_page=100').then(({ data }) => setAllParams(data.data ?? [])).catch(() => {});
    api.get('/parameter-categories?per_page=100').then(({ data }) => setAllCats(data.data ?? [])).catch(() => {});
  }, []);

  const load = (id) => {
    if (!id) return;
    setLoading(true);
    api
      .get(`/users/${id}/assignments`)
      .then(({ data }) => {
        setAssign({
          parameters: data.parameters.map((p) => p.id),
          categories: data.categories.map((c) => c.id),
        });
      })
      .catch((e) => push(apiError(e), 'error'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    load(userId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [userId]);

  const toggleId = (list, id) =>
    list.includes(id) ? list.filter((x) => x !== id) : [...list, id];

  const save = async (kind) => {
    setSaving(kind);
    try {
      if (kind === 'parameters') {
        await api.put(`/users/${userId}/parameters`, { parameter_ids: assign.parameters });
        push('Parameter assignments updated.');
      } else {
        await api.put(`/users/${userId}/categories`, { category_ids: assign.categories });
        push('Category assignments updated.');
      }
    } catch (e) {
      push(apiError(e), 'error');
    } finally {
      setSaving(null);
    }
  };

  return (
    <div className="space-y-5">
      <Toaster toasts={toasts} />
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Assignments</h1>
        <p className="text-sm text-gray-500">Control which parameters and categories each user can enter data for.</p>
      </div>

      <div className="card p-4">
        <label className="mb-1 block text-sm font-medium text-gray-700">User</label>
        <select className="input max-w-md" value={userId} onChange={(e) => setUserId(e.target.value)}>
          <option value="">Select a user…</option>
          {users.map((u) => (
            <option key={u.id} value={u.id}>{u.full_name || u.username} ({u.username})</option>
          ))}
        </select>
      </div>

      {!userId && <EmptyState title="Select a user" message="Choose a user to manage their data access." />}

      {userId && loading && <Spinner label="Loading assignments…" />}

      {userId && !loading && (
        <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
          <div className="card overflow-hidden">
            <div className="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-3">
              <h3 className="font-semibold text-gray-800">Categories ({assign.categories.length})</h3>
              <button className="btn btn-primary text-xs" disabled={saving === 'categories'} onClick={() => save('categories')}>
                {saving === 'categories' ? 'Saving…' : 'Save'}
              </button>
            </div>
            {allCats.length === 0 ? (
              <EmptyState title="No categories" />
            ) : (
              <ul className="max-h-96 divide-y divide-gray-100 overflow-y-auto">
                {allCats.map((c) => (
                  <li key={c.id} className="flex items-center gap-3 px-4 py-2 hover:bg-gray-50">
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                      checked={assign.categories.includes(c.id)}
                      onChange={() => setAssign((s) => ({ ...s, categories: toggleId(s.categories, c.id) }))}
                    />
                    <span className="text-sm text-gray-800">{c.name}</span>
                    <span className="ml-auto text-xs text-gray-400">{c.parameters_count ?? 0} params</span>
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div className="card overflow-hidden">
            <div className="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-3">
              <h3 className="font-semibold text-gray-800">Parameters ({assign.parameters.length})</h3>
              <button className="btn btn-primary text-xs" disabled={saving === 'parameters'} onClick={() => save('parameters')}>
                {saving === 'parameters' ? 'Saving…' : 'Save'}
              </button>
            </div>
            {allParams.length === 0 ? (
              <EmptyState title="No parameters" />
            ) : (
              <ul className="max-h-96 divide-y divide-gray-100 overflow-y-auto">
                {allParams.map((p) => (
                  <li key={p.id} className="flex items-center gap-3 px-4 py-2 hover:bg-gray-50">
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                      checked={assign.parameters.includes(p.id)}
                      onChange={() => setAssign((s) => ({ ...s, parameters: toggleId(s.parameters, p.id) }))}
                    />
                    <span className="w-10 font-mono text-xs text-blue-700">{p.code}</span>
                    <span className="text-sm text-gray-800">{p.label}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </div>
  );
}