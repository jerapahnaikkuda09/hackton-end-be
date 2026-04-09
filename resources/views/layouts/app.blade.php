<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'BEBAS Scanner')</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        .prose pre { background: #1e293b; color: #e2e8f0; padding: 1rem; border-radius: 0.5rem; overflow-x: auto; }
        .prose code { background: #f1f5f9; padding: 0.125rem 0.375rem; border-radius: 0.25rem; font-size: 0.875rem; }
        .prose pre code { background: transparent; padding: 0; }
        .severity-critical { color: #ef4444; }
        .severity-warning { color: #f59e0b; }
        .severity-info { color: #3b82f6; }
        .badge { display: inline-block; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
        .badge-critical { background: #fef2f2; color: #dc2626; }
        .badge-warning { background: #fffbeb; color: #d97706; }
        .badge-info { background: #eff6ff; color: #2563eb; }
        .badge-none { background: #f0fdf4; color: #16a34a; }
        .fade-in { animation: fadeIn 0.3s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        .spinner { border: 3px solid #e5e7eb; border-top: 3px solid #6366f1; border-radius: 50%; width: 1.25rem; height: 1.25rem; animation: spin 0.8s linear infinite; display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body class="bg-gray-50 min-h-screen font-sans text-gray-900">
    {{-- Navbar --}}
    <nav class="bg-white border-b border-gray-200 shadow-sm sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-14 items-center">
                <div class="flex items-center gap-6">
                    <a href="/dashboard" class="font-bold text-lg text-indigo-600">BEBAS Scanner</a>
                    <div class="hidden sm:flex items-center gap-1" id="nav-links">
                        <a href="/dashboard" class="nav-link px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100">Dashboard</a>
                        <a href="/scans" class="nav-link px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100">Scans</a>
                        <a href="/scan-requests" class="nav-link px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100">Repo Sync</a>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span id="nav-user" class="text-sm text-gray-500"></span>
                    <button onclick="handleLogout()" class="text-sm text-red-500 hover:text-red-700 font-medium">Logout</button>
                </div>
            </div>
        </div>
    </nav>

    {{-- Content --}}
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        @yield('content')
    </main>

    <script>
        // ── Global API Helper ──
        function getToken() { return localStorage.getItem('bebas_token') || ''; }
        function setToken(t) { localStorage.setItem('bebas_token', t); }

        async function api(method, path, body = null) {
            const opts = {
                method,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-API-Token': getToken(),
                },
            };
            if (body) opts.body = JSON.stringify(body);
            const res = await fetch('/api/v1' + path, opts);
            if (res.status === 401) {
                localStorage.removeItem('bebas_token');
                window.location.href = '/login';
                return null;
            }
            return res.json();
        }

        function handleLogout() {
            localStorage.removeItem('bebas_token');
            localStorage.removeItem('bebas_user');
            window.location.href = '/login';
        }

        // ── Auth Guard ──
        const publicPages = ['/login', '/register'];
        if (!publicPages.includes(window.location.pathname)) {
            if (!getToken()) window.location.href = '/login';
            try {
                const u = JSON.parse(localStorage.getItem('bebas_user') || '{}');
                if (u.name) document.addEventListener('DOMContentLoaded', () => {
                    const el = document.getElementById('nav-user');
                    if (el) el.textContent = u.name;
                });
            } catch(e) {}
        }

        // ── Active Nav ──
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.nav-link').forEach(a => {
                if (window.location.pathname.startsWith(a.getAttribute('href'))) {
                    a.classList.add('bg-gray-100', 'text-gray-900');
                }
            });
        });

        function severityBadge(s) {
            return `<span class="badge badge-${s}">${s.toUpperCase()}</span>`;
        }

        function timeAgo(dt) {
            const d = new Date(dt);
            const diff = (Date.now() - d) / 1000;
            if (diff < 60) return 'baru saja';
            if (diff < 3600) return Math.floor(diff/60) + ' menit lalu';
            if (diff < 86400) return Math.floor(diff/3600) + ' jam lalu';
            return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
        }
    </script>
    @yield('scripts')
</body>
</html>
