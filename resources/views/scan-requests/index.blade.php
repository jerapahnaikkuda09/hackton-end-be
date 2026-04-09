@extends('layouts.app')
@section('title', 'Repo Sync - BEBAS Scanner')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Sinkronisasi Repo</h1>
    <p class="text-gray-500 text-sm mt-1">Minta scan terhadap repository user lain via URL</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    {{-- Request Scan --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="font-semibold text-gray-900 mb-4">Request Scan Baru</h2>
        <div id="req-error" class="hidden bg-red-50 text-red-600 text-sm rounded-lg p-3 mb-3"></div>
        <div id="req-success" class="hidden bg-green-50 text-green-600 text-sm rounded-lg p-3 mb-3"></div>
        <form id="request-form" class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">URL Repository</label>
                <input type="url" id="repo-url" required
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    placeholder="https://github.com/user/repository">
            </div>
            <button type="submit" id="btn-request"
                class="w-full bg-indigo-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-indigo-700 transition">
                Kirim Request
            </button>
        </form>
    </div>

    {{-- Pending Requests (untuk pemilik repo) --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="font-semibold text-gray-900 mb-4">Request Masuk (Menunggu)</h2>
        <p class="text-xs text-gray-500 mb-3">Request scan dari user lain yang ditujukan ke Anda. Jalankan CLI <code class="bg-gray-100 px-1 rounded">--poll</code> untuk memproses otomatis.</p>
        <div id="pending-list" class="space-y-3">
            <div class="text-center text-gray-400 py-4"><span class="spinner"></span></div>
        </div>
    </div>
</div>

{{-- My Requests --}}
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mt-6">
    <h2 class="font-semibold text-gray-900 mb-4">Request Saya</h2>
    <div id="my-requests" class="space-y-3">
        <div class="text-center text-gray-400 py-4"><span class="spinner"></span></div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    loadPending();
    loadMyRequests();
});

// Kirim request scan
document.getElementById('request-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btn-request');
    const errBox = document.getElementById('req-error');
    const succBox = document.getElementById('req-success');
    errBox.classList.add('hidden');
    succBox.classList.add('hidden');
    btn.disabled = true;
    btn.textContent = 'Mengirim...';

    const data = await api('POST', '/scan-requests', {
        repo_url: document.getElementById('repo-url').value,
    });

    if (data && data.success) {
        succBox.innerHTML = `Request berhasil! ID: <strong>${data.scan_request_id}</strong>. ${data.owner_found ? 'Pemilik repo ditemukan.' : '<span class="text-amber-600">Pemilik repo belum teridentifikasi — menunggu klaim.</span>'}`;
        succBox.classList.remove('hidden');
        document.getElementById('repo-url').value = '';
        loadMyRequests();
    } else {
        errBox.textContent = data?.message || 'Gagal mengirim request.';
        errBox.classList.remove('hidden');
    }

    btn.disabled = false;
    btn.textContent = 'Kirim Request';
});

async function loadPending() {
    const container = document.getElementById('pending-list');
    const data = await api('GET', '/scan-requests/pending');
    const items = data?.data || [];

    if (!items.length) {
        container.innerHTML = '<p class="text-center text-gray-400 py-4">Tidak ada request masuk.</p>';
        return;
    }

    container.innerHTML = items.map(r => `
        <div class="border border-gray-100 rounded-lg p-3 fade-in">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-900">Request #${r.id}</span>
                <span class="badge badge-warning">PENDING</span>
            </div>
            <p class="text-xs text-gray-500 mt-1 font-mono truncate">${r.repo_url}</p>
            <p class="text-xs text-gray-400 mt-1">Dari user #${r.requester_user_id} · ${timeAgo(r.created_at)}</p>
        </div>
    `).join('');
}

async function loadMyRequests() {
    const container = document.getElementById('my-requests');
    // Ambil semua scan requests yang saya buat — kita akan fetch beberapa ID
    const stored = JSON.parse(localStorage.getItem('bebas_scan_requests') || '[]');

    if (!stored.length) {
        container.innerHTML = '<p class="text-center text-gray-400 py-4">Belum ada request. Kirim request di atas untuk memulai.</p>';
        return;
    }

    let html = '';
    for (const id of stored.slice(-10).reverse()) {
        const data = await api('GET', `/scan-requests/${id}`);
        if (!data || !data.success) continue;

        const statusBadge = data.status === 'fulfilled'
            ? '<span class="badge badge-none">FULFILLED</span>'
            : data.status === 'failed'
                ? '<span class="badge badge-critical">FAILED</span>'
                : '<span class="badge badge-warning">PENDING</span>';

        html += `
            <div class="border border-gray-100 rounded-lg p-4 fade-in">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-900">Request #${data.scan_request_id}</span>
                    ${statusBadge}
                </div>
                <p class="text-xs text-gray-500 mt-1 font-mono truncate">${data.repo_url}</p>
                ${data.scan ? `
                    <a href="/scans/${data.scan.id}" class="inline-block mt-2 text-xs text-indigo-600 hover:underline">
                        Lihat hasil scan → (${data.scan.max_severity}, Critical: ${data.scan.total_critical})
                    </a>
                ` : '<p class="text-xs text-gray-400 mt-1">Menunggu pemilik repo menjalankan scan...</p>'}
            </div>
        `;
    }

    container.innerHTML = html || '<p class="text-center text-gray-400 py-4">Belum ada request.</p>';
}

// Override form submit to save request IDs
const origSubmit = document.getElementById('request-form');
const origHandler = origSubmit.onsubmit;
origSubmit.addEventListener('submit', async () => {
    // Wait a beat for the main handler to run
    setTimeout(async () => {
        // Re-check: loadMyRequests will pick up from localStorage
    }, 500);
});

// Patch API to save scan request IDs
const origApi = window.api;
const patchedApi = async (method, path, body) => {
    const res = await origApi(method, path, body);
    if (method === 'POST' && path === '/scan-requests' && res?.success && res.scan_request_id) {
        const stored = JSON.parse(localStorage.getItem('bebas_scan_requests') || '[]');
        stored.push(res.scan_request_id);
        localStorage.setItem('bebas_scan_requests', JSON.stringify(stored));
    }
    return res;
};
window.api = patchedApi;
</script>
@endsection
