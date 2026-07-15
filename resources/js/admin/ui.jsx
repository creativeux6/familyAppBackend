import { useEffect, useRef, useState } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';

export function AuthLayout({ title, subtitle, children }) {
  return (
    <div className="min-h-screen bg-gradient-to-br from-indigo-50 via-slate-50 to-white flex items-center justify-center p-4">
      <div className="w-full max-w-md">
        <div className="mb-8 text-center">
          <div className="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-600 text-white text-lg font-semibold shadow-lg shadow-indigo-200">
            FA
          </div>
          <h1 className="mt-4 text-2xl font-semibold tracking-tight text-slate-900">{title}</h1>
          {subtitle ? <p className="mt-2 text-sm text-slate-500">{subtitle}</p> : null}
        </div>
        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">{children}</div>
      </div>
    </div>
  );
}

export function Field({ label, children }) {
  return (
    <label className="block mb-4">
      <span className="mb-1.5 block text-sm font-medium text-slate-700">{label}</span>
      {children}
    </label>
  );
}

export function Input(props) {
  return (
    <input
      {...props}
      className={`w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-100 ${props.className ?? ''}`}
    />
  );
}

export function Button({ children, variant = 'primary', className = '', ...props }) {
  const styles =
    variant === 'primary'
      ? 'bg-indigo-600 text-white hover:bg-indigo-700'
      : variant === 'danger'
        ? 'bg-red-600 text-white hover:bg-red-700'
        : 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50';

  return (
    <button
      {...props}
      className={`inline-flex w-full items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold transition disabled:opacity-50 ${styles} ${className}`}
    >
      {children}
    </button>
  );
}

export function ErrorBox({ message }) {
  if (!message) return null;
  return (
    <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
      {message}
    </div>
  );
}

function Icon({ children, className = 'h-5 w-5 shrink-0' }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden
    >
      {children}
    </svg>
  );
}

function IconDashboard() {
  return (
    <Icon>
      <rect x="3" y="3" width="7" height="9" rx="1.5" />
      <rect x="14" y="3" width="7" height="5" rx="1.5" />
      <rect x="14" y="12" width="7" height="9" rx="1.5" />
      <rect x="3" y="16" width="7" height="5" rx="1.5" />
    </Icon>
  );
}

function IconHome() {
  return (
    <Icon>
      <path d="M3 10.5 12 3l9 7.5" />
      <path d="M5.5 9.5V20a1 1 0 0 0 1 1H10v-6h4v6h3.5a1 1 0 0 0 1-1V9.5" />
    </Icon>
  );
}

function IconLogs() {
  return (
    <Icon>
      <path d="M8 6h12" />
      <path d="M8 12h12" />
      <path d="M8 18h12" />
      <circle cx="4" cy="6" r="1.2" fill="currentColor" stroke="none" />
      <circle cx="4" cy="12" r="1.2" fill="currentColor" stroke="none" />
      <circle cx="4" cy="18" r="1.2" fill="currentColor" stroke="none" />
    </Icon>
  );
}

function IconUsers() {
  return (
    <Icon>
      <circle cx="9" cy="8" r="3.5" />
      <path d="M2.5 19.5a6.5 6.5 0 0 1 13 0" />
      <circle cx="17" cy="9" r="2.5" />
      <path d="M16 19.5a5 5 0 0 1 5.5-4.7" />
    </Icon>
  );
}

function IconStorage() {
  return (
    <Icon>
      <ellipse cx="12" cy="6" rx="8" ry="3" />
      <path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6" />
      <path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" />
    </Icon>
  );
}

function IconGallery() {
  return (
    <Icon>
      <rect x="3.5" y="4.5" width="17" height="15" rx="2" />
      <circle cx="9" cy="10" r="1.8" />
      <path d="m7.5 17.5 3.2-3.5 2.3 2.4 2.8-3.6 3.7 4.7" />
    </Icon>
  );
}

function IconCalendar() {
  return (
    <Icon>
      <rect x="3.5" y="5" width="17" height="15.5" rx="2" />
      <path d="M8 3.5v3" />
      <path d="M16 3.5v3" />
      <path d="M3.5 10h17" />
    </Icon>
  );
}

function IconConnections() {
  return (
    <Icon>
      <circle cx="7" cy="7" r="2.5" />
      <circle cx="17" cy="7" r="2.5" />
      <circle cx="12" cy="17" r="2.5" />
      <path d="M9.2 8.5 10.8 15" />
      <path d="M14.8 8.5 13.2 15" />
    </Icon>
  );
}

function IconGroups() {
  return (
    <Icon>
      <path d="M21 15a2 2 0 0 1-2 2H8l-5 3V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
    </Icon>
  );
}

function IconProfile() {
  return (
    <Icon className="h-4 w-4 shrink-0">
      <circle cx="12" cy="8" r="3.5" />
      <path d="M5 19.5a7 7 0 0 1 14 0" />
    </Icon>
  );
}

function IconLogout() {
  return (
    <Icon className="h-4 w-4 shrink-0">
      <path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4" />
      <path d="M16 12H9" />
      <path d="m14 8 4 4-4 4" />
    </Icon>
  );
}

function SidebarItem({ icon, label, disabled = false }) {
  return (
    <div
      className={[
        'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm',
        disabled ? 'text-indigo-100/70' : '',
      ].join(' ')}
    >
      {icon}
      <span>{label}</span>
    </div>
  );
}

function navClass({ isActive }) {
  return [
    'flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
    isActive
      ? 'bg-white/15 text-white shadow-sm ring-1 ring-white/20'
      : 'text-indigo-100/80 hover:bg-white/10 hover:text-white',
  ].join(' ');
}

function SidebarNav({ isAdmin, onNavigate }) {
  return (
    <nav className="flex flex-col gap-1 p-3">
      <div className="mb-2 px-3 text-[11px] font-semibold uppercase tracking-wider text-indigo-200/60">
        Main
      </div>
      <NavLink to="/web" end className={navClass} onClick={onNavigate}>
        {isAdmin ? <IconDashboard /> : <IconHome />}
        {isAdmin ? 'Dashboard' : 'Home'}
      </NavLink>
      {isAdmin ? (
        <>
          <div className="mb-2 mt-4 px-3 text-[11px] font-semibold uppercase tracking-wider text-indigo-200/60">
            Operations
          </div>
          <NavLink to="/web/logs" className={navClass} onClick={onNavigate}>
            <IconLogs />
            System logs
          </NavLink>
          <NavLink to="/web/users" className={navClass} onClick={onNavigate}>
            <IconUsers />
            Users
          </NavLink>
          <NavLink to="/web/plans" className={navClass} onClick={onNavigate}>
            <IconStorage />
            Storage plans
          </NavLink>
        </>
      ) : (
        <div className="pointer-events-none mt-4 space-y-1 opacity-45">
          <div className="px-3 text-[11px] font-semibold uppercase tracking-wider text-indigo-200/60">
            Coming in Phase 2
          </div>
          <SidebarItem icon={<IconGallery />} label="Gallery" disabled />
          <SidebarItem icon={<IconCalendar />} label="Calendar" disabled />
          <SidebarItem icon={<IconConnections />} label="Connections" disabled />
          <SidebarItem icon={<IconGroups />} label="Groups" disabled />
        </div>
      )}
    </nav>
  );
}

function UserMenu({ user, onLogout }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const navigate = useNavigate();

  useEffect(() => {
    if (!open) return undefined;

    const onPointer = (e) => {
      if (ref.current && !ref.current.contains(e.target)) {
        setOpen(false);
      }
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setOpen(false);
    };

    document.addEventListener('mousedown', onPointer);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onPointer);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
        className="flex cursor-pointer items-center gap-2 rounded-full border border-white/15 bg-white/10 py-1 pl-1 pr-2 text-left transition hover:bg-white/15 sm:pr-3"
      >
        <span className="flex h-9 w-9 items-center justify-center rounded-full bg-amber-300 text-sm font-semibold text-indigo-950">
          {(user?.display_name || '?').charAt(0).toUpperCase()}
        </span>
        <span className="hidden min-w-0 sm:block">
          <span className="block truncate text-sm font-medium text-white">
            {user?.display_name}
          </span>
          <span className="block truncate text-[11px] text-indigo-100/70">
            {(user?.roles ?? []).join(' · ') || 'user'}
          </span>
        </span>
        <span className="hidden text-indigo-100/80 sm:inline" aria-hidden>
          ▾
        </span>
      </button>

      {open ? (
        <div
          role="menu"
          className="absolute right-0 z-50 mt-2 w-56 overflow-hidden rounded-2xl border border-slate-200 bg-white py-1 shadow-xl shadow-slate-900/15"
        >
          <div className="border-b border-slate-100 px-4 py-3">
            <div className="truncate text-sm font-semibold text-slate-900">{user?.display_name}</div>
            <div className="truncate text-xs text-slate-500">{user?.phone}</div>
          </div>
          <button
            type="button"
            role="menuitem"
            className="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-slate-700 hover:bg-slate-50"
            onClick={() => {
              setOpen(false);
              navigate('/web/profile');
            }}
          >
            <IconProfile />
            Profile
          </button>
          <button
            type="button"
            role="menuitem"
            className="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-red-600 hover:bg-red-50"
            onClick={async () => {
              setOpen(false);
              await onLogout();
            }}
          >
            <IconLogout />
            Log out
          </button>
        </div>
      ) : null}
    </div>
  );
}

/** @deprecated use AppShell */
export function PanelShell(props) {
  return <AppShell {...props} />;
}

export function AppShell({ user, isAdmin, onLogout, children }) {
  const [mobileOpen, setMobileOpen] = useState(false);
  const location = useLocation();

  useEffect(() => {
    setMobileOpen(false);
  }, [location.pathname]);

  useEffect(() => {
    if (!mobileOpen) return undefined;
    const onKey = (e) => {
      if (e.key === 'Escape') setMobileOpen(false);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [mobileOpen]);

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      {mobileOpen ? (
        <button
          type="button"
          aria-label="Close menu"
          className="fixed inset-0 z-40 bg-slate-950/55 lg:hidden"
          onClick={() => setMobileOpen(false)}
        />
      ) : null}

      <aside
        className={[
          'fixed inset-y-0 left-0 z-50 flex w-72 flex-col bg-[#1e2a5a] text-white transition-transform duration-200 lg:translate-x-0',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
        ].join(' ')}
      >
        <div className="flex h-16 items-center gap-3 border-b border-white/10 px-5">
          <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-300 text-sm font-bold text-indigo-950">
            FA
          </div>
          <div>
            <div className="text-sm font-semibold text-white">Family App</div>
            <div className="text-xs text-indigo-200/70">
              {isAdmin ? 'Admin console' : 'Web home'}
            </div>
          </div>
        </div>
        <div className="flex-1 overflow-y-auto">
          <SidebarNav isAdmin={isAdmin} onNavigate={() => setMobileOpen(false)} />
        </div>
        <div className="border-t border-white/10 p-4 text-xs text-indigo-200/60">
          Signed in as
          <div className="mt-1 truncate font-medium text-white">{user?.display_name}</div>
        </div>
      </aside>

      <div className="flex min-h-screen flex-col lg:pl-72">
        <header className="sticky top-0 z-30 border-b border-indigo-950/40 bg-[#2f3f8f] text-white shadow-sm shadow-indigo-950/20">
          <div className="flex h-16 items-center justify-between gap-3 px-4 sm:px-6">
            <div className="flex items-center gap-3">
              <button
                type="button"
                className="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-white/15 bg-white/10 text-white lg:hidden"
                onClick={() => setMobileOpen(true)}
                aria-label="Open menu"
              >
                ☰
              </button>
              <div>
                <div className="text-sm font-semibold text-white sm:text-base">
                  {isAdmin ? 'Administration' : 'My home'}
                </div>
                <div className="hidden text-xs text-indigo-100/70 sm:block">
                  {(user?.roles ?? []).join(' · ') || 'user'}
                </div>
              </div>
            </div>

            <UserMenu user={user} onLogout={onLogout} />
          </div>
        </header>

        <main className="flex-1 px-4 py-6 sm:px-6 lg:px-8">{children}</main>

        <footer className="border-t border-slate-200 bg-white px-4 py-4 sm:px-6">
          <div className="flex flex-col gap-2 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <span>© {new Date().getFullYear()} Family App</span>
            <span className="flex flex-wrap gap-3">
              <Link to="/web" className="hover:text-indigo-600">
                Home
              </Link>
              {isAdmin ? (
                <>
                  <Link to="/web/logs" className="hover:text-indigo-600">
                    Logs
                  </Link>
                  <Link to="/web/users" className="hover:text-indigo-600">
                    Users
                  </Link>
                  <Link to="/web/plans" className="hover:text-indigo-600">
                    Plans
                  </Link>
                </>
              ) : null}
              <Link to="/web/profile" className="hover:text-indigo-600">
                Profile
              </Link>
              <span className="text-slate-300">|</span>
              <span>Web console</span>
            </span>
          </div>
        </footer>
      </div>
    </div>
  );
}
