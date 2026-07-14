import { useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth';
import { AuthLayout, Button, ErrorBox, Field, Input } from '../ui';
import { isAdminRole } from '../api';

export function LoginPage() {
  const { user, login } = useAuth();
  const navigate = useNavigate();
  const [phone, setPhone] = useState('+92300');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  if (user) {
    return <Navigate to="/web" replace />;
  }

  async function onSubmit(e) {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      const next = await login(phone, password);
      navigate(isAdminRole(next) ? '/web' : '/web');
    } catch (err) {
      setError(err.message || 'Login failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthLayout title="Sign in" subtitle="Use your phone number and password">
      <form onSubmit={onSubmit}>
        <ErrorBox message={error} />
        <Field label="Phone">
          <Input value={phone} onChange={(e) => setPhone(e.target.value)} autoComplete="tel" required />
        </Field>
        <Field label="Password">
          <Input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password"
            required
          />
        </Field>
        <Button type="submit" disabled={busy}>
          {busy ? 'Signing in…' : 'Sign in'}
        </Button>
        <div className="mt-4 flex justify-between text-sm">
          <Link className="text-indigo-600 hover:underline" to="/web/register">
            Create account
          </Link>
          <Link className="text-indigo-600 hover:underline" to="/web/forgot-password">
            Forgot password?
          </Link>
        </div>
      </form>
    </AuthLayout>
  );
}
