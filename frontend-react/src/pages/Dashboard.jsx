import { useEffect, useState } from 'react';
import { useAuth } from '../context/AuthContext';
import { api } from '../lib/api';
import { Spinner, EmptyState, StatusBadge } from '../components/Ui';

export default function Dashboard() {
  const { user } = useAuth();
  const [periods, setPeriods] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    const getUrl = user?.role === 'admin' ? '/reporting-periods?per_page=100' : '/reporting-periods?per_page=100';
    api
      .get(getUrl)
      .then(({ data }) => {
        if (active) setPeriods(data.data ?? []);
      })
      .catch(() => {})
      .finally(() => active && setLoading(false));
    return () => {
      active = false;
    };
  }, [user]);

  if (loading) return <Spinner label="Loading dashboard…" />;

  const all = periods ?? [];
  const countBy = (s) => all.filter((p) => p.status === s).length;
  const recent = [...all].sort((a, b) => (a.start_date < b.start_date ? 1 : -1)).slice(0, 5);

  const stats = [
    { label: 'Total periods', value: all.length, color: 'bg-ocean-100 text-ocean-800' },
    { label: 'Open', value: countBy('open'), color: 'bg-ocean-100 text-ocean-800' },
    { label: 'Submitted', value: countBy('submitted'), color: 'bg-indigo-100 text-indigo-700' },
    { label: 'Approved', value: countBy('approved'), color: 'bg-green-100 text-green-700' },
    { label: 'Under review', value: countBy('under_review'), color: 'bg-amber-100 text-amber-700' },
    { label: 'Closed', value: countBy('closed'), color: 'bg-slate-100 text-slate-600' },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">
          Welcome, {user?.full_name || user?.username}
        </h1>
        <p className="text-sm text-gray-500">
          {user?.role === 'admin'
            ? 'Admin console — manage periods, users, parameters and approvals.'
            : 'Enter and submit monthly monitoring data.'}
        </p>
      </div>

      <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
        {stats.map((s) => (
          <div key={s.label} className={`card p-4 ${s.color}`}>
            <p className="text-3xl font-bold">{s.value}</p>
            <p className="mt-1 text-sm font-medium">{s.label}</p>
          </div>
        ))}
      </div>

      <div className="card">
        <div className="border-b border-gray-200 px-4 py-3">
          <h2 className="font-semibold text-gray-800">Recent reporting periods</h2>
        </div>
        {recent.length === 0 ? (
          <EmptyState title="No reporting periods found" />
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="th">Name</th>
                <th className="th">Period</th>
                <th className="th">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {recent.map((p) => (
                <tr key={p.id} className="hover:bg-gray-50">
                  <td className="td font-medium text-gray-900">{p.name}</td>
                  <td className="td">{p.month_year}</td>
                  <td className="td">
                    <StatusBadge status={p.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}