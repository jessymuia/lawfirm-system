<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="color-scheme" content="light">
    <title>{{ config('app.name') }} · Platform console</title>
    <link rel="stylesheet" href="/lms-loader.css?v={{ filemtime(public_path('lms-loader.css')) }}">
    <link rel="stylesheet" href="/platform-console.css?v={{ filemtime(public_path('platform-console.css')) }}">
    <script src="/platform-qr.js?v={{ filemtime(public_path('platform-qr.js')) }}" defer></script>
    <script src="/platform-console.js?v={{ filemtime(public_path('platform-console.js')) }}" defer></script>
</head>
<body>
    <div id="platform-root" data-product="{{ config('app.name') }}"@if(\App\Http\Middleware\PlatformHost::embedded()) data-app-login="/login"@endif>
        <noscript><main class="login-screen"><section class="login-card"><h1>Platform console requires JavaScript</h1><p>Enable JavaScript to use the secure staff sign-in.</p></section></main></noscript>
    </div>
</body>
</html>
