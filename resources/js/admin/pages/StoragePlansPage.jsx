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
  access_gb: '15',
  stream_gb: '10',
  download_gb: '5',
  file_view_gb: '15',
  price: '0',
  currency: 'USD',
  billing_period: 'monthly',
  is_shared: false,
  max_shared_members: '0',
  is_active: true,
  sort_order: '10',
};

function bytesToGb(bytes) {
  return String((Number(bytes) || 0) / (1024 * 1024 * 1024));
}

function gbToBytes(gb) {
  return Math.round(Number(gb) * 1024 * 1024 * 1024);
}

function slugify(value) {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60);
}

function planToForm(plan) {
  const period =
    plan.slug === 'free' ? 'yearly' : plan.billing_period === 'yearly' ? 'yearly' : 'monthly';

  return {
    name: plan.name || '',
    slug: plan.slug || '',
    description: plan.description || '',
    quota_gb: bytesToGb(plan.storage_limit_bytes || plan.quota_bytes),
    access_gb: bytesToGb(plan.monthly_access_limit_bytes),
    stream_gb: bytesToGb(plan.streaming_limit_bytes),
    download_gb: bytesToGb(plan.download_limit_bytes),
    file_view_gb: bytesToGb(plan.file_view_limit_bytes),
    price: String(((Number(plan.display_price_cents) || 0) / 100).toFixed(2)),
    currency: plan.currency || 'USD',
    billing_period: period,
    is_shared: Boolean(plan.is_shared),
    max_shared_members: String(plan.max_shared_members ?? 0),
    is_active: Boolean(plan.is_active),
    sort_order: String(plan.sort_order ?? 0),
  };
}

function formToPayload(form) {
  const price = Number(form.price);
  const slug = form.slug.trim();
  const billingPeriod = slug === 'free' ? 'yearly' : form.billing_period || 'monthly';

  return {
    name: form.name.trim(),
    slug,
    description: form.description.trim() || null,
    quota_bytes: gbToBytes(form.quota_gb),
    storage_limit_bytes: gbToBytes(form.quota_gb),
    monthly_access_limit_bytes: gbToBytes(form.access_gb || form.quota_gb),
    streaming_limit_bytes: gbToBytes(form.stream_gb || form.access_gb),
    download_limit_bytes: gbToBytes(form.download_gb || form.quota_gb),
    file_view_limit_bytes: gbToBytes(form.file_view_gb || form.access_gb),
    is_shared: Boolean(form.is_shared) && slug !== 'free',
    max_shared_members: Boolean(form.is_shared) && slug !== 'free' ? Number(form.max_shared_members) || 0 : 0,
    display_price_cents: Math.round(price * 100),
    currency: (form.currency || 'USD').toUpperCase(),
    billing_period: billingPeriod,
    is_active: Boolean(form.is_active),
    sort_order: Number(form.sort_order) || 0,
  };
}

function Field({ label, children }) {
  return (
    <label className="block text-xs text-slate-500">
      {label}
      <div className="mt-1">{children}</div>
    </label>
  );
}

function inputClassName() {
  return 'w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';
}

function DetailRow({ label, value }) {
  return (
    <div>
      <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
      <div className="mt-1 whitespace-pre-wrap text-sm text-slate-800">{value || '—'}</div>
    </div>
  );
}

export function StoragePlansPage() {
  const { isAdmin } = useAuth();
  const [plans, setPlans] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [modalError, setModalError] = useState('');
  const [saving, setSaving] = useState(false);
  /** @type {['create'|'edit'|'view'|null, object|null]} */
  const [modal, setModal] = useState({ mode: null, plan: null });
  const [form, setForm] = useState(emptyForm);

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

  function closeModal() {
    setModal({ mode: null, plan: null });
    setForm(emptyForm);
    setModalError('');
  }

  function openCreate() {
    setForm(emptyForm);
    setModalError('');
    setModal({ mode: 'create', plan: null });
  }

  function openEdit(plan) {
    setForm(planToForm(plan));
    setModalError('');
    setModal({ mode: 'edit', plan });
  }

  function openView(plan) {
    setModalError('');
    setModal({ mode: 'view', plan });
  }

  async function savePlan(event) {
    event.preventDefault();
    setSaving(true);
    setModalError('');
    try {
      const payload = formToPayload(form);
      if (!payload.name || !payload.slug || payload.quota_bytes < 1) {
        throw new Error('Name, slug, and data limit are required.');
      }
      if (modal.mode === 'edit' && modal.plan?.uuid) {
        await api(`/admin/storage/plans/${modal.plan.uuid}`, {
          method: 'PATCH',
          body: payload,
        });
      } else {
        await api('/admin/storage/plans', { method: 'POST', body: payload });
      }
      closeModal();
      await loadPlans();
    } catch (err) {
      setModalError(err.message || 'Could not save plan');
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

  const isFormModal = modal.mode === 'create' || modal.mode === 'edit';
  const isCreate = modal.mode === 'create';

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Storage plans</h1>
          <p className="mt-1 text-sm text-slate-500">
            Plan quota is fixed. Billing period is when the plan price is charged (monthly or yearly)
            — storage usage is never reset on billing.
          </p>
        </div>
        <div className="flex flex-wrap gap-3">
          <Link to="/web" className="text-sm text-indigo-600 hover:underline">
            Back to dashboard
          </Link>
          <button
            type="button"
            onClick={openCreate}
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

      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="overflow-x-auto">
          <table className="min-w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-4 py-3">Plan name</th>
                <th className="px-4 py-3">Data limit</th>
                <th className="px-4 py-3">Price</th>
                <th className="px-4 py-3">Billing</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                Array.from({ length: 3 }).map((_, i) => (
                  <tr key={i} className="border-t border-slate-100">
                    <td colSpan={6} className="px-4 py-3">
                      <Shimmer className="h-4 w-full" />
                    </td>
                  </tr>
                ))
              ) : plans.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-slate-500">
                    No plans yet. Create one with <strong>New plan</strong>, or run{' '}
                    <code className="rounded bg-slate-100 px-1">
                      php artisan db:seed --class=StoragePlanSeeder
                    </code>
                    .
                  </td>
                </tr>
              ) : (
                plans.map((plan) => (
                  <tr key={plan.uuid} className="border-t border-slate-100">
                    <td className="px-4 py-3 font-medium text-slate-900">{plan.name}</td>
                    <td className="px-4 py-3 text-slate-700">{formatBytes(plan.quota_bytes)}</td>
                    <td className="px-4 py-3 text-slate-700">
                      {formatPriceCents(plan.display_price_cents, plan.currency)}
                    </td>
                    <td className="px-4 py-3 text-slate-700">
                      {plan.billing_period_label ||
                        (plan.billing_period === 'yearly' || plan.slug === 'free'
                          ? 'Yearly'
                          : 'Monthly')}
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
                          onClick={() => openEdit(plan)}
                          className="rounded-lg border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50"
                        >
                          Edit
                        </button>
                        <button
                          type="button"
                          onClick={() => openView(plan)}
                          className="rounded-lg border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50"
                        >
                          View
                        </button>
                        <button
                          type="button"
                          onClick={() => toggleActive(plan)}
                          className={`rounded-lg border px-2 py-1 text-xs hover:bg-slate-50 ${
                            plan.is_active
                              ? 'border-amber-200 text-amber-800'
                              : 'border-emerald-200 text-emerald-700'
                          }`}
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

      {modal.mode ? (
        <div
          className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-4 sm:items-center"
          onClick={closeModal}
          onKeyDown={(e) => {
            if (e.key === 'Escape') closeModal();
          }}
          role="presentation"
        >
          <div
            className="max-h-[90vh] w-full max-w-xl overflow-auto rounded-2xl bg-white p-5 shadow-xl"
            onClick={(e) => e.stopPropagation()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="plan-modal-title"
          >
            <div className="mb-4 flex items-start justify-between gap-3">
              <h2 id="plan-modal-title" className="text-lg font-semibold text-slate-900">
                {modal.mode === 'create'
                  ? 'New plan'
                  : modal.mode === 'edit'
                    ? 'Edit plan'
                    : 'View plan'}
              </h2>
              <button
                type="button"
                className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50"
                onClick={closeModal}
              >
                Close
              </button>
            </div>

            {modalError ? (
              <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {modalError}
              </div>
            ) : null}

            {isFormModal ? (
              <form onSubmit={savePlan} className="space-y-3">
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label="Plan name">
                    <input
                      required
                      value={form.name}
                      onChange={(e) => {
                        const name = e.target.value;
                        setForm((prev) => ({
                          ...prev,
                          name,
                          slug: isCreate ? slugify(name) : prev.slug,
                        }));
                      }}
                      className={inputClassName()}
                    />
                  </Field>
                  <Field label="Slug">
                    <input
                      required
                      value={form.slug}
                      onChange={(e) =>
                        setForm((prev) => ({ ...prev, slug: slugify(e.target.value) }))
                      }
                      className={inputClassName()}
                    />
                  </Field>
                  <div className="sm:col-span-2">
                    <Field label="Description">
                      <textarea
                        rows={3}
                        value={form.description}
                        onChange={(e) =>
                          setForm((prev) => ({ ...prev, description: e.target.value }))
                        }
                        className={inputClassName()}
                        placeholder="Optional plan details"
                      />
                    </Field>
                  </div>
                  <Field label="Storage (GB)">
                    <input
                      required
                      type="number"
                      min="0.1"
                      step="0.1"
                      value={form.quota_gb}
                      onChange={(e) => setForm((prev) => ({ ...prev, quota_gb: e.target.value }))}
                      className={inputClassName()}
                    />
                  </Field>
                  <Field label="Monthly access (GB)">
                    <input
                      type="number"
                      min="0.1"
                      step="0.1"
                      value={form.access_gb}
                      onChange={(e) => setForm((prev) => ({ ...prev, access_gb: e.target.value }))}
                      className={inputClassName()}
                    />
                  </Field>
                  <Field label="Streaming (GB)">
                    <input
                      type="number"
                      min="0"
                      step="0.1"
                      value={form.stream_gb}
                      onChange={(e) => setForm((prev) => ({ ...prev, stream_gb: e.target.value }))}
                      className={inputClassName()}
                    />
                  </Field>
                  <Field label="Downloads (GB)">
                    <input
                      type="number"
                      min="0"
                      step="0.1"
                      value={form.download_gb}
                      onChange={(e) => setForm((prev) => ({ ...prev, download_gb: e.target.value }))}
                      className={inputClassName()}
                    />
                  </Field>
                  <Field label="File views (GB)">
                    <input
                      type="number"
                      min="0"
                      step="0.1"
                      value={form.file_view_gb}
                      onChange={(e) => setForm((prev) => ({ ...prev, file_view_gb: e.target.value }))}
                      className={inputClassName()}
                    />
                  </Field>
                  <Field label="Price">
                    <input
                      required
                      type="number"
                      min="0"
                      step="0.01"
                      value={form.price}
                      onChange={(e) => setForm((prev) => ({ ...prev, price: e.target.value }))}
                      className={inputClassName()}
                    />
                  </Field>
                  <Field label="Billing period (price charge)">
                    <select
                      value={form.slug === 'free' ? 'yearly' : form.billing_period}
                      disabled={form.slug === 'free'}
                      onChange={(e) =>
                        setForm((prev) => ({ ...prev, billing_period: e.target.value }))
                      }
                      className={inputClassName()}
                    >
                      <option value="monthly">Monthly</option>
                      <option value="yearly">Yearly</option>
                    </select>
                    {form.slug === 'free' ? (
                      <p className="mt-1 text-[11px] text-slate-400">
                        Free plan is billed yearly. Assigned quota stays 5 GB — usage is not reset.
                      </p>
                    ) : (
                      <p className="mt-1 text-[11px] text-slate-400">
                        Price is charged on this interval. Plan quota stays the same; storage usage is
                        not reset on billing.
                      </p>
                    )}
                  </Field>
                  <Field label="Currency">
                    <input
                      value={form.currency}
                      maxLength={3}
                      onChange={(e) => setForm((prev) => ({ ...prev, currency: e.target.value }))}
                      className={`${inputClassName()} uppercase`}
                    />
                  </Field>
                  <Field label="Sort order">
                    <input
                      type="number"
                      min="0"
                      value={form.sort_order}
                      onChange={(e) =>
                        setForm((prev) => ({ ...prev, sort_order: e.target.value }))
                      }
                      className={inputClassName()}
                    />
                  </Field>
                  <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                      type="checkbox"
                      checked={Boolean(form.is_shared) && form.slug !== 'free'}
                      disabled={form.slug === 'free'}
                      onChange={(e) =>
                        setForm((prev) => ({ ...prev, is_shared: e.target.checked }))
                      }
                    />
                    Shared plan
                  </label>
                  <Field label="Max shared members (owner not counted)">
                    <input
                      type="number"
                      min="0"
                      disabled={!form.is_shared || form.slug === 'free'}
                      value={form.max_shared_members}
                      onChange={(e) =>
                        setForm((prev) => ({ ...prev, max_shared_members: e.target.value }))
                      }
                      className={inputClassName()}
                    />
                  </Field>
                  <label className="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                    <input
                      type="checkbox"
                      checked={form.is_active}
                      onChange={(e) =>
                        setForm((prev) => ({ ...prev, is_active: e.target.checked }))
                      }
                    />
                    Active (visible for assignment)
                  </label>
                </div>
                <div className="mt-2 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                  <button
                    type="submit"
                    disabled={saving}
                    className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                  >
                    {saving ? 'Saving…' : isCreate ? 'Create plan' : 'Update plan'}
                  </button>
                  <button
                    type="button"
                    onClick={closeModal}
                    className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
                  >
                    Cancel
                  </button>
                </div>
              </form>
            ) : (
              <div className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                  <DetailRow label="Plan name" value={modal.plan?.name} />
                  <DetailRow label="Slug" value={modal.plan?.slug} />
                  <DetailRow
                    label="Storage"
                    value={formatBytes(modal.plan?.storage_limit_bytes || modal.plan?.quota_bytes)}
                  />
                  <DetailRow
                    label="Monthly access"
                    value={formatBytes(modal.plan?.monthly_access_limit_bytes)}
                  />
                  <DetailRow label="Streaming" value={formatBytes(modal.plan?.streaming_limit_bytes)} />
                  <DetailRow label="Downloads" value={formatBytes(modal.plan?.download_limit_bytes)} />
                  <DetailRow
                    label="File views"
                    value={formatBytes(modal.plan?.file_view_limit_bytes)}
                  />
                  <DetailRow
                    label="Seats"
                    value={
                      modal.plan?.is_shared
                        ? `Shared · ${modal.plan?.max_shared_members ?? 0} members + owner`
                        : 'Owner only'
                    }
                  />
                  <DetailRow
                    label="Price"
                    value={formatPriceCents(
                      modal.plan?.display_price_cents,
                      modal.plan?.currency,
                    )}
                  />
                  <DetailRow
                    label="Billing period (price charge)"
                    value={
                      modal.plan?.billing_period_label ||
                      (modal.plan?.billing_period === 'yearly' || modal.plan?.slug === 'free'
                        ? 'Yearly'
                        : 'Monthly')
                    }
                  />
                  <DetailRow
                    label="Quota note"
                    value="Assigned data limit stays fixed for this plan. Billing renews price only — storage usage is not reset."
                  />
                  <DetailRow label="Currency" value={modal.plan?.currency} />
                  <DetailRow label="Sort order" value={String(modal.plan?.sort_order ?? '')} />
                  <DetailRow
                    label="Status"
                    value={modal.plan?.is_active ? 'Active' : 'Inactive'}
                  />
                </div>
                <DetailRow label="Description" value={modal.plan?.description} />
                <div className="flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                  <button
                    type="button"
                    onClick={() => openEdit(modal.plan)}
                    className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                  >
                    Edit
                  </button>
                  <button
                    type="button"
                    onClick={closeModal}
                    className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
                  >
                    Close
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      ) : null}
    </div>
  );
}
