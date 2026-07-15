import { useEffect, useMemo, useState } from 'react';
import { Link, Navigate } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';
import {
  HttpStatusBadge,
  mergeStatusCodes,
  PaginationBar,
} from '../components';
import { LogsShimmer, Shimmer } from '../shimmer';

function statusBadge(status) {
  const map = {
    ok: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    degraded: 'bg-amber-50 text-amber-800 border-amber-200',
    down: 'bg-red-50 text-red-700 border-red-200',
  };
  return map[status] || 'bg-slate-50 text-slate-600 border-slate-200';
}

const emptyFilters = {
  q: '',
  status_code: '',
  from: '',
  to: '',
};

const PER_PAGE = 20;

export function LogsPage() {
  const { isAdmin } = useAuth();
  const [logs, setLogs] = useState([]);
  const [meta, setMeta] = useState(null);
  const [health, setHealth] = useState(null);
  const [statusCodes, setStatusCodes] = useState([]);
  const [selected, setSelected] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [draft, setDraft] = useState(emptyFilters);
  const [filters, setFilters] = useState(emptyFilters);
  const [page, setPage] = useState(1);

  const statusOptions = useMemo(() => mergeStatusCodes(statusCodes), [statusCodes]);

  useEffect(() => {
    if (!isAdmin) {
      return undefined;
    }

    let cancelled = false;
    (async () => {
      try {
        const codesData = await api('/admin/system-logs/status-codes');
        if (!cancelled) {
          setStatusCodes(codesData.status_codes || []);
        }
      } catch {
        // Dropdown still has COMMON_HTTP_STATUS_CODES via mergeStatusCodes.
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [isAdmin]);

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
          per_page: String(PER_PAGE),
          page: String(page),
        });
        if (filters.q.trim()) query.set('q', filters.q.trim());
        if (filters.status_code) query.set('status_code', String(filters.status_code));
        if (filters.from) query.set('from', filters.from);
        if (filters.to) query.set('to', filters.to);

        const [logsData, healthData] = await Promise.all([
          api(`/admin/system-logs?${query.toString()}`),
          api('/admin/websocket-health'),
        ]);
        if (cancelled) return;

        const rows = Array.isArray(logsData?.data)
          ? logsData.data
          : Array.isArray(logsData)
            ? logsData
            : [];
        setLogs(rows.slice(0, PER_PAGE));
        setMeta(
          logsData?.meta || {
            current_page: page,
            per_page: PER_PAGE,
            total: rows.length,
            last_page: 1,
          },
        );
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
            API responses, WebSocket health, and app-side reports (badge <span className="font-mono">App</span>, path{' '}
            <span className="font-mono">app/…</span>). Search <span className="font-mono">app/media</span>,{' '}
            <span className="font-mono">app/chat</span>, or <span className="font-mono">app/stream</span>.
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

      <form
        onSubmit={applyFilters}
        className="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
      >
        <div className="mb-3 text-sm font-semibold text-slate-800">Search API logs</div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label className="block text-xs text-slate-500 sm:col-span-2 lg:col-span-2">
            Search (message, exception, path)
            <input
              value={draft.q}
              onChange={(e) => setDraft((prev) => ({ ...prev, q: e.target.value }))}
              placeholder="e.g. ValidationException, upload…"
              className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            />
          </label>
          <label className="block text-xs text-slate-500">
            Status code
            <select
              value={draft.status_code}
              onChange={(e) => setDraft((prev) => ({ ...prev, status_code: e.target.value }))}
              className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            >
              <option value="">All status codes</option>
              {statusOptions.map((code) => (
                <option key={code} value={String(code)}>
                  {code} — {code >= 200 && code < 300
                    ? 'Success'
                    : code >= 300 && code < 400
                      ? 'Redirect'
                      : code >= 400 && code < 500
                        ? 'Client error'
                        : 'Server error'}
                </option>
              ))}
            </select>
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
                      <HttpStatusBadge code={log.status_code} method={log.method} />
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
        <PaginationBar
          meta={meta}
          page={page}
          loading={loading}
          itemCount={logs.length}
          onPageChange={setPage}
        />
      </div>

      <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
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
              <HttpStatusBadge code={selected.status_code} method={selected.method} />
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
