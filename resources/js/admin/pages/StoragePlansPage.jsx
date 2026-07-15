import { useEffect, useState } from 'react';
import { Link, Navigate } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';
import { formatBytes, formatPriceCents } from '../components';
import { Shimmer } from '../shimmer';

const emptyForm = {
  name: '',
  slug: '',
  description: '',
  quota_gb: '5',
  price: '0',
  currency: 'USD',
  is_active: true,
  sort_order: '10',
};

function slugify(value) {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60);
}

function planToForm(plan) {
  return {
    name: plan.name || '',
    slug: plan.slug || '',
    description: plan.description || '',
    quota_gb: String((Number(plan.quota_bytes) || 0) / (1024 * 1024 * 1024)),
    price: String(((Number(plan.display_price_cents) || 0) / 100).toFixed(2)),
    currency: plan.currency || 'USD',
    is_active: Boolean(plan.is_active),
    sort_order: String(plan.sort_order ?? 0),
  };
}

function formToPayload(form) {
  const quotaGb = Number(form.quota_gb);
  const price = Number(form.price);
  return {
    name: form.name.trim(),
    slug: form.slug.trim(),
    description: form.description.trim() || null,
    quota_bytes: Math.round(quotaGb * 1024 * 1024 * 1024),
    display_price_cents: Math.round(price * 100),
    currency: (form.currency || 'USD').toUpperCase(),
    is_active: Boolean(form.is_active),
    sort_order: Number(form.sort_order) || 0,
  };
}

export function StoragePlansPage() {
  const { isAdmin } = useAuth();
  const [plans, setPlans] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const [editingUuid, setEditingUuid] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [showForm, setShowForm] = useState(false);

  async function loadPlans() {
    setLoading(true);
    setError('');
    try {
      const data = await api('/admin/storage/plans');
      setPlans(data.plans || []);
    } catch (err) {
      setError(err.message || 'Could not load plans');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    if (!isAdmin) return undefined;
    loadPlans();
    return undefined;
  }, [isAdmin]);

  if (!isAdmin) {
    return <Navigate to="/web" replace />;
  }

  function startCreate() {
    setEditingUuid(null);
    setForm(emptyForm);
    setShowForm(true);
  }

  function startEdit(plan) {
    setEditingUuid(plan.uuid);
    setForm(planToForm(plan));
    setShowForm(true);
  }

  async function savePlan(event) {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      const payload = formToPayload(form);
      if (!payload.name || !payload.slug || payload.quota_bytes < 1) {
        throw new Error('Name, slug, and data limit are required.');
      }
      if (editingUuid) {
        await api(`/admin/storage/plans/${editingUuid}`, {
          method: 'PATCH',
          body: payload,
        });
      } else {
        await api('/admin/storage/plans', { method: 'POST', body: payload });
      }
      setShowForm(false);
      setEditingUuid(null);
      setForm(emptyForm);
      await loadPlans();
    } catch (err) {
      setError(err.message || 'Could not save plan');
    } finally {
      setSaving(false);
    }
  }

  async function toggleActive(plan) {
    setError('');
    try {
      await api(`/admin/storage/plans/${plan.uuid}`, {
        method: 'PATCH',
        body: { is_active: !plan.is_active },
      });
      await loadPlans();
    } catch (err) {
      setError(err.message || 'Could not update plan');
    }
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Storage plans</h1>
          <p className="mt-1 text-sm text-slate-500">
            Manage plan name, description, data limit, and price. Free (5 GB) is seeded by default.
          </p>
        </div>
        <div className="flex flex-wrap gap-3">
          <Link to="/web" className="text-sm text-indigo-600 hover:underline">
            Back to dashboard
          </Link>
          <button
            type="button"
            onClick={startCreate}
            className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
          >
            New plan
          </button>
        </div>
      </div>

      {error ? (
        <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {error}
        </div>
      ) : null}

      {showForm ? (
        <form
          onSubmit={savePlan}
          className="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"
        >
          <div className="mb-3 text-sm font-semibold text-slate-800">
            {editingUuid ? 'Edit plan' : 'Create plan'}
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block text-xs text-slate-500">
              Plan name
              <input
                required
                value={form.name}
                onChange={(e) => {
                  const name = e.target.value;
                  setForm((prev) => ({
                    ...prev,
                    name,
                    slug: editingUuid ? prev.slug : slugify(name),
                  }));
                }}
                className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
              />
            </label>
            <label className="block text-xs text-slate-500">
              Slug
              <input
                required
                value={form.slug}
                onChange={(e) => setForm((prev) => ({ ...prev, slug: slugify(e.target.value) }))}
                className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
              />
            </label>
            <label className="block text-xs text-slate-500 sm:col-span-2">
              Description
              <textarea
                rows={3}
                value={form.description}
                onChange={(e) => setForm((prev) => ({ ...prev, description: e.target.value }))}
                className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                placeholder="Shown in admin and plan catalog"
              />
            </label>
            <label className="block text-xs text-slate-500">
              Data limit (GB)
              <input
                required
                type="number"
                min="0.1"
                step="0.1"
                value={form.quota_gb}
                onChange={(e) => setForm((prev) => ({ ...prev, quota_gb: e.target.value }))}
                className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
              />
            </label>
            <label className="block text-xs text-slate-500">
              Price
              <input
                required
                type="number"
                min="0"
                step="0.01"
                value={form.price}
                onChange={(e) => setForm((prev) => ({ ...prev, price: e.target.value }))}
                className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
              />
            </label>
            <label className="block text-xs text-slate-500">
              Currency
              <input
                value={form.currency}
                maxLength={3}
                onChange={(e) => setForm((prev) => ({ ...prev, currency: e.target.value }))}
                className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm uppercase"
              />
            </label>
            <label className="block text-xs text-slate-500">
              Sort order
              <input
                type="number"
                min="0"
                value={form.sort_order}
                onChange={(e) => setForm((prev) => ({ ...prev, sort_order: e.target.value }))}
                className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
              />
            </label>
            <label className="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(e) => setForm((prev) => ({ ...prev, is_active: e.target.checked }))}
              />
              Active (visible for assignment)
            </label>
          </div>
          <div className="mt-4 flex flex-wrap gap-2">
            <button
              type="submit"
              disabled={saving}
              className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
            >
              {saving ? 'Saving…' : editingUuid ? 'Update plan' : 'Create plan'}
            </button>
            <button
              type="button"
              onClick={() => {
                setShowForm(false);
                setEditingUuid(null);
              }}
              className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
            >
              Cancel
            </button>
          </div>
        </form>
      ) : null}

      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="overflow-x-auto">
          <table className="min-w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-4 py-3">Plan</th>
                <th className="px-4 py-3">Data limit</th>
                <th className="px-4 py-3">Price</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                Array.from({ length: 3 }).map((_, i) => (
                  <tr key={i} className="border-t border-slate-100">
                    <td colSpan={5} className="px-4 py-3">
                      <Shimmer className="h-4 w-full" />
                    </td>
                  </tr>
                ))
              ) : plans.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-4 py-8 text-slate-500">
                    No plans yet. Run{' '}
                    <code className="rounded bg-slate-100 px-1">php artisan db:seed --class=StoragePlanSeeder</code>{' '}
                    or create one above.
                  </td>
                </tr>
              ) : (
                plans.map((plan) => (
                  <tr key={plan.uuid} className="border-t border-slate-100 align-top">
                    <td className="px-4 py-3">
                      <div className="font-medium text-slate-900">{plan.name}</div>
                      <div className="mt-0.5 font-mono text-xs text-slate-500">{plan.slug}</div>
                      {plan.description ? (
                        <div className="mt-1 max-w-md text-xs text-slate-600">{plan.description}</div>
                      ) : null}
                    </td>
                    <td className="px-4 py-3 text-slate-700">{formatBytes(plan.quota_bytes)}</td>
                    <td className="px-4 py-3 text-slate-700">
                      {formatPriceCents(plan.display_price_cents, plan.currency)}
                    </td>
                    <td className="px-4 py-3">
                      <span
                        className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold ${
                          plan.is_active
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                            : 'border-slate-200 bg-slate-50 text-slate-600'
                        }`}
                      >
                        {plan.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-2">
                        <button
                          type="button"
                          onClick={() => startEdit(plan)}
                          className="rounded-lg border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50"
                        >
                          Edit
                        </button>
                        <button
                          type="button"
                          onClick={() => toggleActive(plan)}
                          className="rounded-lg border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50"
                        >
                          {plan.is_active ? 'Deactivate' : 'Activate'}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
