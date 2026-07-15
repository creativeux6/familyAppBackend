import { BrowserRouter, Navigate, Outlet, Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './auth';
import { getCachedUser, getToken, isAdminRole } from './api';
import { AppShell } from './ui';
import { ContentShimmer } from './shimmer';
import { LoginPage } from './pages/LoginPage';
import { RegisterPage } from './pages/RegisterPage';
import { ForgotPasswordPage } from './pages/ForgotPasswordPage';
import { ResetPasswordPage } from './pages/ResetPasswordPage';
import { AdminDashboardPage } from './pages/AdminDashboardPage';
import { UserHomePage } from './pages/UserHomePage';
import { LogsPage } from './pages/LogsPage';
import { ProfilePage } from './pages/ProfilePage';
import { UsersPage } from './pages/UsersPage';
import { StoragePlansPage } from './pages/StoragePlansPage';

function GuestOnly({ children }) {
  const { user, booting } = useAuth();

  if (booting) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-indigo-50 via-slate-50 to-white p-4">
        <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <div className="shimmer mb-4 h-8 w-40 rounded-lg" />
          <div className="shimmer mb-3 h-10 w-full rounded-xl" />
          <div className="shimmer mb-3 h-10 w-full rounded-xl" />
          <div className="shimmer h-10 w-full rounded-xl" />
        </div>
      </div>
    );
  }

  if (user) {
    return <Navigate to="/web" replace />;
  }

  return children;
}

function RequireAuth() {
  const { user, booting, isAdmin, logout } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  const shellUser = user || getCachedUser() || {
    display_name: 'Loading',
    phone: '',
    roles: [],
  };
  const shellIsAdmin = user ? isAdmin : isAdminRole(shellUser);
  const hasSession = Boolean(getToken() || user);

  if (!booting && !user) {
    return <Navigate to="/web/login" replace />;
  }

  if (!hasSession) {
    return <Navigate to="/web/login" replace />;
  }

  const shimmerVariant = location.pathname.includes('/logs')
    ? 'logs'
    : location.pathname.includes('/profile')
      ? 'profile'
      : location.pathname.includes('/users') || location.pathname.includes('/plans')
        ? 'dashboard'
        : shellIsAdmin
          ? 'dashboard'
          : 'home';

  return (
    <AppShell
      user={shellUser}
      isAdmin={shellIsAdmin}
      onLogout={async () => {
        await logout();
        navigate('/web/login');
      }}
    >
      {booting ? <ContentShimmer variant={shimmerVariant} /> : <Outlet />}
    </AppShell>
  );
}

function HomeRoute() {
  const { isAdmin } = useAuth();
  return isAdmin ? <AdminDashboardPage /> : <UserHomePage />;
}

function AppRoutes() {
  return (
    <Routes>
      <Route
        path="/web/login"
        element={
          <GuestOnly>
            <LoginPage />
          </GuestOnly>
        }
      />
      <Route
        path="/web/register"
        element={
          <GuestOnly>
            <RegisterPage />
          </GuestOnly>
        }
      />
      <Route
        path="/web/forgot-password"
        element={
          <GuestOnly>
            <ForgotPasswordPage />
          </GuestOnly>
        }
      />
      <Route
        path="/web/reset-password"
        element={
          <GuestOnly>
            <ResetPasswordPage />
          </GuestOnly>
        }
      />
      <Route path="/web" element={<RequireAuth />}>
        <Route index element={<HomeRoute />} />
        <Route path="logs" element={<LogsPage />} />
        <Route path="users" element={<UsersPage />} />
        <Route path="plans" element={<StoragePlansPage />} />
        <Route path="profile" element={<ProfilePage />} />
      </Route>
      <Route path="*" element={<Navigate to="/web" replace />} />
    </Routes>
  );
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>
    </BrowserRouter>
  );
}
