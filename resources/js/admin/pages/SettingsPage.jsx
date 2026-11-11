import { useEffect, useState } from 'react';
import { api } from '../api';
import { Button, ErrorBox, Field, Input } from '../ui';

export function SettingsPage() {
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const data = await api('/admin/settings');
        if (!cancelled) {
          setEmail(data.support_inbox_email || '');
        }
      } catch (err) {
        if (!cancelled) setError(err.message || 'Could not load settings');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  async function onSave(e) {
    e.preventDefault();
    setSaving(true);
    setError('');
    setNotice('');
    try {
      const data = await api('/admin/settings', {
        method: 'PATCH',
        body: { support_inbox_email: email.trim() },
      });
      setEmail(data.support_inbox_email || email.trim());
      setNotice('Support inbox updated.');
    } catch (err) {
      setError(err.message || 'Could not save settings');
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <div className="shimmer h-40 w-full max-w-lg rounded-2xl" />;
  }

  return (
    <div className="max-w-lg">
      <h1 className="text-2xl font-semibold text-slate-900">Settings</h1>
      <p className="mt-1 text-sm text-slate-500">
        Support inbox receives password-recovery help when a user has no email on file.
      </p>

      <form onSubmit={onSave} className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <ErrorBox message={error} />
        {notice ? (
          <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
            {notice}
          </div>
        ) : null}
        <Field label="Support inbox email">
          <Input
            type="email"
            value={email}
            onChange={(ev) => setEmail(ev.target.value)}
            required
            placeholder="support@example.com"
          />
        </Field>
        <Button type="submit" disabled={saving}>
          {saving ? 'Saving…' : 'Save'}
        </Button>
      </form>
    </div>
  );
}
