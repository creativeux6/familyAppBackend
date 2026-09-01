<x-legal-layout title="Delete account" :app-name="$appName" :app-url="$appUrl">
    <h1>Delete your {{ $appName }} account</h1>
    <p>Google Play requires a public way to delete an account. You can delete from the {{ $appName }} app (Profile → Delete account) or with the form below.</p>

    <div class="card">
        @if (session('status'))
            <p class="ok">{{ session('status') }}</p>
        @endif

        <p>This permanently removes your login, personal media, avatars, and devices. Family-tree people you added for relatives stay as unlinked stubs so other family members keep their tree.</p>

        <form method="post" action="{{ route('legal.account-deletion.submit') }}">
            @csrf
            <label for="phone">Phone number</label>
            <input id="phone" name="phone" type="tel" autocomplete="username" value="{{ old('phone') }}" required>
            @error('phone')<div class="error">{{ $message }}</div>@enderror

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            @error('password')<div class="error">{{ $message }}</div>@enderror

            <label for="confirmation">Type DELETE to confirm</label>
            <input id="confirmation" name="confirmation" type="text" value="{{ old('confirmation') }}" required>
            @error('confirmation')<div class="error">{{ $message }}</div>@enderror

            <button type="submit">Delete my account</button>
        </form>
    </div>

    <p>Read the <a href="{{ route('legal.privacy') }}">Privacy Policy</a> for what we store.</p>
</x-legal-layout>
