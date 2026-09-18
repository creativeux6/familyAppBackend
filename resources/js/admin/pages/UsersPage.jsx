import { useEffect, useState } from 'react';
import { Link, Navigate } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';
import { formatBytes, formatPriceCents, formatUsd, PaginationBar } from '../components';
import { Shimmer } from '../shimmer';

function UsageBar({ used, limit, label }) {
  const u = Number(used) || 0;
  const l = Number(limit) || 0;
  const pct = l > 0 ? Math.min(100, Math.round((u / l) * 100)) : 0;
  return (
    <div>
      <div className="mb-1 flex justify-between text-xs text-slate-500">
        <span>{label}</span>
        <span>
          {formatBytes(u)} / {l ? formatBytes(l) : '—'} ({pct}%)
        </span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-slate-100">
        <div className="h-full rounded-full bg-indigo-500" style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}

function formatDate(value) {
  if (!value) return '—';
  try {
    return new Date(value).toLocaleDateString();
  } catch {
    return '—';
  }
}

function formatDateTime(value) {
  if (!value) return '—';
  try {
    return new Date(value).toLocaleString();
  } catch {
    return '—';
  }
}

export function UsersPage() {
  const { isAdmin } = useAuth();
  const [users, setUsers] = useState([]);
  const [meta, setMeta] = useState(null);
  const [plans, setPlans] = useState([]);
  const [search, setSearch] = useState('');
  const [appliedSearch, setAppliedSearch] = useState('');
  const [includeTrashed, setIncludeTrashed] = useState(false);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyUuid, setBusyUuid] = useState('');
  const [selected, setSelected] = useState(null);
  const [assignPlanUuid, setAssignPlanUuid] = useState('');
  const [overrideGb, setOverrideGb] = useState('');

  useEffect(() => {
    if (!isAdmin) return undefined;
    let cancelled = false;
    (async () => {
      try {
        const data = await api('/admin/storage/plans');
        if (!cancelled) setPlans(data.plans || []);
      } catch {
        // Plans optional for list; assign sheet needs them.
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [isAdmin]);

  useEffect(() => {
    if (!isAdmin) return undefined;
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError('');
      try {
        const query = new URLSearchParams({
          page: String(page),
          per_page: String(PER_PAGE),
          include_trashed: includeTrashed ? '1' : '0',
        });
        if (appliedSearch.trim()) query.set('search', appliedSearch.trim());
        const data = await api(`/admin/users?${query.toString()}`);
        if (cancelled) return;
        setUsers(data.users || []);
        setMeta(data.meta || null);
      } catch (err) {
        if (!cancelled) setError(err.message || 'Could not load users');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [isAdmin, page, appliedSearch, includeTrashed]);

  if (!isAdmin) {
    return <Navigate to="/web" replace />;
  }

  async function refreshList() {
    const query = new URLSearchParams({
      page: String(page),
      per_page: String(PER_PAGE),
      include_trashed: includeTrashed ? '1' : '0',
    });
    if (appliedSearch.trim()) query.set('search', appliedSearch.trim());
    const data = await api(`/admin/users?${query.toString()}`);
    setUsers(data.users || []);
    setMeta(data.meta || null);
  }

  async function openUser(uuid) {
    setBusyUuid(uuid);
    setError('');
    try {
      const detail = await api(`/admin/users/${uuid}`);
      setSelected(detail);
      setAssignPlanUuid(detail.storage?.plan?.uuid || detail.plan_assignment?.plan?.uuid || '');
      const quotaBytes = detail.storage?.quota_bytes;
      const planQuota = detail.storage?.plan?.storage_limit_bytes
        ?? detail.storage?.plan?.quota_bytes;
      const isCustom =
        quotaBytes &&
        planQuota &&
        Number(quotaBytes) > Number(planQuota);
      setOverrideGb(
        isCustom
          ? String(Math.round((Number(quotaBytes) / 1073741824) * 100) / 100)
          : '',
      );
    } catch (err) {
      setError(err.message || 'Could not load user');
    } finally {
      setBusyUuid('');
    }
  }

  async function banUser(uuid) {
    if (!window.confirm('Ban this user? They will be soft-deleted and tokens revoked.')) return;
    setBusyUuid(uuid);
    setError('');
    try {
      await api(`/admin/users/${uuid}`, { method: 'DELETE' });
      setSelected(null);
      await refreshList();
    } catch (err) {
      setError(err.message || 'Could not ban user');
    } finally {
      setBusyUuid('');
    }
  }

  async function restoreUser(uuid) {
    setBusyUuid(uuid);
    setError('');
    try {
      await api(`/admin/users/${uuid}/restore`, { method: 'POST' });
      setSelected(null);
      await refreshList();
    } catch (err) {
      setError(err.message || 'Could not restore user');
    } finally {
      setBusyUuid('');
    }
  }

  async function assignPlan() {
    if (!selected?.user?.uuid || !assignPlanUuid) return;
    setBusyUuid(selected.user.uuid);
    setError('');
    try {
      await api(`/admin/storage/users/${selected.user.uuid}/assign`, {
        method: 'POST',
        body: { storage_plan_uuid: assignPlanUuid },
      });
      const detail = await api(`/admin/users/${selected.user.uuid}`);
      setSelected(detail);
      await refreshList();
    } catch (err) {
      setError(err.message || 'Could not assign plan');
    } finally {
      setBusyUuid('');
    }
  }

  async function resetAccessUsage() {
    if (!selected?.user?.uuid) return;
    setBusyUuid(selected.user.uuid);
    setError('');
    try {
      const detail = await api(`/admin/users/${selected.user.uuid}/access-usage/reset`, {
        method: 'POST',
      });
      setSelected(detail);
      await refreshList();
    } catch (err) {
      setError(err.message || 'Could not reset access usage');
    } finally {
      setBusyUuid('');
    }
  }

  async function saveStorageGrant() {
    if (!selected?.user?.uuid) return;
    setBusyUuid(selected.user.uuid);
    setError('');
    try {
      const detail = await api(`/admin/users/${selected.user.uuid}/storage-grant`, {
        method: 'PATCH',
        body: { storage_limit_gb: Number(overrideGb) },
      });
      setSelected(detail);
      const quotaBytes = detail.storage?.quota_bytes;
      setOverrideGb(
        quotaBytes
          ? String(Math.round((Number(quotaBytes) / 1073741824) * 100) / 100)
          : '',
      );
      await refreshList();
    } catch (err) {
      setError(err.message || 'Could not save storage grant');
    } finally {
      setBusyUuid('');
    }
  }

  async function clearStorageGrant() {
    if (!selected?.user?.uuid) return;
    setBusyUuid(selected.user.uuid);
    setError('');
    try {
      const detail = await api(`/admin/users/${selected.user.uuid}/storage-grant`, {
        method: 'PATCH',
        body: { clear: true },
      });
      setSelected(detail);
      setOverrideGb('');
      await refreshList();
    } catch (err) {
      setError(err.message || 'Could not clear storage grant');
    } finally {
      setBusyUuid('');
    }
  }

  async function makeFree() {
    if (!selected?.user?.uuid) return;
    if (
      !window.confirm(
        'Move this user to Free immediately (no payment)? You can then set a custom storage limit.',
      )
    ) {
      return;
    }
    setBusyUuid(selected.user.uuid);
    setError('');
    try {
      const detail = await api(`/admin/users/${selected.user.uuid}/make-free`, {
        method: 'POST',
      });
      setSelected(detail);
      setAssignPlanUuid(detail.storage?.plan?.uuid || '');
      await refreshList();
    } catch (err) {
      setError(err.message || 'Could not move user to Free');
    } finally {
      setBusyUuid('');
    }
  }

  const management = selected?.management;
  const storage = selected?.storage;
  const plan =
    storage?.plan || selected?.plan_assignment?.plan || null;
  const isFreePlan = (plan?.slug || selected?.user?.plan_slug) === 'free';
  const derivedAccessBytes = overrideGb
    ? Math.round(Number(overrideGb) * 1073741824 * 3)
    : storage?.access_quota_bytes;
  const cycleStart =
    selected?.plan_assignment?.starts_at || selected?.user?.plan_starts_at;
  const cycleEnd =
    selected?.plan_assignment?.ends_at ||
    selected?.plan_assignment?.renewal_date ||
    selected?.user?.renewal_date;

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Users</h1>
          <p className="mt-1 text-sm text-slate-500">
            Operational account view — storage, plans, and membership. No personal contact or media
            content is shown.
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
        className="mb-4 flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
        onSubmit={(e) => {
          e.preventDefault();
          setPage(1);
          setAppliedSearch(search);
        }}
      >
        <label className="block min-w-[14rem] flex-1 text-xs text-slate-500">
          Search account name or ID
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            placeholder="Display name or UUID…"
          />
        </label>
        <label className="flex items-center gap-2 pb-2 text-sm text-slate-700">
          <input
            type="checkbox"
            checked={includeTrashed}
            onChange={(e) => {
              setPage(1);
              setIncludeTrashed(e.target.checked);
            }}
          />
          Include banned
        </label>
        <button
          type="submit"
          className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
        >
          Search
        </button>
      </form>

      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="overflow-x-auto">
          <table className="min-w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-4 py-3">Account</th>
                <th className="px-4 py-3">Plan</th>
                <th className="px-4 py-3">Quota</th>
                <th className="px-4 py-3">Usage</th>
                <th className="px-4 py-3">Access</th>
                <th className="px-4 py-3">Next bill</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                Array.from({ length: 5 }).map((_, i) => (
                  <tr key={i} className="border-t border-slate-100">
                    <td colSpan={8} className="px-4 py-3">
                      <Shimmer className="h-4 w-full" />
                    </td>
                  </tr>
                ))
              ) : users.length === 0 ? (
                <tr>
                    <td colSpan={8} className="px-4 py-8 text-slate-500">
                    No users found.
                  </td>
                </tr>
              ) : (
                users.map((user) => {
                  const banned = Boolean(user.deleted_at);
                  return (
                    <tr key={user.uuid} className="border-t border-slate-100">
                      <td className="px-4 py-3">
                        <div className="font-medium text-slate-800">{user.display_name || '—'}</div>
                        <div className="mt-0.5 font-mono text-[11px] text-slate-400">
                          {user.uuid?.slice(0, 8)}…
                        </div>
                      </td>
                      <td className="px-4 py-3 text-slate-700">
                        <div className="font-medium">{user.plan_name || '—'}</div>
                        {user.billing_period_label ? (
                          <div className="mt-0.5 text-[11px] text-slate-400">
                            {user.billing_period_label}
                          </div>
                        ) : user.plan_source ? (
                          <div className="mt-0.5 text-[11px] text-slate-400">{user.plan_source}</div>
                        ) : null}
                      </td>
                      <td className="px-4 py-3 text-slate-600">
                        {user.quota_bytes != null ? formatBytes(user.quota_bytes) : '—'}
                      </td>
                      <td className="px-4 py-3 text-slate-600">
                        {formatBytes(user.storage_total_used_bytes ?? user.storage_used_bytes)}
                        {user.quota_bytes != null ? (
                          <span className="text-slate-400"> / {formatBytes(user.quota_bytes)}</span>
                        ) : null}
                      </td>
                      <td className="px-4 py-3 text-slate-600">
                        {formatBytes(user.access_used_bytes)}
                        {user.access_quota_bytes != null ? (
                          <span className="text-slate-400"> / {formatBytes(user.access_quota_bytes)}</span>
                        ) : null}
                      </td>
                      <td className="whitespace-nowrap px-4 py-3 text-slate-600">
                        {formatDate(user.renewal_date)}
                      </td>
                      <td className="px-4 py-3">
                        <span
                          className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold ${
                            banned
                              ? 'border-red-200 bg-red-50 text-red-700'
                              : 'border-emerald-200 bg-emerald-50 text-emerald-700'
                          }`}
                        >
                          {banned ? 'Banned' : user.billing_status === 'media_locked'
                            ? 'Media locked'
                            : user.billing_status === 'past_due'
                              ? 'Past due'
                              : 'Active'}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap gap-2">
                          <button
                            type="button"
                            disabled={busyUuid === user.uuid}
                            onClick={() => openUser(user.uuid)}
                            className="rounded-lg border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50 disabled:opacity-40"
                          >
                            View
                          </button>
                          {banned ? (
                            <button
                              type="button"
                              disabled={busyUuid === user.uuid}
                              onClick={() => restoreUser(user.uuid)}
                              className="rounded-lg border border-emerald-200 px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50 disabled:opacity-40"
                            >
                              Restore
                            </button>
                          ) : (
                            <button
                              type="button"
                              disabled={busyUuid === user.uuid}
                              onClick={() => banUser(user.uuid)}
                              className="rounded-lg border border-red-200 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-40"
                            >
                              Ban
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
        <PaginationBar
          meta={meta}
          page={page}
          loading={loading}
          itemCount={users.length}
          onPageChange={setPage}
        />
      </div>

      {selected ? (
        <div
          className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-4 sm:items-center"
          onClick={() => setSelected(null)}
          role="presentation"
        >
          <div
            className="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-5 shadow-xl"
            onClick={(e) => e.stopPropagation()}
            role="dialog"
            aria-modal="true"
          >
            <div className="mb-4 flex items-start justify-between gap-3">
              <div>
                <h2 className="text-lg font-semibold text-slate-900">Account overview</h2>
                <p className="mt-1 text-sm text-slate-600">
                  {selected.user?.display_name || 'Account'}
                </p>
                <p className="mt-0.5 font-mono text-[11px] text-slate-400">{selected.user?.uuid}</p>
              </div>
              <button
                type="button"
                className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm"
                onClick={() => setSelected(null)}
              >
                Close
              </button>
            </div>

            <dl className="space-y-3 text-sm">
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Account type</dt>
                <dd className="font-medium text-slate-800">
                  {management?.account_mode_label || '—'}
                </dd>
              </div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Family members</dt>
                <dd className="font-medium text-slate-800">
                  {management?.account_mode === 'family'
                    ? management?.family_member_count ?? 0
                    : '—'}
                </dd>
              </div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Connected members</dt>
                <dd className="font-medium text-slate-800">
                  {management?.connected_members_count ?? 0}
                </dd>
              </div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Roles</dt>
                <dd className="font-medium text-slate-800">
                  {(selected.roles || []).join(', ') || 'user'}
                </dd>
              </div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Plan</dt>
                <dd className="text-right font-medium text-slate-800">
                  {plan?.name || '—'}
                  {plan?.display_price_cents != null ? (
                    <div className="text-xs font-normal text-slate-500">
                      {formatPriceCents(plan.display_price_cents, plan.currency)}
                    </div>
                  ) : null}
                </dd>
              </div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Billing status</dt>
                <dd className="font-medium text-slate-800">
                  {storage?.billing_status || selected?.plan_assignment?.billing_status || 'active'}
                </dd>
              </div>
              {storage?.is_pool_owner === false ? (
                <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                  <dt className="text-slate-500">Pool owner</dt>
                  <dd className="font-medium text-slate-800">{storage?.pool_owner_name || '—'}</dd>
                </div>
              ) : null}
              <div className="space-y-3 border-b border-slate-100 py-3">
                <UsageBar
                  label="Storage"
                  used={storage?.stored_bytes ?? storage?.used_bytes}
                  limit={storage?.quota_bytes}
                />
                <UsageBar
                  label="Monthly access"
                  used={storage?.access_used_bytes}
                  limit={storage?.access_quota_bytes}
                />
              </div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Streaming</dt>
                <dd className="font-medium text-slate-800">{formatBytes(storage?.streamed_bytes)}</dd>
              </div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Downloads</dt>
                <dd className="font-medium text-slate-800">{formatBytes(storage?.downloaded_bytes)}</dd>
              </div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">File views</dt>
                <dd className="font-medium text-slate-800">{formatBytes(storage?.file_viewed_bytes)}</dd>
              </div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Estimated cost</dt>
                <dd className="font-medium text-slate-800">{formatUsd(storage?.estimated_cost_usd)}</dd>
              </div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Revenue</dt>
                <dd className="font-medium text-slate-800">{formatUsd(storage?.revenue_usd)}</dd>
              </div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Net contribution</dt>
                <dd className="font-medium text-slate-800">{formatUsd(storage?.net_contribution_usd)}</dd>
              </div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Owned bytes (this user)</dt>
                <dd className="font-medium text-slate-800">{formatBytes(storage?.owned_bytes)}</dd>
              </div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Seats</dt>
                <dd className="text-right font-medium text-slate-800">
                  {storage?.membership?.member_count ?? 0} members ·{' '}
                  {storage?.membership?.empty_seats ?? 0} empty
                  {(storage?.membership?.members || []).length ? (
                    <div className="mt-1 text-xs font-normal text-slate-500">
                      {(storage.membership.members || [])
                        .map((m) => `${m.display_name || m.user_uuid} (${m.role})`)
                        .join(', ')}
                    </div>
                  ) : null}
                </dd>
              </div>
              {storage?.pending_plan ? (
                <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                  <dt className="text-slate-500">Pending downgrade</dt>
                  <dd className="font-medium text-slate-800">{storage.pending_plan.name}</dd>
                </div>
              ) : null}
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Plan cycle start</dt>
                <dd className="font-medium text-slate-800">{formatDateTime(cycleStart)}</dd>
              </div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt className="text-slate-500">Next bill / cycle end</dt>
                <dd className="font-medium text-slate-800">{formatDateTime(cycleEnd)}</dd>
              </div>
              <div className="flex justify-between gap-4 pb-1">
                <dt className="text-slate-500">Lifetime egress</dt>
                <dd className="font-medium text-slate-800">
                  {formatBytes(storage?.lifetime_read_bytes ?? storage?.read_bytes)}
                </dd>
              </div>
            </dl>

            {(storage?.past_periods || []).length ? (
              <div className="mt-3 text-xs text-slate-500">
                Past periods:{' '}
                {storage.past_periods
                  .map(
                    (p) =>
                      `${formatDate(p.period_start)} · stored ${formatBytes(p.storage_used_bytes)} · access ${formatBytes(p.monthly_access_bytes)}`,
                  )
                  .join(' | ')}
              </div>
            ) : null}

            <p className="mt-3 text-xs text-slate-500">
              Storage quota is stored bytes on the pool. Monthly access meters reset each cycle.
              Stream/download/view costs are admin-only. Billing advances price only — stored usage
              is not reset.
            </p>

            <button
              type="button"
              disabled={busyUuid === selected.user?.uuid}
              onClick={resetAccessUsage}
              className="mt-3 w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            >
              Reset monthly access usage
            </button>

            <div className="mt-4 space-y-3 border-t border-slate-100 pt-4">
              <h3 className="text-sm font-semibold text-slate-800">Free storage grant</h3>
              <p className="text-xs text-slate-500">
                Custom limits only for Free-plan users. Monthly access scales to 3× storage.
              </p>
              {isFreePlan ? (
                <>
                  <label className="block text-xs text-slate-500">
                    Custom storage (GB)
                    <input
                      type="number"
                      min="1"
                      step="1"
                      value={overrideGb}
                      onChange={(e) => setOverrideGb(e.target.value)}
                      placeholder="e.g. 100"
                      className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                    />
                  </label>
                  <p className="text-xs text-slate-500">
                    Monthly access ≈ {derivedAccessBytes ? formatBytes(derivedAccessBytes) : '—'}
                  </p>
                  <div className="flex gap-2">
                    <button
                      type="button"
                      disabled={!overrideGb || busyUuid === selected.user?.uuid}
                      onClick={saveStorageGrant}
                      className="flex-1 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-40"
                    >
                      Save grant
                    </button>
                    <button
                      type="button"
                      disabled={busyUuid === selected.user?.uuid}
                      onClick={clearStorageGrant}
                      className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                    >
                      Clear
                    </button>
                  </div>
                </>
              ) : (
                <>
                  <p className="text-xs text-amber-700">
                    User is on {plan?.name || 'a paid plan'}. Move to Free before setting a custom
                    limit.
                  </p>
                  <button
                    type="button"
                    disabled={busyUuid === selected.user?.uuid}
                    onClick={makeFree}
                    className="w-full rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100 disabled:opacity-40"
                  >
                    Move to Free
                  </button>
                </>
              )}
            </div>

            <div className="mt-4 border-t border-slate-100 pt-4">
              <label className="block text-xs text-slate-500">
                Assign storage plan
                <select
                  value={assignPlanUuid}
                  onChange={(e) => setAssignPlanUuid(e.target.value)}
                  className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                >
                  <option value="">Select plan…</option>
                  {plans.map((p) => (
                    <option key={p.uuid} value={p.uuid}>
                      {p.name} ({formatBytes(p.quota_bytes)})
                    </option>
                  ))}
                </select>
              </label>
              <button
                type="button"
                disabled={!assignPlanUuid || busyUuid === selected.user?.uuid}
                onClick={assignPlan}
                className="mt-3 w-full rounded-xl bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-40"
              >
                Save plan assignment
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
