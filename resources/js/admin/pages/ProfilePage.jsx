import { useAuth } from '../auth';
import { ProfileShimmer } from '../shimmer';

export function ProfilePage() {
  const { user, isAdmin, booting } = useAuth();

  if (booting || !user) {
    return <ProfileShimmer />;
  }

  return (
    <div className="mx-auto max-w-2xl">
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-slate-900">Profile</h1>
        <p className="mt-1 text-sm text-slate-500">
          Your account details for the web console.
        </p>
      </div>

      <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <div className="mb-6 flex items-center gap-4">
          <div className="flex h-14 w-14 items-center justify-center rounded-full bg-[#2f3f8f] text-xl font-semibold text-white">
            {(user?.display_name || '?').charAt(0).toUpperCase()}
          </div>
          <div>
            <div className="text-lg font-semibold text-slate-900">{user?.display_name}</div>
            <div className="text-sm text-slate-500">{user?.phone}</div>
          </div>
        </div>

        <dl className="grid gap-4 sm:grid-cols-2">
          <div className="rounded-xl bg-slate-50 px-4 py-3">
            <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">
              Display name
            </dt>
            <dd className="mt-1 text-sm font-medium text-slate-900">{user?.display_name || '—'}</dd>
          </div>
          <div className="rounded-xl bg-slate-50 px-4 py-3">
            <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Phone</dt>
            <dd className="mt-1 text-sm font-medium text-slate-900">{user?.phone || '—'}</dd>
          </div>
          <div className="rounded-xl bg-slate-50 px-4 py-3">
            <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Roles</dt>
            <dd className="mt-1 text-sm font-medium text-slate-900">
              {(user?.roles ?? []).join(', ') || 'user'}
            </dd>
          </div>
          <div className="rounded-xl bg-slate-50 px-4 py-3">
            <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Console</dt>
            <dd className="mt-1 text-sm font-medium text-slate-900">
              {isAdmin ? 'Admin dashboard' : 'User home'}
            </dd>
          </div>
        </dl>
      </div>
    </div>
  );
}
