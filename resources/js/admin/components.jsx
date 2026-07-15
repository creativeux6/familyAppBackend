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
    return `$${amount.toFixed(2)}`;
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

/**
 * Build page tokens for numbered pagination, e.g. [1, 2, 3, '…', 20, 21].
 * @param {number} current
 * @param {number} last
 * @param {number} [siblingCount]
 * @returns {Array<number|string>}
 */
export function buildPageItems(current, last, siblingCount = 1) {
  const total = Math.max(1, Number(last) || 1);
  const active = Math.min(Math.max(1, Number(current) || 1), total);

  if (total <= 7) {
    return Array.from({ length: total }, (_, i) => i + 1);
  }

  const pages = new Set([1, total]);
  for (let i = active - siblingCount; i <= active + siblingCount; i += 1) {
    if (i >= 1 && i <= total) {
      pages.add(i);
    }
  }

  // Wider window near edges so patterns like "…, 20, 21" feel natural.
  if (active <= 3) {
    pages.add(2);
    pages.add(3);
    pages.add(4);
  }
  if (active >= total - 2) {
    pages.add(total - 1);
    pages.add(total - 2);
    pages.add(total - 3);
  }

  const sorted = [...pages].sort((a, b) => a - b);
  const items = [];
  let previous = null;

  for (const pageNumber of sorted) {
    if (previous !== null && pageNumber - previous > 1) {
      items.push('…');
    }
    items.push(pageNumber);
    previous = pageNumber;
  }

  return items;
}

function pageButtonClass(active, disabled) {
  if (disabled && !active) {
    return 'cursor-not-allowed border-slate-200 bg-white text-slate-400 opacity-40';
  }
  if (active) {
    return 'cursor-default border-indigo-600 bg-indigo-600 text-white shadow-sm';
  }
  return 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50';
}

export function PaginationBar({
  meta,
  page,
  loading = false,
  onPageChange,
  itemCount = 0,
  siblingCount = 1,
}) {
  const current = meta?.current_page ?? page ?? 1;
  const last = Math.max(1, meta?.last_page ?? 1);
  const perPage = meta?.per_page ?? 20;
  const total = meta?.total ?? itemCount;
  const from = total === 0 ? 0 : (current - 1) * perPage + 1;
  const to = total === 0 ? 0 : (current - 1) * perPage + itemCount;
  const pageItems = buildPageItems(current, last, siblingCount);
  const atStart = current <= 1 || loading;
  const atEnd = current >= last || loading;

  return (
    <div className="flex flex-col gap-3 border-t border-slate-100 bg-slate-50/80 px-4 py-3 text-xs text-slate-600 sm:flex-row sm:items-center sm:justify-between">
      <span>
        Showing {from}–{to} of {total} · {perPage} per page · Page {current} of {last}
      </span>

      <nav className="flex flex-wrap items-center gap-1.5" aria-label="Pagination">
        <button
          type="button"
          aria-label="First page"
          disabled={atStart}
          onClick={() => onPageChange(1)}
          className={`rounded-lg border px-2.5 py-1.5 font-medium ${pageButtonClass(false, atStart)}`}
        >
          First
        </button>
        <button
          type="button"
          aria-label="Previous page"
          disabled={atStart}
          onClick={() => onPageChange(Math.max(1, current - 1))}
          className={`rounded-lg border px-2.5 py-1.5 font-medium ${pageButtonClass(false, atStart)}`}
        >
          Previous
        </button>

        <div className="mx-0.5 flex flex-wrap items-center gap-1">
          {pageItems.map((item, index) => {
            if (item === '…') {
              return (
                <span
                  key={`ellipsis-${index}`}
                  className="px-1.5 py-1.5 font-semibold text-slate-400"
                  aria-hidden
                >
                  …
                </span>
              );
            }

            const active = item === current;
            return (
              <button
                key={item}
                type="button"
                aria-label={`Page ${item}`}
                aria-current={active ? 'page' : undefined}
                disabled={loading || active}
                onClick={() => onPageChange(item)}
                className={`min-w-[2rem] rounded-lg border px-2 py-1.5 text-center font-semibold tabular-nums ${pageButtonClass(active, loading)}`}
              >
                {item}
              </button>
            );
          })}
        </div>

        <button
          type="button"
          aria-label="Next page"
          disabled={atEnd}
          onClick={() => onPageChange(Math.min(last, current + 1))}
          className={`rounded-lg border px-2.5 py-1.5 font-medium ${pageButtonClass(false, atEnd)}`}
        >
          Next
        </button>
        <button
          type="button"
          aria-label="Last page"
          disabled={atEnd}
          onClick={() => onPageChange(last)}
          className={`rounded-lg border px-2.5 py-1.5 font-medium ${pageButtonClass(false, atEnd)}`}
        >
          Last
        </button>
      </nav>
    </div>
  );
}
