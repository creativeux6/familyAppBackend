import { useEffect, useState } from 'react';
import { Link, Navigate } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';
import { LogsShimmer, Shimmer } from '../shimmer';

function statusBadge(status) {
  const map = {
    ok: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    degraded: 'bg-amber-50 text-amber-800 border-amber-200',
    down: 'bg-red-50 text-red-700 border-red-200',
  };
  return map[status] || 'bg-slate-50 text-slate-600 border-slate-200';
}

export function LogsPage() {
  const { isAdmin } = useAuth();
  const [logs, setLogs] = useState([]);
  const [meta, setMeta] = useState(null);
  const [health, setHealth] = useState(null);
  const [selected, setSelected] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [pathFilter, setPathFilter] = useState('');

  useEffect(() => {
    if (!isAdmin) {
      return undefined;
    }

    let cancelled = false;
    (async () => {
      setLoading(true);
      setError('');
      try {
        const query = new URLSearchParams({ per_page: '25' });
        if (pathFilter.trim()) query.set('path', pathFilter.trim());
        const [logsData, healthData] = await Promise.all([
          api(`/admin/system-logs?${query.toString()}`),
          api('/admin/websocket-health'),
        ]);
        if (cancelled) return;
        setLogs(logsData.data || []);
        setMeta(logsData.meta || null);
        setHealth(healthData);
      } catch (err) {
        if (!cancelled) setError(err.message || 'Could not load logs');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [pathFilter, isAdmin]);

  if (!isAdmin) {
    return <Navigate to="/web" replace />;
  }

  async function openDetail(uuid) {
    try {
      const detail = await api(`/admin/system-logs/${uuid}`);
      setSelected(detail);
    } catch (err) {
      setError(err.message || 'Could not load log detail');
    }
  }

  const sockets = health?.sockets ?? [];

  if (loading && !health) {
    return <LogsShimmer />;
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">System logs</h1>
          <p className="mt-1 text-sm text-slate-500">
            API/debug errors and per-socket WebSocket health.
          </p>
        </div>
        <Link to="/web" className="text-sm text-indigo-600 hover:underline">
          Back to dashboard
        </Link>
      </div>

      {error ? (
        <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {error}
        </div>
      ) : null}

      <div className="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="flex flex-wrap items-center gap-3">
          <div className="text-sm font-semibold text-slate-800">WebSockets overview</div>
          {health ? (
            <span
              className={`rounded-full border px-2.5 py-0.5 text-xs font-semibold uppercase ${statusBadge(health.status)}`}
            >
              {health.status}
            </span>
          ) : (
            <span className="text-sm text-slate-400">Checking…</span>
          )}
          {health?.summary ? (
            <span className="text-xs text-slate-500">
              {health.summary.ok} ok · {health.summary.degraded} degraded · {health.summary.down}{' '}
              down
            </span>
          ) : null}
        </div>

        {health ? (
          <div className="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-2 lg:grid-cols-4">
            <div>Checked: {new Date(health.checked_at).toLocaleString()}</div>
            <div>
              Server: {health.connection?.host}:{health.connection?.port}
            </div>
            <div>
              Client: {health.connection?.client_host}:{health.connection?.client_port}
            </div>
            <div>Driver: {health.connection?.broadcast_driver}</div>
          </div>
        ) : null}

        <div className="mt-4 overflow-hidden rounded-xl border border-slate-200">
          <div className="overflow-x-auto">
            <table className="min-w-full text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-3 py-2.5 sm:px-4">Socket / check</th>
                  <th className="px-3 py-2.5 sm:px-4">Type</th>
                  <th className="px-3 py-2.5 sm:px-4">Endpoint</th>
                  <th className="px-3 py-2.5 sm:px-4">Status</th>
                  <th className="hidden px-3 py-2.5 sm:table-cell sm:px-4">Details</th>
                </tr>
              </thead>
              <tbody>
                {sockets.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-4 py-6 text-slate-500">
                      No socket checks available yet.
                    </td>
                  </tr>
                ) : (
                  sockets.map((socket) => (
                    <tr key={socket.id} className="border-t border-slate-100 align-top">
                      <td className="px-3 py-3 sm:px-4">
                        <div className="font-medium text-slate-800">{socket.name}</div>
                        <div className="mt-0.5 text-xs text-slate-500">{socket.description}</div>
                        <div className="mt-1 text-xs text-slate-500 sm:hidden">{socket.message}</div>
                      </td>
                      <td className="px-3 py-3 capitalize text-slate-600 sm:px-4">{socket.type}</td>
                      <td className="max-w-[12rem] truncate px-3 py-3 font-mono text-xs text-slate-700 sm:max-w-xs sm:px-4">
                        {socket.endpoint}
                      </td>
                      <td className="px-3 py-3 sm:px-4">
                        <span
                          className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold uppercase ${statusBadge(socket.status)}`}
                        >
                          {socket.status}
                        </span>
                        {socket.latency_ms != null ? (
                          <div className="mt-1 text-[11px] text-slate-400">{socket.latency_ms} ms</div>
                        ) : null}
                      </td>
                      <td className="hidden px-3 py-3 text-slate-600 sm:table-cell sm:px-4">
                        {socket.message}
                        {socket.http_status != null ? (
                          <span className="ml-1 text-xs text-slate-400">
                            (HTTP {socket.http_status})
                          </span>
                        ) : null}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>

        {health?.recent_errors?.length ? (
          <div className="mt-4">
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
              Recent Reverb log lines
            </div>
            <ul className="mt-2 max-h-40 space-y-1 overflow-auto rounded-xl bg-slate-50 p-3 font-mono text-xs text-slate-700">
              {health.recent_errors.map((row, index) => (
                <li key={index}>
                  {row.timestamp ? `[${row.timestamp}] ` : ''}
                  {row.message}
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </div>

      <div className="mb-4">
        <input
          value={pathFilter}
          onChange={(e) => setPathFilter(e.target.value)}
          placeholder="Filter API errors by path…"
          className="w-full max-w-md rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
        />
      </div>

      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="overflow-x-auto">
          <table className="min-w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-4 py-3">When</th>
                <th className="px-4 py-3">User</th>
                <th className="px-4 py-3">Route</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">Message</th>
              </tr>
            </thead>
              <tbody>
                {loading ? (
                  Array.from({ length: 5 }).map((_, index) => (
                    <tr key={index} className="border-t border-slate-100">
                      <td className="px-4 py-3" colSpan={5}>
                        <Shimmer className="h-4 w-full" />
                      </td>
                    </tr>
                  ))
                ) : logs.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-4 py-8 text-slate-500">
                    No system errors recorded yet.
                  </td>
                </tr>
              ) : (
                logs.map((log) => (
                  <tr
                    key={log.uuid}
                    className="cursor-pointer border-t border-slate-100 hover:bg-indigo-50/40"
                    onClick={() => openDetail(log.uuid)}
                  >
                    <td className="whitespace-nowrap px-4 py-3 text-slate-600">
                      {log.occurred_at ? new Date(log.occurred_at).toLocaleString() : '—'}
                    </td>
                    <td className="px-4 py-3 text-slate-700">
                      {log.user?.display_name || 'Guest'}
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-slate-700">
                      {log.method} {log.path}
                    </td>
                    <td className="px-4 py-3">{log.status_code || '—'}</td>
                    <td className="max-w-sm truncate px-4 py-3 text-slate-600">{log.message}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        {meta ? (
          <div className="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
            Page {meta.current_page} of {meta.last_page} · {meta.total} total
          </div>
        ) : null}
      </div>

      {selected ? (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-4 sm:items-center">
          <div className="max-h-[85vh] w-full max-w-3xl overflow-auto rounded-2xl bg-white p-5 shadow-xl">
            <div className="mb-4 flex items-start justify-between gap-4">
              <div>
                <h2 className="text-lg font-semibold text-slate-900">Error detail</h2>
                <p className="text-sm text-slate-500">
                  {selected.method} {selected.path} · {selected.status_code}
                </p>
              </div>
              <button
                type="button"
                className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm"
                onClick={() => setSelected(null)}
              >
                Close
              </button>
            </div>
            <div className="space-y-3 text-sm">
              <div>
                <span className="text-slate-500">User: </span>
                {selected.user?.display_name || 'Guest'} ({selected.user?.phone || 'n/a'})
              </div>
              <div>
                <span className="text-slate-500">Exception: </span>
                {selected.exception_class}
              </div>
              <div>
                <span className="text-slate-500">Message</span>
                <pre className="mt-1 whitespace-pre-wrap rounded-xl bg-slate-50 p-3 text-xs text-slate-800">
                  {selected.message}
                </pre>
              </div>
              {selected.trace ? (
                <div>
                  <span className="text-slate-500">Trace</span>
                  <pre className="mt-1 max-h-80 overflow-auto whitespace-pre-wrap rounded-xl bg-slate-950 p-3 text-xs text-slate-100">
                    {selected.trace}
                  </pre>
                </div>
              ) : null}
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
