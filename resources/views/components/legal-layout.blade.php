@props(['title', 'appName', 'appUrl'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ $appName }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: system-ui, sans-serif; line-height: 1.55; margin: 0; background: #f7f4ef; color: #1b1b18; }
        main { max-width: 42rem; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }
        h1 { font-size: 1.75rem; margin: 0 0 0.75rem; }
        h2 { font-size: 1.15rem; margin: 1.75rem 0 0.5rem; }
        p, li { color: #3f3d38; }
        a { color: #0f6d56; }
        .card { background: #fff; border: 1px solid #e4dfd4; border-radius: 12px; padding: 1.25rem; }
        label { display: block; font-weight: 600; margin: 0.85rem 0 0.3rem; }
        input { width: 100%; box-sizing: border-box; padding: 0.65rem 0.75rem; border: 1px solid #cfc8ba; border-radius: 8px; font: inherit; }
        button { margin-top: 1.1rem; background: #0f6d56; color: #fff; border: 0; border-radius: 8px; padding: 0.7rem 1rem; font: inherit; font-weight: 700; cursor: pointer; }
        .error { color: #a12622; font-size: 0.92rem; }
        .ok { color: #0f6d56; font-weight: 600; }
        nav { margin-bottom: 1.5rem; font-size: 0.92rem; }
    </style>
</head>
<body>
<main>
    <nav>
        <a href="{{ $appUrl }}">{{ $appName }}</a>
        · <a href="{{ route('legal.privacy') }}">Privacy</a>
        · <a href="{{ route('legal.account-deletion') }}">Delete account</a>
    </nav>
    {{ $slot }}
</main>
</body>
</html>
