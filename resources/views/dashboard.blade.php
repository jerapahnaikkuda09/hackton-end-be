@extends('layouts.app')
@section('title', 'Dashboard - BEBAS Scanner')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
    <p class="text-gray-500 text-sm mt-1">Ringkasan hasil scan kode Anda</p>
</div>

{{-- Stats Cards --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8" id="stats-cards">
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
        <p class="text-sm text-gray-500">Total Scan</p>
        <p class="text-3xl font-bold text-gray-900 mt-1" id="stat-total">-</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
        <p class="text-sm text-gray-500">Critical Issues</p>
        <p class="text-3xl font-bold text-red-600 mt-1" id="stat-critical">-</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
        <p class="text-sm text-gray-500">Warnings</p>
        <p class="text-3xl font-bold text-amber-500 mt-1" id="stat-warning">-</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
        <p class="text-sm text-gray-500">Info</p>
        <p class="text-3xl font-bold text-blue-500 mt-1" id="stat-info">-</p>
    </div>
</div>

{{-- API Token --}}
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 mb-8">
    <h2 class="font-semibold text-gray-900 mb-2">API Token Anda</h2>
    <p class="text-xs text-gray-500 mb-3">Gunakan token ini di CLI scanner dengan environment variable <code class="bg-gray-100 px-1 rounded">BEBAS_API_TOKEN</code></p>
    <div class="flex items-center gap-2">
        <code id="api-token" class="flex-1 bg-gray-100 text-sm px-3 py-2 rounded-lg font-mono text-gray-700 overflow-x-auto">Loading...</code>
        <button onclick="copyToken()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition shrink-0">Copy</button>
    </div>
</div>

{{-- Setup Guide --}}
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 mb-8">
    <h2 class="font-semibold text-gray-900 mb-2">🚀 Setup Scanner di Project Lain</h2>
    <p class="text-xs text-gray-500 mb-4">Ikuti langkah berikut untuk menjalankan scanner di project manapun</p>

    <div class="space-y-3">
        {{-- Step 1: Download --}}
        <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
            <span class="bg-indigo-600 text-white text-xs font-bold rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">1</span>
            <div class="flex-1">
                <p class="text-sm font-medium text-gray-900">Download scanner.py ke folder project Anda</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <a href="/scanner/download" class="inline-flex items-center bg-indigo-600 text-white px-4 py-1.5 rounded-lg text-xs font-medium hover:bg-indigo-700 transition">
                        ⬇ Download scanner.py
                    </a>
                    <span class="text-xs text-gray-400 self-center">atau via PowerShell:</span>
                </div>
                <code id="cmd-download" class="block bg-gray-900 text-green-400 text-xs px-3 py-2 rounded-lg font-mono mt-2 overflow-x-auto cursor-pointer hover:bg-gray-800 transition" onclick="copyCmd(this)">Invoke-WebRequest -Uri http://localhost:8000/scanner/download -OutFile scanner.py</code>
            </div>
        </div>

        {{-- Step 2: Install Hooks --}}
        <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
            <span class="bg-indigo-600 text-white text-xs font-bold rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">2</span>
            <div class="flex-1">
                <p class="text-sm font-medium text-gray-900">Pasang Penjaga Otomatis (Git Hooks)</p>
                <p class="text-xs text-gray-500 mt-1">Sistem akan otomatis meminta Token Anda (paste dari kotak atas) dan menciptakan file <code class="bg-gray-200 px-1 rounded">.bebas</code>.</p>
                <code class="block bg-gray-900 text-green-400 text-xs px-3 py-2 rounded-lg font-mono mt-2 overflow-x-auto cursor-pointer hover:bg-gray-800 transition" onclick="copyCmd(this)">python scanner.py --setup-hooks</code>
            </div>
        </div>

        {{-- Step 3: Selesai --}}
        <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
            <span class="bg-indigo-600 text-white text-xs font-bold rounded-full w-6 h-6 flex items-center justify-center shrink-0 mt-0.5">3</span>
            <div class="flex-1">
                <p class="text-sm font-medium text-gray-900">Selesai! Lakukan <i>Git Commit</i></p>
                <p class="text-xs text-gray-500 mt-1">Scanner akan tertanam dan langsung aktif otomatis menolak kode bocor setiap kali Anda menyimpan git commit.</p>
            </div>
        </div>
    </div>
</div>

{{-- Recent Scans --}}
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
    <div class="flex items-center justify-between mb-4">
        <h2 class="font-semibold text-gray-900">Scan Terbaru</h2>
        <a href="/scans" class="text-sm text-indigo-600 hover:underline">Lihat semua →</a>
    </div>
    <div id="recent-scans" class="space-y-3">
        <div class="text-center text-gray-400 py-8"><span class="spinner"></span></div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', async () => {
    // Load stats
    const stats = await api('GET', '/dashboard/stats');
    if (stats) {
        document.getElementById('stat-total').textContent = stats.total_scans ?? 0;
        document.getElementById('stat-critical').textContent = stats.severity_distribution?.critical ?? 0;
        document.getElementById('stat-warning').textContent = stats.severity_distribution?.warning ?? 0;
        document.getElementById('stat-info').textContent = stats.severity_distribution?.info ?? 0;
    }

    // Load user info + token
    const me = await api('GET', '/auth/me');
    if (me) {
        document.getElementById('api-token').textContent = me.user?.api_token || me.api_token || '-';
        const u = me.user || me.data || me;
        if (u.name) {
            document.getElementById('nav-user').textContent = u.name;
            localStorage.setItem('bebas_user', JSON.stringify(u));
        }
    }

    // Load recent scans
    const scans = await api('GET', '/scans?per_page=5');
    const container = document.getElementById('recent-scans');
    const items = scans?.data || [];
    if (!items.length) {
        container.innerHTML = '<p class="text-center text-gray-400 py-6">Belum ada scan. Jalankan CLI scanner untuk memulai.</p>';
        return;
    }
    container.innerHTML = items.map(s => `
        <a href="/scans/${s.id}" class="block border border-gray-100 rounded-lg p-4 hover:bg-gray-50 transition fade-in">
            <div class="flex items-center justify-between">
                <div>
                    <span class="font-medium text-gray-900">${s.repository || 'Unknown'}</span>
                    <span class="text-gray-400 text-sm ml-2">${s.branch || ''}</span>
                </div>
                ${severityBadge(s.max_severity || 'none')}
            </div>
            <div class="flex items-center gap-4 mt-2 text-xs text-gray-500">
                <span>Critical: ${s.total_critical}</span>
                <span>Warning: ${s.total_warning}</span>
                <span>Info: ${s.total_info}</span>
                <span class="ml-auto">${timeAgo(s.created_at)}</span>
            </div>
        </a>
    `).join('');
});

function copyToken() {
    const t = document.getElementById('api-token').textContent;
    navigator.clipboard.writeText(t).then(() => {
        const btn = event.target;
        btn.textContent = 'Copied!';
        setTimeout(() => btn.textContent = 'Copy', 1500);
    });
}
</script>
@endsection
