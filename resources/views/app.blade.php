<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="application-name" content="{{ config('app.name', 'Malikia Pro') }}">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Malikia Pro') }}">
        <meta name="theme-color" content="#0f172a">
        <meta name="msapplication-TileColor" content="#0f172a">
        <link rel="icon" type="image/svg+xml" href="{{ asset('brand/bimi-logo.svg') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="manifest" href="{{ asset('site.webmanifest') }}">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">

        <title inertia>{{ config('app.name', 'Malikia pro') }}</title>

        <script @if(!empty($cspNonce)) nonce="{{ $cspNonce }}" @endif>
            (function () {
                var theme = localStorage.getItem('hs_theme') || 'default';
                if (theme === 'auto') {
                    theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'default';
                }

                var root = document.documentElement;
                root.classList.remove('light', 'dark', 'default', 'auto');
                root.classList.add(theme);
            })();
        </script>

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-stone-50 text-stone-900 overflow-x-hidden dark:bg-neutral-950 dark:text-neutral-100">
        @inertia
    </body>
</html>
