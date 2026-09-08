import { useEffect, useState } from 'react';
import { api, apiError, errorMap } from '../lib/api';
import { useToasts, Toaster } from '../components/Toasts';
import { Spinner, EmptyState, Pagination, FieldErrors } from '../components/Ui';

const DATA_TYPES = ['number', 'text', 'currency', 'percentage'];
const empty = { code: '', category_id: '', label: '', description: '', data_type: 'text', unit: '', required: false, display_order: 0 };

export default function Parameters() {
  const { toasts, push } = useToasts();
  const [rows, setRows] = useState(null);
  const [meta, setMeta] = useState(null);
  const [cats, setCats] = useState([]);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [catFilter, setCatFilter] = useState('');

  const [modal, setModal] = useState(null); // { editing, form }
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const load = () => {
    const params = { page };
    if (search) params.search = search;
    if (catFilter) params.category_id = catFilter;
    setRows(null);
    api.get('/parameters', { params }).then(({ data }) => {
      setRows(data.data ?? []);
      setMeta(data.meta ?? null);
    }).catch((e) => push(apiError(e), 'error'));
  };

  useEffect(() => {
    api.get('/parameter-categories?per_page=100').then(({ data }) => setCats(data.data ?? [])).catch(() => {});
  }, []);

  useEffect(() => {
    const t = setTimeout(load, search ? 250 : 0);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, search, catFilter]);

  const openCreate = () => setModal({ editing: null, form: { ...empty } });
  const openEdit = (p) => setModal({ editing: p, form: {
    code: p.code, category_id: p.category_id ?? '', label: p.label,
    description: p.description ?? '', data_type: p.data_type, unit: p.unit ?? '',
    required: !!p.required, display_order: p.display_order ?? 0,
  } });

  const submit = async (e) => {
    e.preventDefault();
    if (!modal) return;
    setSaving(true);
    setErrors({});
    const payload = {
      code: modal.form.code, category_id: modal.form.category_id || null,
      label: modal.form.label, description: modal.form.description || null,
      data_type: modal.form.data_type, unit: modal.form.unit || null,
      required: modal.form.required, display_order: Number(modal.form.display_order) || 0,
    };
    try {
      if (modal.editing) {
        await api.patch(`/parameters/${modal.editing.id}`, payload);
        push('Parameter updated.');
      } else {
        await api.post('/parameters', payload);
        push('Parameter created.');
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
          <h1 className="text-2xl font-bold text-gray-900">Parameters</h1>
          <p className="text-sm text-gray-500">Reference data for monthly monitoring indicators.</p>
        </div>
        <button className="btn btn-primary" onClick={openCreate}>+ New parameter</button>
      </div>

      <div className="card flex flex-wrap items-end gap-3 p-4">
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-600">Search</label>
          <input className="input" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Code or label" />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-600">Category</label>
          <select className="input" value={catFilter} onChange={(e) => setCatFilter(e.target.value)}>
            <option value="">All</option>
            {cats.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>
      </div>

      <div className="card overflow-hidden">
        {rows === null ? <Spinner /> : rows.length === 0 ? (
          <EmptyState title="No parameters found" />
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="th">Code</th>
                <th className="th">Label</th>
                <th className="th">Category</th>
                <th className="th">Type</th>
                <th className="th">Unit</th>
                <th className="th">Req</th>
                <th className="th"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {rows.map((p) => (
                <tr key={p.id} className="hover:bg-gray-50">
                  <td className="td font-mono font-semibold text-blue-700">{p.code}</td>
                  <td className="td">{p.label}</td>
                  <td className="td">{p.category?.name ?? '—'}</td>
                  <td className="td"><span className="badge bg-gray-100 text-gray-700">{p.data_type}</span></td>
                  <td className="td">{p.unit ?? '—'}</td>
                  <td className="td">{p.required ? '✓' : ''}</td>
                  <td className="td">
                    <button className="text-xs font-medium text-blue-600 hover:underline" onClick={() => openEdit(p)}>Edit</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Pagination meta={meta} onPage={setPage} />
      </div>
{modal && (
        <Modal title={modal.editing ? `Edit parameter: ${modal.editing.code}` : 'New parameter'} onClose={() => setModal(null)}>
          <FieldErrors errors={errors} />
          <form onSubmit={submit} className="space-y-4">
            <div className="grid grid-cols-2 gap-3">
              <Field label="Code">
                <input className="input" required value={modal.form.code} onChange={(e) => setModal({ ...modal, form: { ...modal.form, code: e.target.value } })} />
              </Field>
              <Field label="Data type">
                <select className="input" value={modal.form.data_type} onChange={(e) => setModal({ ...modal, form: { ...modal.form, data_type: e.target.value } })}>
                  {DATA_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                </select>
              </Field>
            </div>
            <Field label="Label">
              <input className="input" required value={modal.form.label} onChange={(e) => setModal({ ...modal, form: { ...modal.form, label: e.target.value } })} />
            </Field>
            <Field label="Description (optional)">
              <textarea className="input" rows={2} value={modal.form.description} onChange={(e) => setModal({ ...modal, form: { ...modal.form, description: e.target.value } })} />
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Category">
                <select className="input" value={modal.form.category_id} onChange={(e) => setModal({ ...modal, form: { ...modal.form, category_id: e.target.value } })}>
                  <option value="">None</option>
                  {cats.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </Field>
              <Field label="Unit">
                <input className="input" value={modal.form.unit} onChange={(e) => setModal({ ...modal, form: { ...modal.form, unit: e.target.value } })} />
              </Field>
            </div>
            <div className="flex items-center justify-between">
              <label className="flex items-center gap-2 text-sm font-medium text-gray-700">
                <input type="checkbox" className="h-4 w-4 rounded border-gray-300" checked={modal.form.required} onChange={(e) => setModal({ ...modal, form: { ...modal.form, required: e.target.checked } })} />
                Required
              </label>
              <Field label="Display order">
                <input className="input w-24" type="number" value={modal.form.display_order} onChange={(e) => setModal({ ...modal, form: { ...modal.form, display_order: e.target.value } })} />
              </Field>
            </div>
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
      <div className="card w-full max-w-xl p-6" onClick={(e) => e.stopPropagation()}>
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