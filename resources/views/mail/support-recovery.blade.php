<p>A user requested password recovery help but has no email on their account.</p>
<p>Phone: <strong>{{ $phone }}</strong></p>
<p>Time: {{ now()->toIso8601String() }}</p>
@if ($note)
<p>Note from user: {{ $note }}</p>
@endif
<p>Please add an email on this user in the admin panel so they can retry forgot password.</p>
