import { useEffect, useState } from 'react';
import { Link, Navigate } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';
import { formatBytes, PaginationBar } from '../components';
import { Shimmer } from '../shimmer';

const PER_PAGE = 20;

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

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Users</h1>
          <p className="mt-1 text-sm text-slate-500">
            Search, suspend/restore accounts, and assign storage plans.
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
          Search phone, name, or email
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            placeholder="+92… or display name"
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
                <th className="px-4 py-3">User</th>
                <th className="px-4 py-3">Phone</th>
                <th className="px-4 py-3">Roles</th>
                <th className="px-4 py-3">Usage</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                Array.from({ length: 5 }).map((_, i) => (
                  <tr key={i} className="border-t border-slate-100">
                    <td colSpan={6} className="px-4 py-3">
                      <Shimmer className="h-4 w-full" />
                    </td>
                  </tr>
                ))
              ) : users.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-slate-500">
                    No users found.
                  </td>
                </tr>
              ) : (
                users.map((user) => {
                  const banned = Boolean(user.deleted_at);
                  return (
                    <tr key={user.uuid} className="border-t border-slate-100">
                      <td className="px-4 py-3 font-medium text-slate-800">
                        {user.display_name}
                      </td>
                      <td className="px-4 py-3 font-mono text-xs text-slate-600">{user.phone}</td>
                      <td className="px-4 py-3 text-xs text-slate-600">
                        {(user.roles || []).join(', ') || 'user'}
                      </td>
                      <td className="px-4 py-3 text-slate-600">
                        {formatBytes(user.storage_total_used_bytes ?? user.storage_used_bytes)}
                      </td>
                      <td className="px-4 py-3">
                        <span
                          className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold ${
                            banned
                              ? 'border-red-200 bg-red-50 text-red-700'
                              : 'border-emerald-200 bg-emerald-50 text-emerald-700'
                          }`}
                        >
                          {banned ? 'Banned' : 'Active'}
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
                            Manage
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
            className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl"
            onClick={(e) => e.stopPropagation()}
            role="dialog"
            aria-modal="true"
          >
            <div className="mb-4 flex items-start justify-between gap-3">
              <div>
                <h2 className="text-lg font-semibold text-slate-900">
                  {selected.user?.display_name}
                </h2>
                <p className="mt-1 font-mono text-xs text-slate-500">{selected.user?.phone}</p>
              </div>
              <button
                type="button"
                className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm"
                onClick={() => setSelected(null)}
              >
                Close
              </button>
            </div>

            <div className="space-y-2 text-sm text-slate-700">
              <div>
                Roles: {(selected.roles || []).join(', ') || 'user'}
              </div>
              <div>
                Storage:{' '}
                {formatBytes(selected.storage?.used_bytes)} of{' '}
                {formatBytes(selected.storage?.quota_bytes)}
                {selected.storage?.plan?.name
                  ? ` · ${selected.storage.plan.name}`
                  : ''}
              </div>
              <div className="text-xs text-slate-500">
                Stored {formatBytes(selected.storage?.stored_bytes)} · Read{' '}
                {formatBytes(selected.storage?.read_bytes)}
              </div>
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
                  {plans.map((plan) => (
                    <option key={plan.uuid} value={plan.uuid}>
                      {plan.name} ({formatBytes(plan.quota_bytes)})
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
