<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name'))</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @php
        $inlineCss = '';
        $manifestPath = public_path('build/manifest.json');
        if (is_file($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true) ?: [];
            $entry = $manifest['resources/js/app.js'] ?? $manifest['resources/css/app.css'] ?? null;
            $cssFiles = is_array($entry) ? ($entry['css'] ?? []) : [];
            foreach ($cssFiles as $cssFile) {
                $cssPath = public_path('build/' . ltrim($cssFile, '/'));
                if (is_file($cssPath)) {
                    $inlineCss .= file_get_contents($cssPath) . "\n";
                }
            }
        }
    @endphp
    @if ($inlineCss !== '')
        <style>
            {!! $inlineCss !!}
        </style>
    @else
        @vite('resources/css/app.css')
    @endif
</head>
<body class="bg-gray-50 text-gray-900">
    <main class="min-h-screen">
        <div class="p-4 sm:p-6 lg:p-8">
            @yield('content')
        </div>
    </main>
</body>
</html>
