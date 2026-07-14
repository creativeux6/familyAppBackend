import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api';
import { AuthLayout, Button, ErrorBox, Field, Input } from '../ui';

export function ForgotPasswordPage() {
  const [phone, setPhone] = useState('+92300');
  const [message, setMessage] = useState('');
  const [resetToken, setResetToken] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function onSubmit(e) {
    e.preventDefault();
    setBusy(true);
    setError('');
    setMessage('');
    setResetToken('');
    try {
      const data = await api('/auth/forgot-password', {
        method: 'POST',
        body: { phone },
      });
      setMessage(data.message || 'Check your messages for a reset token.');
      if (data.reset_token) {
        setResetToken(data.reset_token);
      }
    } catch (err) {
      setError(err.message || 'Request failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthLayout title="Forgot password" subtitle="We will issue a reset token for your phone">
      <form onSubmit={onSubmit}>
        <ErrorBox message={error} />
        {message ? (
          <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
            {message}
            {resetToken ? (
              <div className="mt-2 font-mono text-xs">
                Dev token: <strong>{resetToken}</strong>
              </div>
            ) : null}
          </div>
        ) : null}
        <Field label="Phone">
          <Input value={phone} onChange={(e) => setPhone(e.target.value)} required />
        </Field>
        <Button type="submit" disabled={busy}>
          {busy ? 'Sending…' : 'Send reset token'}
        </Button>
        <div className="mt-4 flex justify-between text-sm">
          <Link className="text-indigo-600 hover:underline" to="/web/login">
            Back to sign in
          </Link>
          <Link className="text-indigo-600 hover:underline" to="/web/reset-password">
            I have a token
          </Link>
        </div>
      </form>
    </AuthLayout>
  );
}
