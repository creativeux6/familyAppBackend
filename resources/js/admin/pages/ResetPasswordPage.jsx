import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../api';
import { AuthLayout, Button, ErrorBox, Field, Input } from '../ui';

export function ResetPasswordPage() {
  const navigate = useNavigate();
  const [phone, setPhone] = useState('+92300');
  const [token, setToken] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function onSubmit(e) {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      await api('/auth/reset-password', {
        method: 'POST',
        body: {
          phone,
          token,
          password,
          password_confirmation: passwordConfirmation,
        },
      });
      navigate('/web/login');
    } catch (err) {
      setError(err.message || 'Reset failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthLayout title="Reset password" subtitle="Enter the token sent for your phone number">
      <form onSubmit={onSubmit}>
        <ErrorBox message={error} />
        <Field label="Phone">
          <Input value={phone} onChange={(e) => setPhone(e.target.value)} required />
        </Field>
        <Field label="Reset token">
          <Input value={token} onChange={(e) => setToken(e.target.value)} required />
        </Field>
        <Field label="New password">
          <Input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            minLength={8}
          />
        </Field>
        <Field label="Confirm password">
          <Input
            type="password"
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
            required
            minLength={8}
          />
        </Field>
        <Button type="submit" disabled={busy}>
          {busy ? 'Saving…' : 'Reset password'}
        </Button>
        <p className="mt-4 text-center text-sm">
          <Link className="text-indigo-600 hover:underline" to="/web/login">
            Back to sign in
          </Link>
        </p>
      </form>
    </AuthLayout>
  );
}
