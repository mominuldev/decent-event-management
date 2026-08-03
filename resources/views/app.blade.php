<!DOCTYPE html>
<html lang="en" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0d5c5c">
    <title>{{ config('app.name', 'Decent Ticket Management') }} — Admin</title>

    {{-- Applied before first paint so a dark-mode user never sees a light flash. --}}
    <script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            try {
                var stored = localStorage.getItem('decent-admin-theme');
                var theme = stored === 'dark' || stored === 'light'
                    ? stored
                    : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {}
        })();
    </script>

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
