<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name') }}</title>

    {{-- ENG-13: built by Vite (public/build) --}}
    @vite(['resources/js/src/assets/sass/app.scss', 'resources/js/src/main.js'])

    <link rel="shortcut icon" href="{{ asset('favicon.png') }}">

    {{-- FUN-19: installable web app --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0f3460">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ \App\Http\Controllers\AppController::shortName((string) config('app.name')) }}">
    <link rel="apple-touch-icon" href="{{ asset('images/pwa/icon-192.png') }}">

    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,500;8..60,600;8..60,700&display=swap" rel="stylesheet">
</head>

<body>
    <noscript>
        <strong>{{ config('app.name') }} needs JavaScript to run. Please enable it to continue.</strong>
    </noscript>
    <div id="app"
         data-firm-name="{{ \App\Branding\Branding::shellName() }}"
         @if(!empty($firmBilling)) data-firm-billing="1" @endif
         data-product-name="{{ $productName ?? config('app.name') }}"
         data-product-short="{{ $productShort ?? config('app.short_name') }}"
         @if(!empty($operatorLogin)) data-operator-login="1" @endif
         @if(!empty($signupUrl)) data-signup-url="{{ $signupUrl }}" @endif
         @if(!empty($platformLoginUrl)) data-platform-login-url="{{ $platformLoginUrl }}" @endif></div>

</body>
</html>
