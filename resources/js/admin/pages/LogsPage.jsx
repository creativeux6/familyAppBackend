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

function httpStatusClass(code) {
  if (!code) return 'bg-slate-50 text-slate-600 border-slate-200';
  if (code >= 500) return 'bg-red-50 text-red-700 border-red-200';
  if (code >= 400) return 'bg-amber-50 text-amber-800 border-amber-200';
  if (code >= 200 && code < 300) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
  return 'bg-slate-50 text-slate-600 border-slate-200';
}

const emptyFilters = {
  q: '',
  status_code: '',
  from: '',
  to: '',
};

export function LogsPage() {
  const { isAdmin } = useAuth();
  const [logs, setLogs] = useState([]);
  const [meta, setMeta] = useState(null);
  const [health, setHealth] = useState(null);
  const [selected, setSelected] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [draft, setDraft] = useState(emptyFilters);
  const [filters, setFilters] = useState(emptyFilters);
  const [page, setPage] = useState(1);
  const perPage = 20;

  useEffect(() => {
    if (!isAdmin) {
      return undefined;
    }

    let cancelled = false;
    (async () => {
      setLoading(true);
      setError('');
      try {
        const query = new URLSearchParams({
          per_page: String(perPage),
          page: String(page),
        });
        if (filters.q.trim()) query.set('q', filters.q.trim());
        if (filters.status_code.trim()) query.set('status_code', filters.status_code.trim());
        if (filters.from) query.set('from', filters.from);
        if (filters.to) query.set('to', filters.to);

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
  }, [filters, page, isAdmin]);

  if (!isAdmin) {
    return <Navigate to="/web" replace />;
  }

  async function openDetail(uuid) {
    setDetailLoading(true);
    setError('');
    try {
      const detail = await api(`/admin/system-logs/${uuid}`);
      setSelected(detail);
    } catch (err) {
      setError(err.message || 'Could not load log detail');
      setSelected(null);
    } finally {
      setDetailLoading(false);
    }
  }

  function applyFilters(event) {
    event.preventDefault();
    setPage(1);
    setFilters({ ...draft });
  }

  function clearFilters() {
    setDraft(emptyFilters);
    setFilters(emptyFilters);
    setPage(1);
  }

  const sockets = health?.sockets ?? [];

  if (loading && !health && logs.length === 0) {
    return <LogsShimmer />;
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">System logs</h1>
          <p className="mt-1 text-sm text-slate-500">
            All API responses (success and errors), client-reported failures, and WebSocket health.
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
      </div>

      <form
        onSubmit={applyFilters}
        className="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
      >
        <div className="mb-3 text-sm font-semibold text-slate-800">Search API logs</div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label className="block text-xs text-slate-500 sm:col-span-2 lg:col-span-2">
            Search (message, exception, path, status code)
            <input
              value={draft.q}
              onChange={(e) => setDraft((prev) => ({ ...prev, q: e.target.value }))}
              placeholder="e.g. 413, ValidationException, upload…"
              className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            />
          </label>
          <label className="block text-xs text-slate-500">
            Status code
            <input
              value={draft.status_code}
              onChange={(e) => setDraft((prev) => ({ ...prev, status_code: e.target.value }))}
              placeholder="422"
              inputMode="numeric"
              className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            />
          </label>
          <label className="block text-xs text-slate-500">
            From
            <input
              type="date"
              value={draft.from}
              onChange={(e) => setDraft((prev) => ({ ...prev, from: e.target.value }))}
              className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            />
          </label>
          <label className="block text-xs text-slate-500">
            To
            <input
              type="date"
              value={draft.to}
              onChange={(e) => setDraft((prev) => ({ ...prev, to: e.target.value }))}
              className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            />
          </label>
        </div>
        <div className="mt-3 flex flex-wrap gap-2">
          <button
            type="submit"
            className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
          >
            Search
          </button>
          <button
            type="button"
            onClick={clearFilters}
            className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
          >
            Clear
          </button>
        </div>
      </form>

      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="overflow-x-auto">
          <table className="min-w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-4 py-3">When</th>
                <th className="px-4 py-3">User</th>
                <th className="px-4 py-3">Route</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">Exception</th>
                <th className="px-4 py-3">Message</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                Array.from({ length: 5 }).map((_, index) => (
                  <tr key={index} className="border-t border-slate-100">
                    <td className="px-4 py-3" colSpan={6}>
                      <Shimmer className="h-4 w-full" />
                    </td>
                  </tr>
                ))
              ) : logs.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-slate-500">
                    No matching logs.
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
                    <td className="px-4 py-3">
                      <span
                        className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold ${httpStatusClass(log.status_code)}`}
                      >
                        {log.status_code || '—'}
                      </span>
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-slate-600">
                      {log.exception_class || '—'}
                    </td>
                    <td className="max-w-sm truncate px-4 py-3 text-slate-600">{log.message}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        {meta ? (
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-xs text-slate-500">
            <span>
              Showing {(meta.current_page - 1) * meta.per_page + (logs.length ? 1 : 0)}–
              {(meta.current_page - 1) * meta.per_page + logs.length} of {meta.total} · {meta.per_page}{' '}
              per page
            </span>
            <div className="flex flex-wrap items-center gap-2">
              <button
                type="button"
                disabled={page <= 1 || loading}
                onClick={() => setPage(1)}
                className="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-40"
              >
                First
              </button>
              <button
                type="button"
                disabled={page <= 1 || loading}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                className="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-40"
              >
                Prev
              </button>
              <span className="px-1 font-medium text-slate-700">
                Page {meta.current_page} / {meta.last_page || 1}
              </span>
              <button
                type="button"
                disabled={page >= (meta.last_page || 1) || loading}
                onClick={() => setPage((p) => p + 1)}
                className="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-40"
              >
                Next
              </button>
              <button
                type="button"
                disabled={page >= (meta.last_page || 1) || loading}
                onClick={() => setPage(meta.last_page || 1)}
                className="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-40"
              >
                Last
              </button>
            </div>
          </div>
        ) : null}
      </div>

      {detailLoading ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
          <div className="rounded-2xl bg-white px-6 py-4 text-sm text-slate-700 shadow-xl">
            Loading log detail…
          </div>
        </div>
      ) : null}

      {selected ? (
        <div
          className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-4 sm:items-center"
          onClick={() => setSelected(null)}
          onKeyDown={(e) => {
            if (e.key === 'Escape') setSelected(null);
          }}
          role="presentation"
        >
          <div
            className="max-h-[85vh] w-full max-w-3xl overflow-auto rounded-2xl bg-white p-5 shadow-xl"
            onClick={(e) => e.stopPropagation()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="log-detail-title"
          >
            <div className="mb-4 flex items-start justify-between gap-4">
              <div>
                <h2 id="log-detail-title" className="text-lg font-semibold text-slate-900">
                  Log detail
                </h2>
                <p className="mt-1 font-mono text-sm text-slate-500">
                  {selected.method} {selected.path}
                </p>
              </div>
              <button
                type="button"
                className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50"
                onClick={() => setSelected(null)}
              >
                Close
              </button>
            </div>
            <div className="mb-4">
              <span
                className={`inline-flex rounded-full border px-2.5 py-0.5 text-xs font-semibold ${httpStatusClass(selected.status_code)}`}
              >
                HTTP {selected.status_code || '—'}
              </span>
            </div>
            <div className="grid gap-3 text-sm sm:grid-cols-2">
              <div>
                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">When</div>
                <div className="mt-1 text-slate-800">
                  {selected.occurred_at ? new Date(selected.occurred_at).toLocaleString() : '—'}
                </div>
              </div>
              <div>
                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">User</div>
                <div className="mt-1 text-slate-800">
                  {selected.user?.display_name || 'Guest'}
                  {selected.user?.phone ? ` · ${selected.user.phone}` : ''}
                </div>
              </div>
              <div>
                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Exception / type
                </div>
                <div className="mt-1 font-mono text-xs text-slate-800">
                  {selected.exception_class || '—'}
                </div>
              </div>
              <div>
                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Request ID
                </div>
                <div className="mt-1 break-all font-mono text-xs text-slate-800">
                  {selected.request_id || '—'}
                </div>
              </div>
              <div>
                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">IP</div>
                <div className="mt-1 font-mono text-xs text-slate-800">
                  {selected.ip_address || '—'}
                </div>
              </div>
              <div>
                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">UUID</div>
                <div className="mt-1 break-all font-mono text-xs text-slate-800">{selected.uuid}</div>
              </div>
            </div>
            <div className="mt-4">
              <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Message</div>
              <pre className="mt-1 whitespace-pre-wrap rounded-xl bg-slate-50 p-3 text-xs text-slate-800">
                {selected.message || '—'}
              </pre>
            </div>
            {selected.trace ? (
              <div className="mt-4">
                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Details / response / trace
                </div>
                <pre className="mt-1 max-h-80 overflow-auto whitespace-pre-wrap rounded-xl bg-slate-950 p-3 text-xs text-slate-100">
                  {selected.trace}
                </pre>
              </div>
            ) : null}
          </div>
        </div>
      ) : null}
    </div>
  );
}
