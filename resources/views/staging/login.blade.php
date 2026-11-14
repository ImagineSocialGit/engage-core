<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Staging access</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        *, *::before, *::after { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; background: #f8fafc; color: #0f172a; display: grid; place-items: center; padding: 1.5rem; }
        main { width: 100%; max-width: 26rem; background: white; padding: 2rem; border: 1px solid #e2e8f0; border-radius: 1rem; box-shadow: 0 18px 50px rgba(15, 23, 42, .06); }
        h1 { font-size: 1.5rem; margin: 0 0 .5rem; }
        p { color: #475569; margin: 0 0 1.5rem; line-height: 1.5; }
        label { display: block; font-weight: 600; margin-bottom: .35rem; }
        input { display: block; width: 100%; padding: .7rem .8rem; border: 1px solid #94a3b8; border-radius: .5rem; font: inherit; margin-bottom: 1rem; }
        input:focus-visible, button:focus-visible { outline: 3px solid #2563eb; outline-offset: 2px; }
        button { width: 100%; padding: .8rem; border: 0; border-radius: .5rem; background: #1e293b; color: white; font: inherit; font-weight: 600; cursor: pointer; }
        button:hover { background: #334155; }
        .error { color: #991b1b; background: #fef2f2; padding: .75rem; border-radius: .5rem; margin: 0 0 1rem; }
    </style>
</head>
<body>
    <main>
        <h1>Staging access</h1>
        @if($isAuthenticated)
            <p>You have access to this preview. Sign out to end this session.</p>
            <form action="/staging-logout" method="post">
                @csrf
                <button type="submit">Sign out</button>
            </form>
        @else
        <p>This preview is restricted. Sign in to continue.</p>
        @error('login')
            <div class="error" role="alert">{{ $message }}</div>
        @enderror
        <form action="/staging-login" method="post">
            @csrf
            <label for="staging-user">Username</label>
            <input id="staging-user" name="user" type="text" autocomplete="username" required autofocus>
            <label for="staging-password">Password</label>
            <input id="staging-password" name="password" type="password" autocomplete="current-password" required>
            <button type="submit">Continue</button>
        </form>
        @endif
    </main>
</body>
</html>