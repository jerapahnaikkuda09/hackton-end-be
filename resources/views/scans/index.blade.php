@extends('layouts.app')
@section('title', 'Scans - BEBAS Scanner')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Riwayat Scan</h1>
        <p class="text-gray-500 text-sm mt-1">Semua hasil scan dari CLI dan GitHub Action</p>
    </div>
</div>

{{-- Filter --}}
<div class="flex items-center gap-3 mb-4">
    <select id="filter-source" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        <option value="">Semua Sumber</option>
        <option value="local">Local CLI</option>
        <option value="github_action">GitHub Action</option>
    </select>
    <select id="filter-severity" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        <option value="">Semua Severity</option>
        <option value="critical">Critical</option>
        <option value="warning">Warning</option>
        <option value="info">Info</option>
        <option value="none">None</option>
    </select>
    <button onclick="loadScans()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition">Filter</button>
</div>

{{-- Scan List --}}
<div id="scan-list" class="space-y-3">
    <div class="text-center text-gray-400 py-12"><span class="spinner"></span></div>
</div>

{{-- Pagination --}}
<div id="pagination" class="flex items-center justify-center gap-2 mt-6"></div>
@endsection

@section('scripts')
<script>
let currentPage = 1;

async function loadScans(page = 1) {
    currentPage = page;
    const container = document.getElementById('scan-list');
    container.innerHTML = '<div class="text-center text-gray-400 py-12"><span class="spinner"></span></div>';

    let query = `?page=${page}`;
    const src = document.getElementById('filter-source').value;
    const sev = document.getElementById('filter-severity').value;
    if (src) query += `&source=${src}`;
    if (sev) query += `&severity=${sev}`;

    const data = await api('GET', '/scans' + query);
    const items = data?.data || [];

    if (!items.length) {
        container.innerHTML = '<p class="text-center text-gray-400 py-12">Tidak ada scan ditemukan.</p>';
        document.getElementById('pagination').innerHTML = '';
        return;
    }

    container.innerHTML = items.map(s => `
        <a href="/scans/${s.id}" class="block bg-white border border-gray-200 rounded-xl p-5 hover:shadow-md transition fade-in">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div>
                        <span class="font-semibold text-gray-900">${s.repository || 'Unknown'}</span>
                        <span class="text-gray-400 text-sm ml-2">${s.branch || ''}</span>
                        ${s.blocked ? '<span class="badge badge-critical ml-2">BLOCKED</span>' : ''}
                    </div>
                </div>
                ${severityBadge(s.max_severity || 'none')}
            </div>
            <div class="flex items-center gap-4 mt-3 text-xs text-gray-500">
                <span class="bg-gray-100 px-2 py-0.5 rounded">${s.source === 'github_action' ? 'GitHub' : 'Local'}</span>
                <span class="severity-critical">C: ${s.total_critical}</span>
                <span class="severity-warning">W: ${s.total_warning}</span>
                <span class="severity-info">I: ${s.total_info}</span>
                ${s.commit_hash ? `<span class="font-mono">${s.commit_hash.substring(0,8)}</span>` : ''}
                <span class="ml-auto">${timeAgo(s.created_at)}</span>
            </div>
        </a>
    `).join('');

    // Pagination
    const pag = document.getElementById('pagination');
    const lastPage = data.last_page || 1;
    if (lastPage > 1) {
        let html = '';
        for (let i = 1; i <= lastPage; i++) {
            html += `<button onclick="loadScans(${i})" class="px-3 py-1.5 rounded-lg text-sm ${i === currentPage ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-300 text-gray-600 hover:bg-gray-50'}">${i}</button>`;
        }
        pag.innerHTML = html;
    } else {
        pag.innerHTML = '';
    }
}

document.addEventListener('DOMContentLoaded', () => loadScans());
</script>
@endsection
