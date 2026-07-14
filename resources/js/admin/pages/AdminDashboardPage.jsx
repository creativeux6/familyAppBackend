import { useEffect, useState } from 'react';
import { api } from '../api';
import { useAuth } from '../auth';
import { DashboardShimmer } from '../shimmer';

function StatCard({ label, value }) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="text-sm text-slate-500">{label}</div>
      <div className="mt-2 text-3xl font-semibold tracking-tight text-slate-900">{value ?? '—'}</div>
    </div>
  );
}

export function AdminDashboardPage() {
  const { user } = useAuth();
  const [stats, setStats] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const data = await api('/admin/dashboard');
        if (!cancelled) setStats(data);
      } catch (err) {
        if (!cancelled) setError(err.message || 'Could not load dashboard');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  if (loading) {
    return <DashboardShimmer />;
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-slate-900">Dashboard</h1>
        <p className="mt-1 text-sm text-slate-500">
          Welcome back, {user?.display_name}. Platform overview for admins.
        </p>
      </div>

      {error ? (
        <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {error}
        </div>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <StatCard label="Users total" value={stats?.users_total} />
        <StatCard label="New users (7d)" value={stats?.users_new_7d} />
        <StatCard label="Families" value={stats?.families_total} />
        <StatCard label="Groups" value={stats?.groups_total} />
        <StatCard label="Active media files" value={stats?.media_files_active} />
        <StatCard label="Open abuse reports" value={stats?.abuse_reports_open} />
      </div>
    </div>
  );
}
