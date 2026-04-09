@extends('layouts.app')
@section('title', 'Scan Detail - BEBAS Scanner')

@section('content')
<div class="mb-6">
    <a href="/scans" class="text-sm text-indigo-600 hover:underline">← Kembali ke daftar</a>
</div>

{{-- Scan Info --}}
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6" id="scan-info">
    <div class="text-center text-gray-400 py-8"><span class="spinner"></span></div>
</div>

{{-- Issues --}}
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="font-semibold text-gray-900 text-lg">Daftar Isu</h2>
        <span id="issue-count" class="text-sm text-gray-500"></span>
    </div>
    <div id="issues-list" class="space-y-3">
        <div class="text-center text-gray-400 py-4"><span class="spinner"></span></div>
    </div>
</div>

{{-- AI Chat --}}
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h2 class="font-semibold text-gray-900 text-lg mb-2">Tanya AI tentang Scan Ini</h2>
    <p class="text-xs text-gray-500 mb-4">Tanyakan apa saja tentang isu yang ditemukan. AI akan membantu menjelaskan dan memberikan saran perbaikan.</p>

    <div id="chat-messages" class="space-y-3 mb-4 max-h-96 overflow-y-auto"></div>

    <form id="chat-form" class="flex gap-2">
        <input type="text" id="chat-input" placeholder="Contoh: Bagaimana cara memperbaiki isu pertama?"
            class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        <button type="submit" id="chat-btn"
            class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition shrink-0">
            Kirim
        </button>
    </form>
</div>

{{-- Delete --}}
<div class="mt-6 text-right">
    <button onclick="deleteScan()" class="text-sm text-red-500 hover:text-red-700 font-medium">Hapus Scan Ini</button>
</div>
@endsection

@section('scripts')
<script>
const scanId = window.location.pathname.split('/').pop();
let chatHistory = [];
let scanData = null;

document.addEventListener('DOMContentLoaded', async () => {
    const data = await api('GET', `/scans/${scanId}`);
    if (!data || data.success === false) {
        document.getElementById('scan-info').innerHTML = '<p class="text-center text-red-500">Scan tidak ditemukan.</p>';
        return;
    }
    scanData = data;

    // Scan info
    document.getElementById('scan-info').innerHTML = `
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-xl font-bold text-gray-900">${data.repository || 'Unknown'}</h1>
            ${severityBadge(data.max_severity || 'none')}
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
            <div><span class="text-gray-500">Branch:</span><br><span class="font-medium">${data.branch || '-'}</span></div>
            <div><span class="text-gray-500">Commit:</span><br><span class="font-mono font-medium">${data.commit_hash ? data.commit_hash.substring(0,8) : '-'}</span></div>
            <div><span class="text-gray-500">Sumber:</span><br><span class="font-medium">${data.source === 'github_action' ? 'GitHub Action' : 'Local CLI'}</span></div>
            <div><span class="text-gray-500">Status:</span><br><span class="font-medium">${data.blocked ? '<span class="text-red-600">DIBLOKIR</span>' : '<span class="text-green-600">OK</span>'}</span></div>
        </div>
        <div class="flex items-center gap-6 mt-4 text-sm">
            <span class="severity-critical font-semibold">Critical: ${data.total_critical}</span>
            <span class="severity-warning font-semibold">Warning: ${data.total_warning}</span>
            <span class="severity-info font-semibold">Info: ${data.total_info}</span>
            <span class="text-gray-400 ml-auto">${timeAgo(data.created_at)}</span>
        </div>
    `;

    // Issues
    const issues = data.issues || [];
    document.getElementById('issue-count').textContent = `${issues.length} isu ditemukan`;

    if (!issues.length) {
        document.getElementById('issues-list').innerHTML = '<p class="text-center text-green-500 py-4">Tidak ada isu. Kode aman!</p>';
        return;
    }

    document.getElementById('issues-list').innerHTML = issues.map((issue, i) => `
        <div class="border border-gray-100 rounded-lg p-4 hover:bg-gray-50 transition fade-in">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    ${severityBadge(issue.severity)}
                    <span class="text-sm font-medium text-gray-900">${issue.type}</span>
                </div>
                <button onclick="askAboutIssue(${i})" class="text-xs text-indigo-600 hover:underline font-medium">Tanya AI →</button>
            </div>
            <p class="text-sm text-gray-600 mt-2">${issue.message}</p>
            <p class="text-xs text-gray-400 mt-1 font-mono">${issue.file}:${issue.line || '?'}</p>
        </div>
    `).join('');
});

function askAboutIssue(index) {
    const issue = scanData.issues[index];
    const input = document.getElementById('chat-input');
    input.value = `Jelaskan isu #${index + 1}: "${issue.message}" di file ${issue.file}:${issue.line} dan berikan cara memperbaikinya.`;
    input.focus();
}

document.getElementById('chat-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = document.getElementById('chat-input');
    const btn = document.getElementById('chat-btn');
    const question = input.value.trim();
    if (!question) return;

    // Tampilkan pesan user
    appendChat('user', question);
    input.value = '';
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';

    // Kirim ke API
    const data = await api('POST', '/llm/ask', {
        scan_id: parseInt(scanId),
        question: question,
        history: chatHistory,
    });

    if (data && data.success) {
        appendChat('ai', data.answer);
        chatHistory = data.history || [];
    } else {
        const errorMsg = data && data.message ? data.message : 'Gagal mendapatkan jawaban dari AI. Coba lagi.';
        appendChat('ai', `**[Backend Error]** ${errorMsg}`);
    }

    btn.disabled = false;
    btn.textContent = 'Kirim';
});

function appendChat(role, text) {
    const container = document.getElementById('chat-messages');
    const div = document.createElement('div');
    div.className = 'fade-in';

    if (role === 'user') {
        div.innerHTML = `
            <div class="flex justify-end">
                <div class="bg-indigo-600 text-white rounded-xl rounded-tr-sm px-4 py-2 max-w-lg text-sm">${escapeHtml(text)}</div>
            </div>`;
    } else {
        div.innerHTML = `
            <div class="flex justify-start">
                <div class="bg-gray-100 rounded-xl rounded-tl-sm px-4 py-3 max-w-2xl text-sm prose prose-sm">${marked.parse(text)}</div>
            </div>`;
    }

    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

async function deleteScan() {
    if (!confirm('Yakin ingin menghapus scan ini?')) return;
    await api('DELETE', `/scans/${scanId}`);
    window.location.href = '/scans';
}
</script>
@endsection
