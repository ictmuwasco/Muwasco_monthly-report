import { useEffect, useState, Fragment } from 'react';
import { api, apiError } from '../lib/api';
import { useToasts, Toaster } from '../components/Toasts';
import { Spinner, EmptyState } from '../components/Ui';

export default function Reports() {
  const { toasts, push } = useToasts();
  const [periods, setPeriods] = useState([]);
  const [selected, setSelected] = useState([]);
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api
      .get('/reporting-periods?per_page=100')
      .then(({ data }) => setPeriods(data.data ?? []))
      .catch(() => {});
  }, []);

  const toggle = (id) => {
    setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
  };

  const preview = async () => {
    if (selected.length < 3) {
      push('Select at least 3 months to generate a report.', 'info');
      return;
    }
    setLoading(true);
    setReport(null);
    try {
      const { data } = await api.get('/reports/preview', { params: { months: selected } });
      setReport(data);
    } catch (e) {
      push(apiError(e), 'error');
    } finally {
      setLoading(false);
    }
  };

  const downloadPdf = async () => {
    if (selected.length < 3) {
      push('Select at least 3 months to download.', 'info');
      return;
    }
    setBusy(true);
    try {
      const res = await api.get('/reports/pdf', { params: { months: selected }, responseType: 'blob' });
      const url = URL.createObjectURL(res.data);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'muwasco_monitoring_report.pdf';
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch (e) {
      push(apiError(e), 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-5">
      <Toaster toasts={toasts} />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Reports</h1>
          <p className="text-sm text-gray-500">Select 3+ months to preview or export the monitoring report.</p>
        </div>
      </div>

      <div className="card p-4">
        <label className="mb-2 block text-sm font-medium text-gray-700">
          Months ({selected.length} selected)
        </label>
        {periods.length === 0 ? (
          <EmptyState title="No reporting periods available" />
        ) : (
          <div className="flex flex-wrap gap-2">
            {periods.map((p) => (
              <button
                key={p.id}
                type="button"
                onClick={() => toggle(p.id)}
                className={`btn text-xs ${
                  selected.includes(p.id) ? 'btn-primary' : 'btn-secondary'
                }`}
              >
                {p.name}
              </button>
            ))}
          </div>
        )}
        <div className="mt-4 flex gap-2">
          <button className="btn btn-secondary" disabled={selected.length < 3 || loading} onClick={preview}>
            Preview
          </button>
          <button className="btn btn-primary" disabled={selected.length < 3 || busy} onClick={downloadPdf}>
            {busy ? 'Downloading…' : 'Download PDF'}
          </button>
        </div>
      </div>

      {loading && <Spinner label="Building report…" />}

      {report && (
        <div className="card overflow-hidden">
          <div className="border-b border-gray-200 bg-gray-50 px-4 py-3 flex items-center justify-between">
            <h3 className="font-semibold text-gray-800">Report preview</h3>
            <span className="text-xs text-gray-500">Generated {report.generated_at}</span>
          </div>
          {report.categories.length === 0 ? (
            <EmptyState title="No data in the selected periods" />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="th">Code</th>
                    <th className="th">Parameter</th>
                    {report.periods.map((p) => (
                      <th key={p.id} className="th">{new Date(p.month_year).toLocaleDateString(undefined, { month: 'short', year: 'numeric' })}</th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {report.categories.map((cat) => (
                    <FragmentRow key={cat.name} category={cat} periods={report.periods} />
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function FragmentRow({ category, periods }) {
  return (
    <Fragment>
      <tr>
        <td colSpan={periods.length + 2} className="bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700">
          {category.name}
        </td>
      </tr>
      {category.parameters.map((p) => (
        <tr key={p.code} className="hover:bg-gray-50">
          <td className="td font-semibold text-blue-700">{p.code}</td>
          <td className="td">{p.label}</td>
          {p.values.map((v, i) => (
            <td key={i} className="td">{v ?? '—'}</td>
          ))}
        </tr>
      ))}
    </Fragment>
  );
}