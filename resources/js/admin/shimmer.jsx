export function Shimmer({ className = '' }) {
  return <div className={`shimmer rounded-lg ${className}`} aria-hidden />;
}

export function DashboardShimmer() {
  return (
    <div>
      <div className="mb-6 space-y-2">
        <Shimmer className="h-8 w-48" />
        <Shimmer className="h-4 w-72 max-w-full" />
      </div>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {Array.from({ length: 6 }).map((_, index) => (
          <div key={index} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <Shimmer className="h-4 w-24" />
            <Shimmer className="mt-4 h-9 w-20" />
          </div>
        ))}
      </div>
    </div>
  );
}

export function HomeShimmer() {
  return (
    <div>
      <div className="mb-8 space-y-2">
        <Shimmer className="h-8 w-56" />
        <Shimmer className="h-4 w-80 max-w-full" />
      </div>
      <div className="mb-6 rounded-2xl border border-slate-200 bg-white p-4">
        <Shimmer className="mb-3 h-4 w-20" />
        <Shimmer className="mb-2 h-4 w-full" />
        <Shimmer className="h-4 w-3/4 max-w-md" />
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        {Array.from({ length: 4 }).map((_, index) => (
          <div key={index} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <Shimmer className="h-4 w-32" />
            <Shimmer className="mt-3 h-4 w-full" />
            <Shimmer className="mt-4 h-8 w-28" />
          </div>
        ))}
      </div>
    </div>
  );
}

export function LogsShimmer() {
  return (
    <div>
      <div className="mb-6 space-y-2">
        <Shimmer className="h-8 w-40" />
        <Shimmer className="h-4 w-72 max-w-full" />
      </div>
      <div className="mb-6 rounded-2xl border border-slate-200 bg-white p-5">
        <div className="mb-4 flex items-center gap-3">
          <Shimmer className="h-5 w-40" />
          <Shimmer className="h-5 w-16 rounded-full" />
        </div>
        <div className="space-y-3">
          {Array.from({ length: 5 }).map((_, index) => (
            <div key={index} className="flex gap-3">
              <Shimmer className="h-10 flex-1" />
              <Shimmer className="h-10 w-16" />
              <Shimmer className="hidden h-10 w-24 sm:block" />
            </div>
          ))}
        </div>
      </div>
      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white p-4">
        {Array.from({ length: 6 }).map((_, index) => (
          <div key={index} className="flex gap-3 border-b border-slate-100 py-3 last:border-0">
            <Shimmer className="h-4 w-28" />
            <Shimmer className="h-4 w-24" />
            <Shimmer className="h-4 flex-1" />
          </div>
        ))}
      </div>
    </div>
  );
}

export function ProfileShimmer() {
  return (
    <div className="mx-auto max-w-2xl">
      <div className="mb-6 space-y-2">
        <Shimmer className="h-8 w-28" />
        <Shimmer className="h-4 w-64 max-w-full" />
      </div>
      <div className="rounded-2xl border border-slate-200 bg-white p-6">
        <div className="mb-6 flex items-center gap-4">
          <Shimmer className="h-14 w-14 rounded-full" />
          <div className="space-y-2">
            <Shimmer className="h-5 w-40" />
            <Shimmer className="h-4 w-32" />
          </div>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          {Array.from({ length: 4 }).map((_, index) => (
            <div key={index} className="rounded-xl bg-slate-50 px-4 py-3">
              <Shimmer className="h-3 w-20" />
              <Shimmer className="mt-2 h-4 w-28" />
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

export function ContentShimmer({ variant = 'dashboard' }) {
  if (variant === 'home') return <HomeShimmer />;
  if (variant === 'logs') return <LogsShimmer />;
  if (variant === 'profile') return <ProfileShimmer />;
  return <DashboardShimmer />;
}
