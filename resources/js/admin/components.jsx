/** Shared admin UI helpers */

export function formatBytes(bytes) {
  const value = Number(bytes) || 0;
  if (value >= 1073741824) return `${(value / 1073741824).toFixed(1)} GB`;
  if (value >= 1048576) return `${(value / 1048576).toFixed(1)} MB`;
  if (value >= 1024) return `${Math.round(value / 1024)} KB`;
  return `${value} B`;
}

export function formatPriceCents(cents, currency = 'USD') {
  const amount = (Number(cents) || 0) / 100;
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: currency || 'USD',
    }).format(amount);
  } catch {
    return `$${(amount).toFixed(2)}`;
  }
}

export function httpStatusLabel(code) {
  const n = Number(code);
  if (!n) return 'Unknown';
  if (n >= 200 && n < 300) return 'Success';
  if (n >= 300 && n < 400) return 'Redirect';
  if (n >= 400 && n < 500) return 'Client error';
  if (n >= 500) return 'Server error';
  return 'Other';
}

export function httpStatusClass(code) {
  const n = Number(code);
  if (!n) return 'bg-slate-50 text-slate-600 border-slate-200';
  if (n >= 500) return 'bg-red-50 text-red-700 border-red-200';
  if (n >= 400) return 'bg-amber-50 text-amber-900 border-amber-200';
  if (n >= 300) return 'bg-sky-50 text-sky-800 border-sky-200';
  if (n >= 200) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
  return 'bg-slate-50 text-slate-600 border-slate-200';
}

export function HttpStatusBadge({ code }) {
  if (code == null || code === '') {
    return (
      <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs font-semibold text-slate-600">
        —
      </span>
    );
  }

  return (
    <span
      className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold ${httpStatusClass(code)}`}
      title={httpStatusLabel(code)}
    >
      <span>{code}</span>
      <span className="font-medium opacity-80">{httpStatusLabel(code)}</span>
    </span>
  );
}

/** Common HTTP codes always offered in filters, plus any extras from the API. */
export const COMMON_HTTP_STATUS_CODES = [
  200, 201, 204, 301, 302, 304, 400, 401, 403, 404, 405, 408, 409, 413, 419, 422, 429, 500, 502, 503, 504,
];

export function mergeStatusCodes(fromApi = []) {
  return [...new Set([...COMMON_HTTP_STATUS_CODES, ...fromApi.map(Number)].filter(Boolean))].sort(
    (a, b) => a - b,
  );
}

export function PaginationBar({ meta, page, loading, onPageChange, itemCount = 0 }) {
  const current = meta?.current_page ?? page ?? 1;
  const last = meta?.last_page ?? 1;
  const perPage = meta?.per_page ?? 20;
  const total = meta?.total ?? itemCount;
  const from = total === 0 ? 0 : (current - 1) * perPage + 1;
  const to = total === 0 ? 0 : (current - 1) * perPage + itemCount;

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/80 px-4 py-3 text-xs text-slate-600">
      <span>
        Showing {from}–{to} of {total} · {perPage} per page
      </span>
      <div className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          disabled={current <= 1 || loading}
          onClick={() => onPageChange(1)}
          className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 font-medium hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
        >
          First
        </button>
        <button
          type="button"
          disabled={current <= 1 || loading}
          onClick={() => onPageChange(Math.max(1, current - 1))}
          className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 font-medium hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
        >
          Prev
        </button>
        <span className="px-1 font-semibold text-slate-800">
          Page {current} / {last}
        </span>
        <button
          type="button"
          disabled={current >= last || loading}
          onClick={() => onPageChange(current + 1)}
          className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 font-medium hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
        >
          Next
        </button>
        <button
          type="button"
          disabled={current >= last || loading}
          onClick={() => onPageChange(last)}
          className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 font-medium hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
        >
          Last
        </button>
      </div>
    </div>
  );
}
