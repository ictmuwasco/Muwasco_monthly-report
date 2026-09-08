import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, apiError } from '../lib/api';
import { useToasts, Toaster } from '../components/Toasts';
import { Spinner, EmptyState, StatusBadge } from '../components/Ui';

export default function DataEntry() {
  const [params, setParams] = useSearchParams();
  const periodId = params.get('period');

  const { toasts, push } = useToasts();
  const [entry, setEntry] = useState(null);
  const [periods, setPeriods] = useState([]);
  const [values, setValues] = useState({});
  const [busy, setBusy] = useState(false);

  const load = (id) => {
    if (!id) return;
    setEntry(null);
    api
      .get(`/reporting-periods/${id}/data-entry`)
      .then(({ data }) => {
        setEntry(data);
        const map = {};
        data.categories.forEach((c) => {
          c.parameters.forEach((p) => { map[p.id] = p.saved_value ?? ''; });
        });
        setValues(map);
      })
      .catch((e) => push(apiError(e), 'error'));
  };

  useEffect(() => {
    api
      .get('/reporting-periods?per_page=100')
      .then(({ data }) => setPeriods(data.data ?? []))
      .catch(() => {});
  }, []);

  useEffect(() => {
    load(periodId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [periodId]);

  const selectPeriod = (id) => {
    if (id) setParams({ period: id });
  };

  const collectRows = () =>
    Object.entries(values).map(([pid, val]) => ({
      parameter_id: Number(pid),
      value: val === '' ? null : val,
    }));

  const save = async () => {
    setBusy(true);
    try {
      const { data } = await api.put(`/reporting-periods/${periodId}/monthly-data`, {
        rows: collectRows(),
      });
      push(data.message || 'Draft saved.');
    } catch (e) {
      push(apiError(e), 'error');
    } finally {
      setBusy(false);
    }
  };

  const submit = async () => {
    if (!window.confirm('Submit this period for review? This validates required values.')) return;
    setBusy(true);
    try {
      const { data } = await api.post(`/reporting-periods/${periodId}/submit`);
      push(data.message || 'Submitted.');
      load(periodId);
    } catch (e) {
      push(apiError(e), 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-5">
      <Toaster toasts={toasts} />
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Data Entry</h1>
        <p className="text-sm text-gray-500">Enter monthly monitoring values for a reporting period.</p>
      </div>

      {/* Period selector */}
      <div className="card p-4">
        <label className="mb-1 block text-sm font-medium text-gray-700">Reporting period</label>
        <select className="input max-w-md" value={periodId ?? ''} onChange={(e) => selectPeriod(e.target.value)}>
          <option value="">Select a period…</option>
          {periods.map((p) => (
            <option key={p.id} value={p.id}>{p.name} ({p.status})</option>
          ))}
        </select>
      </div>

      {!periodId && (
        <EmptyState title="Select a reporting period" message="Choose a period above to begin entering data." />
      )}

      {periodId && entry === null && <Spinner label="Loading data entry…" />}

      {entry && (
        <>
          <div className="card flex flex-wrap items-center gap-3 p-4">
            <div>
              <p className="text-lg font-semibold text-gray-900">{entry.period.name}</p>
              <p className="text-sm text-gray-500">{entry.period.month_year}</p>
            </div>
            <StatusBadge status={entry.period.status} />
            <div className="ml-auto flex gap-2">
              <button className="btn btn-secondary" disabled={!entry.can_save || busy} onClick={save}>
                {busy ? 'Working…' : 'Save draft'}
              </button>
              <button className="btn btn-success" disabled={!entry.can_submit || busy} onClick={submit}>
                Submit
              </button>
            </div>
          </div>

          {!entry.can_save && entry.is_locked && (
            <p className="text-sm text-red-600">This period is locked and cannot be edited.</p>
          )}
          {!entry.can_save && entry.is_past_deadline && !entry.is_locked && (
            <p className="text-sm text-red-600">The submission deadline has passed.</p>
          )}

          <div className="space-y-5">
            {entry.categories.map((cat) => (
              <div key={cat.id ?? 'uncat'} className="card overflow-hidden">
                <div className="border-b border-gray-200 bg-gray-50 px-4 py-3">
                  <h3 className="font-semibold text-gray-800">{cat.name}</h3>
                </div>
                <div className="overflow-x-auto">
                  {cat.parameters.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-gray-500">No parameters in this category.</p>
                  ) : (
                    <table className="w-full">
                      <thead className="bg-gray-50">
                        <tr>
                          <th className="th w-16">Code</th>
                          <th className="th">Parameter</th>
                          <th className="th">Value</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-100">
                        {cat.parameters.map((p) => (
                          <tr key={p.id} className="hover:bg-gray-50">
                            <td className="td font-semibold text-blue-700">{p.code}</td>
                            <td className="td">
                              {p.label}
                              {p.required && <span className="ml-1 text-red-500">*</span>}
                              {p.unit && <span className="ml-1 text-xs text-gray-400">({p.unit})</span>}
                            </td>
                            <td className="td">
                              <input
                                className="input max-w-xs"
                                value={values[p.id] ?? ''}
                                onChange={(e) => setValues((s) => ({ ...s, [p.id]: e.target.value }))}
                                inputMode={p.data_type === 'text' ? 'text' : 'decimal'}
                                placeholder={p.data_type === 'percentage' ? '0-100' : ''}
                              />
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  );
}