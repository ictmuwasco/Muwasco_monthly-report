import { useEffect, useState } from 'react';
import { api, apiError, errorMap } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import { useToasts, Toaster } from '../components/Toasts';
import { Spinner, EmptyState, StatusBadge, FieldErrors, Pagination } from '../components/Ui';

const STATUSES = [
  'draft', 'open', 'submitted', 'under_review',
  'changes_requested', 'approved', 'rejected', 'closed',
];

export default function ReportingPeriods() {
  const { user } = useAuth();
  const { toasts, push } = useToasts();
  const isAdmin = user?.role === 'admin';

  const [rows, setRows] = useState(null);
  const [meta, setMeta] = useState(null);
  const [status, setStatus] = useState('');
  const [year, setYear] = useState('');
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');

  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({
    month_year: '', name: '', start_date: '', end_date: '', submission_deadline: '',
  });
  const [formErrors, setFormErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const [transition, setTransition] = useState(null); // { period, next }

  const load = () => {
    const params = { page };
    if (status) params.status = status;
    if (year) params.year = year;
    if (search) params.search = search;
    setRows(null);
    api
      .get('/reporting-periods', { params })
      .then(({ data }) => {
        setRows(data.data ?? []);
        setMeta(data.meta ?? null);
      })
      .catch((e) => push(apiError(e), 'error'));
  };

  useEffect(() => {
    const t = setTimeout(load, search ? 250 : 0);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, status, year, search]);

  const openCreate = () => {
    setForm({ month_year: '', name: '', start_date: '', end_date: '', submission_deadline: '' });
    setFormErrors({});
    setShowForm(true);
  };

  const createPeriod = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFormErrors({});
    try {
      await api.post('/reporting-periods', {
        month_year: form.month_year,
        name: form.name || undefined,
        start_date: form.start_date || undefined,
        end_date: form.end_date || undefined,
        submission_deadline: form.submission_deadline || null,
      });
      push('Reporting period created.');
      setShowForm(false);
      load();
    } catch (err) {
      setFormErrors(errorMap(err));
    } finally {
      setSaving(false);
    }
  };

  const applyTransition = async (e) => {
    e.preventDefault();
    if (!transition) return;
    try {
      await api.post(`/reporting-periods/${transition.period.id}/status`, {
        status: transition.next,
      });
      push(`Period moved to "${transition.next}".`);
      setTransition(null);
      load();
    } catch (err) {
      push(apiError(err), 'error');
      setTransition(null);
    }
  };

  return (
    <div className="space-y-5">
      <Toaster toasts={toasts} />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Reporting Periods</h1>
          <p className="text-sm text-gray-500">Create and manage monthly reporting windows.</p>
        </div>
        {isAdmin && (
          <button className="btn btn-primary" onClick={openCreate}>+ New period</button>
        )}
      </div>

      {/* Filters */}
      <div className="card flex flex-wrap items-end gap-3 p-4">
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-600">Search</label>
          <input className="input" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name or month" />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-600">Status</label>
          <select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All</option>
            {STATUSES.map((s) => (
              <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-600">Year</label>
          <input className="input" placeholder="2025" value={year} onChange={(e) => setYear(e.target.value)} />
        </div>
        <button
          className="btn btn-secondary"
          onClick={() => { setSearch(''); setStatus(''); setYear(''); setPage(1); }}
        >
          Reset
        </button>
      </div>

      {/* Table */}
      <div className="card overflow-hidden">
        {rows === null ? (
          <Spinner />
        ) : rows.length === 0 ? (
          <EmptyState title="No reporting periods" message="Try changing filters or create a new period." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50">
                <tr>
                  <th className="th">Name</th>
                  <th className="th">Month</th>
                  <th className="th">Start</th>
                  <th className="th">End</th>
                  <th className="th">Deadline</th>
                  <th className="th">Data rows</th>
                  <th className="th">Status</th>
                  <th className="th">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {rows.map((p) => (
                  <tr key={p.id} className="hover:bg-gray-50">
                    <td className="td font-medium text-gray-900">{p.name}</td>
                    <td className="td">{p.month_year}</td>
                    <td className="td">{p.start_date}</td>
                    <td className="td">{p.end_date}</td>
                    <td className="td">{p.submission_deadline ?? '—'}</td>
                    <td className="td">{p.monthly_data_count ?? 0}</td>
                    <td className="td"><StatusBadge status={p.status} /></td>
                    <td className="td">
                      <div className="flex items-center gap-3">
                        <a className="text-xs font-medium text-indigo-600 hover:underline" href={`/data-entry?period=${p.id}`}>
                          Enter data
                        </a>
                        {isAdmin && (
                          <button
                            className="text-xs font-medium text-blue-600 hover:underline"
                            onClick={() => setTransition({ period: p, next: p.status })}
                          >
                            Status
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pagination meta={meta} onPage={setPage} />
      </div>
{/* Create modal */}
      {showForm && (
        <Modal title="New reporting period" onClose={() => setShowForm(false)}>
          <FieldErrors errors={formErrors} />
          <form onSubmit={createPeriod} className="space-y-4">
            <Field label="Month (first day of month, YYYY-MM-DD)">
              <input className="input" type="date" required value={form.month_year} onChange={(e) => setForm({ ...form, month_year: e.target.value })} />
            </Field>
            <Field label="Name (optional)">
              <input className="input" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Start date">
                <input className="input" type="date" value={form.start_date} onChange={(e) => setForm({ ...form, start_date: e.target.value })} />
              </Field>
              <Field label="End date">
                <input className="input" type="date" value={form.end_date} onChange={(e) => setForm({ ...form, end_date: e.target.value })} />
              </Field>
            </div>
            <Field label="Submission deadline">
              <input className="input" type="date" value={form.submission_deadline} onChange={(e) => setForm({ ...form, submission_deadline: e.target.value })} />
            </Field>
            <div className="flex justify-end gap-2">
              <button type="button" className="btn btn-secondary" onClick={() => setShowForm(false)}>Cancel</button>
              <button className="btn btn-primary" disabled={saving}>{saving ? 'Creating…' : 'Create'}</button>
            </div>
          </form>
        </Modal>
      )}

      {/* Transition modal */}
      {transition && (
        <Modal title={`Change status: ${transition.period.name}`} onClose={() => setTransition(null)}>
          <form onSubmit={applyTransition} className="space-y-4">
            <Field label="New status">
              <select
                className="input"
                value={transition.next}
                onChange={(e) => setTransition({ ...transition, next: e.target.value })}
              >
                {STATUSES.map((s) => (
                  <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
                ))}
              </select>
            </Field>
            <div className="flex justify-end gap-2">
              <button type="button" className="btn btn-secondary" onClick={() => setTransition(null)}>Cancel</button>
              <button className="btn btn-primary">Apply</button>
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