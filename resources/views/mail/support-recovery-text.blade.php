A user requested password recovery help but has no email on their account.

Phone: {{ $phone }}
Time: {{ now()->toIso8601String() }}
@if ($note)
Note from user: {{ $note }}
@endif

Please add an email on this user in the admin panel so they can retry forgot password.
