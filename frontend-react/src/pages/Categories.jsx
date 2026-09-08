import { useEffect, useState } from 'react';
import { api, apiError, errorMap } from '../lib/api';
import { useToasts, Toaster } from '../components/Toasts';
import { Spinner, EmptyState, Pagination, FieldErrors } from '../components/Ui';

const empty = { name: '', description: '', display_order: 0 };

export default function Categories() {
  const { toasts, push } = useToasts();
  const [rows, setRows] = useState(null);
  const [meta, setMeta] = useState(null);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');

  const [modal, setModal] = useState(null); // { editing, form }
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const load = () => {
    setRows(null);
    api.get('/parameter-categories', { params: { page, search } }).then(({ data }) => {
      setRows(data.data ?? []);
      setMeta(data.meta ?? null);
    }).catch((e) => push(apiError(e), 'error'));
  };

  useEffect(() => {
    const t = setTimeout(load, search ? 250 : 0);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, search]);

  const openCreate = () => setModal({ editing: null, form: { ...empty } });
  const openEdit = (c) => setModal({ editing: c, form: { name: c.name, description: c.description ?? '', display_order: c.display_order ?? 0 } });

  const submit = async (e) => {
    e.preventDefault();
    if (!modal) return;
    setSaving(true);
    setErrors({});
    const payload = { name: modal.form.name, description: modal.form.description || null, display_order: Number(modal.form.display_order) || 0 };
    try {
      if (modal.editing) {
        await api.patch(`/parameter-categories/${modal.editing.id}`, payload);
        push('Category updated.');
      } else {
        await api.post('/parameter-categories', payload);
        push('Category created.');
      }
      setModal(null);
      load();
    } catch (err) {
      setErrors(errorMap(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-5">
      <Toaster toasts={toasts} />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Categories</h1>
          <p className="text-sm text-gray-500">Sections that group reporting parameters.</p>
        </div>
        <button className="btn btn-primary" onClick={openCreate}>+ New category</button>
      </div>

      <div className="card p-4">
        <label className="mb-1 block text-xs font-medium text-gray-600">Search</label>
        <input className="input" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Category name" />
      </div>

      <div className="card overflow-hidden">
        {rows === null ? <Spinner /> : rows.length === 0 ? (
          <EmptyState title="No categories" />
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="th">Name</th>
                <th className="th">Description</th>
                <th className="th">Parameters</th>
                <th className="th">Order</th>
                <th className="th"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {rows.map((c) => (
                <tr key={c.id} className="hover:bg-gray-50">
                  <td className="td font-medium text-gray-900">{c.name}</td>
                  <td className="td">{c.description ?? '—'}</td>
                  <td className="td">{c.parameters_count ?? 0}</td>
                  <td className="td">{c.display_order}</td>
                  <td className="td">
                    <button className="text-xs font-medium text-blue-600 hover:underline" onClick={() => openEdit(c)}>Edit</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Pagination meta={meta} onPage={setPage} />
      </div>

      {modal && (
        <Modal title={modal.editing ? `Edit category: ${modal.editing.name}` : 'New category'} onClose={() => setModal(null)}>
          <FieldErrors errors={errors} />
          <form onSubmit={submit} className="space-y-4">
            <Field label="Name">
              <input className="input" required value={modal.form.name} onChange={(e) => setModal({ ...modal, form: { ...modal.form, name: e.target.value } })} />
            </Field>
            <Field label="Description (optional)">
              <textarea className="input" rows={2} value={modal.form.description} onChange={(e) => setModal({ ...modal, form: { ...modal.form, description: e.target.value } })} />
            </Field>
            <Field label="Display order">
              <input className="input" type="number" value={modal.form.display_order} onChange={(e) => setModal({ ...modal, form: { ...modal.form, display_order: e.target.value } })} />
            </Field>
            <div className="flex justify-end gap-2">
              <button type="button" className="btn btn-secondary" onClick={() => setModal(null)}>Cancel</button>
              <button className="btn btn-primary" disabled={saving}>{saving ? 'Saving…' : 'Save'}</button>
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