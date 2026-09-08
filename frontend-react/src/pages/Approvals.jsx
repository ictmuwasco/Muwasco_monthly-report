import { useEffect, useState } from 'react';
import { api, apiError, errorMap } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import { useToasts, Toaster } from '../components/Toasts';
import { Spinner, EmptyState, StatusBadge } from '../components/Ui';

const ROLES = ['technical_manager', 'commercial_manager'];

export default function Approvals() {
  const { user } = useAuth();
  const { toasts, push } = useToasts();
  const isAdmin = user?.role === 'admin';

  const [periods, setPeriods] = useState([]);
  const [periodId, setPeriodId] = useState('');
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);

  const [reqForm, setReqForm] = useState({ manager_role: ROLES[0], notify_email: '' });
  const [reqErrors, setReqErrors] = useState({});

  useEffect(() => {
    api.get('/reporting-periods?per_page=100').then(({ data }) => setPeriods(data.data ?? [])).catch(() => {});
  }, []);

  const load = (id) => {
    if (!id) return;
    setLoading(true);
    setData(null);
    api
      .get(`/reporting-periods/${id}/approvals`)
      .then(({ data }) => setData(data))
      .catch((e) => push(apiError(e), 'error'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    load(periodId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [periodId]);

  const requestReview = async (e) => {
    e.preventDefault();
    setReqErrors({});
    try {
      await api.post(`/reporting-periods/${periodId}/approvals`, {
        manager_role: reqForm.manager_role,
        notify_email: reqForm.notify_email || null,
      });
      push(`Review requested from ${reqForm.manager_role}.`);
      load(periodId);
    } catch (err) {
      setReqErrors(errorMap(err));
    }
  };

  const decide = async (approval, action) => {
    if (!window.confirm(`Confirm ${action}?`)) return;
    try {
      await api.post(`/reporting-periods/${periodId}/approvals/${approval.id}/decide`, { action });
      push(action === 'approve' ? 'Review approved.' : 'Changes requested.');
      load(periodId);
    } catch (e) {
      push(apiError(e), 'error');
    }
  };

  return (
    <div className="space-y-5">
      <Toaster toasts={toasts} />
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Approvals</h1>
          <p className="text-sm text-gray-500">Manage the review workflow for submitted periods.</p>
        </div>
      </div>

      <div className="card p-4">
        <label className="mb-1 block text-sm font-medium text-gray-700">Reporting period</label>
        <select className="input max-w-md" value={periodId} onChange={(e) => setPeriodId(e.target.value)}>
          <option value="">Select a period…</option>
          {periods.map((p) => (
            <option key={p.id} value={p.id}>{p.name} ({p.status})</option>
          ))}
        </select>
      </div>

      {!periodId && <EmptyState title="Select a reporting period" message="Choose a submitted period to view its approval workflow." />}
      {periodId && loading && <Spinner label="Loading approvals…" />}

      {data && (
        <>
          <div className="card overflow-hidden">
            <div className="flex items-center gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3">
              <h3 className="font-semibold text-gray-800">{data.period.name}</h3>
              <StatusBadge status={data.period.status} />
            </div>
            {data.approvals.length === 0 ? (
              <EmptyState title="No approvals yet" message="Request a manager review below." />
            ) : (
              <table className="w-full">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="th">Manager</th>
                    <th className="th">Status</th>
                    <th className="th">Notified</th>
                    <th className="th">Decision</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {data.approvals.map((a) => (
                    <tr key={a.id} className="hover:bg-gray-50">
                      <td className="td font-medium">{a.manager_role.replace(/_/g, ' ')}</td>
                      <td className="td"><StatusBadge status={a.status} /></td>
                      <td className="td">{a.notified_at ? new Date(a.notified_at).toLocaleString() : '—'}</td>
                      <td className="td">
                        {isAdmin && a.status === 'notified' && (
                          <div className="flex gap-2">
                            <button className="btn btn-success text-xs" onClick={() => decide(a, 'approve')}>Approve</button>
                            <button className="btn btn-danger text-xs" onClick={() => decide(a, 'reject')}>Request changes</button>
                          </div>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>

          {data.histories.length > 0 && (
            <div className="card">
              <div className="border-b border-gray-200 px-4 py-3">
                <h3 className="font-semibold text-gray-800">Decision trail</h3>
              </div>
              <table className="w-full">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="th">When</th>
                    <th className="th">Action</th>
                    <th className="th">From</th>
                    <th className="th">To</th>
                    <th className="th">Comment</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {data.histories.map((h) => (
                    <tr key={h.id} className="hover:bg-gray-50">
                      <td className="td">{h.created_at ? new Date(h.created_at).toLocaleString() : '—'}</td>
                      <td className="td">{h.action}</td>
                      <td className="td">{h.prev_status ?? '—'}</td>
                      <td className="td">{h.new_status ?? '—'}</td>
                      <td className="td">{h.comment ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {isAdmin && (data.period.status === 'submitted' || data.period.status === 'under_review') && (
            <div className="card p-5">
              <h3 className="mb-3 font-semibold text-gray-800">Request manager review</h3>
              <form onSubmit={requestReview} className="space-y-4">
                {reqErrors._server && <p className="text-sm text-red-600">{reqErrors._server[0]}</p>}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div>
                    <label className="mb-1 block text-sm font-medium text-gray-700">Manager role</label>
                    <select className="input" value={reqForm.manager_role} onChange={(e) => setReqForm({ ...reqForm, manager_role: e.target.value })}>
                      {ROLES.map((r) => <option key={r} value={r}>{r.replace(/_/g, ' ')}</option>)}
                    </select>
                  </div>
                  <div>
                    <label className="mb-1 block text-sm font-medium text-gray-700">Notify email (optional)</label>
                    <input className="input" type="email" value={reqForm.notify_email} onChange={(e) => setReqForm({ ...reqForm, notify_email: e.target.value })} />
                  </div>
                </div>
                <button className="btn btn-primary">Request review</button>
              </form>
            </div>
          )}
        </>
      )}
    </div>
  );
}