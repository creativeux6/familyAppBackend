import { useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth';
import { AuthLayout, Button, ErrorBox, Field, Input } from '../ui';

export function RegisterPage() {
  const { user, register } = useAuth();
  const navigate = useNavigate();
  const [displayName, setDisplayName] = useState('');
  const [phone, setPhone] = useState('+92300');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
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
      await register({
        display_name: displayName,
        phone,
        password,
        password_confirmation: passwordConfirmation,
      });
      navigate('/web');
    } catch (err) {
      setError(err.message || 'Registration failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthLayout title="Create account" subtitle="New accounts are always registered as regular users">
      <form onSubmit={onSubmit}>
        <ErrorBox message={error} />
        <Field label="Display name">
          <Input value={displayName} onChange={(e) => setDisplayName(e.target.value)} required />
        </Field>
        <Field label="Phone">
          <Input value={phone} onChange={(e) => setPhone(e.target.value)} autoComplete="tel" required />
        </Field>
        <Field label="Password">
          <Input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="new-password"
            required
            minLength={8}
          />
        </Field>
        <Field label="Confirm password">
          <Input
            type="password"
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
            autoComplete="new-password"
            required
            minLength={8}
          />
        </Field>
        <Button type="submit" disabled={busy}>
          {busy ? 'Creating…' : 'Register'}
        </Button>
        <p className="mt-4 text-center text-sm text-slate-500">
          Already have an account?{' '}
          <Link className="text-indigo-600 hover:underline" to="/web/login">
            Sign in
          </Link>
        </p>
      </form>
    </AuthLayout>
  );
}
