import { useEffect, useState } from 'react';
import { api } from '../api';
import { useAuth } from '../auth';
import { HomeShimmer } from '../shimmer';

export function UserHomePage() {
  const { user } = useAuth();
  const [today, setToday] = useState(null);
  const [quota, setQuota] = useState(null);
  const [connections, setConnections] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const [todayData, quotaData, connectionsData] = await Promise.all([
          api('/calendar/today').catch(() => null),
          api('/storage/quota').catch(() => null),
          api('/connections?status=connected').catch(() => null),
        ]);
        if (cancelled) return;
        setToday(todayData);
        setQuota(quotaData);
        setConnections(connectionsData?.connections ?? connectionsData ?? []);
      } catch (err) {
        if (!cancelled) setError(err.message || 'Could not load home');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  if (loading) {
    return <HomeShimmer />;
  }

  const celebrations = [
    ...(today?.personal ?? []),
    ...(today?.family ?? []),
  ];
  const connectedCount = Array.isArray(connections) ? connections.length : 0;

  function formatBytes(bytes) {
    if (bytes == null || Number.isNaN(Number(bytes))) return '—';
    const value = Number(bytes);
    if (value < 1024) return `${value} B`;
    if (value < 1024 ** 2) return `${(value / 1024).toFixed(1)} KB`;
    if (value < 1024 ** 3) return `${(value / 1024 ** 2).toFixed(1)} MB`;
    return `${(value / 1024 ** 3).toFixed(2)} GB`;
  }

  return (
    <div>
      <div className="mb-8">
        <h1 className="text-2xl font-semibold text-slate-900">
          Hello, {user?.display_name || 'Member'}
        </h1>
        <p className="mt-1 text-sm text-slate-500">
          Your family home. Full gallery, calendar, and chat pages arrive in Phase 2.
        </p>
      </div>

      {error ? (
        <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {error}
        </div>
      ) : null}

      {celebrations.length > 0 ? (
        <div className="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-4">
          <div className="text-sm font-semibold text-amber-900">Today</div>
          <ul className="mt-2 space-y-1 text-sm text-amber-900">
            {celebrations.slice(0, 5).map((item, index) => (
              <li key={item.uuid || item.id || index}>
                {item.message || item.title || item.label || 'Celebration'}
              </li>
            ))}
          </ul>
        </div>
      ) : (
        <div className="mb-6 rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-500">
          No celebrations for today.
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="text-sm font-medium text-slate-800">Family Gallery</div>
          <p className="mt-1 text-sm text-slate-500">Browse and share photos — coming in Phase 2.</p>
          <button
            type="button"
            disabled
            className="mt-4 rounded-xl bg-slate-100 px-3 py-2 text-sm text-slate-400"
          >
            Open gallery
          </button>
        </div>
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="text-sm font-medium text-slate-800">Calendar</div>
          <p className="mt-1 text-sm text-slate-500">Birthdays and reminders — coming in Phase 2.</p>
          <button
            type="button"
            disabled
            className="mt-4 rounded-xl bg-slate-100 px-3 py-2 text-sm text-slate-400"
          >
            Open calendar
          </button>
        </div>
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="text-sm text-slate-500">Connected members</div>
          <div className="mt-2 text-2xl font-semibold text-slate-900">{connectedCount}</div>
        </div>
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="text-sm text-slate-500">Storage used</div>
          <div className="mt-2 text-2xl font-semibold text-slate-900">
            {formatBytes(quota?.used_bytes)}
          </div>
          {quota?.quota_bytes != null ? (
            <div className="mt-1 text-xs text-slate-500">of {formatBytes(quota.quota_bytes)}</div>
          ) : null}
        </div>
      </div>
    </div>
  );
}
