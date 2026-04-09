<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'BEBAS Scanner')</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 min-h-screen font-sans flex items-center justify-center">
    <div class="w-full max-w-md px-6">
        @yield('content')
    </div>
    <script>
        function getToken() { return localStorage.getItem('bebas_token') || ''; }
        if (getToken() && (window.location.pathname === '/login' || window.location.pathname === '/register')) {
            window.location.href = '/dashboard';
        }
    </script>
    @yield('scripts')
</body>
</html>
