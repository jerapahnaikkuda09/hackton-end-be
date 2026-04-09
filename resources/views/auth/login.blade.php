@extends('layouts.guest')
@section('title', 'Login - BEBAS Scanner')

@section('content')
<div class="bg-white rounded-xl shadow-lg p-8">
    <div class="text-center mb-6">
        <h1 class="text-2xl font-bold text-indigo-600">BEBAS Scanner</h1>
        <p class="text-gray-500 text-sm mt-1">Masuk ke akun Anda</p>
    </div>

    <div id="error-box" class="hidden bg-red-50 text-red-600 text-sm rounded-lg p-3 mb-4"></div>

    <form id="login-form" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input type="email" id="email" required
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                placeholder="nama@email.com">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input type="password" id="password" required
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                placeholder="••••••••">
        </div>
        <button type="submit" id="btn-login"
            class="w-full bg-indigo-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-indigo-700 transition">
            Masuk
        </button>
    </form>

    <p class="text-center text-sm text-gray-500 mt-4">
        Belum punya akun? <a href="/register" class="text-indigo-600 hover:underline font-medium">Daftar</a>
    </p>
</div>
@endsection

@section('scripts')
<script>
document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btn-login');
    const errBox = document.getElementById('error-box');
    errBox.classList.add('hidden');
    btn.disabled = true;
    btn.textContent = 'Memproses...';

    try {
        const res = await fetch('/api/v1/auth/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                email: document.getElementById('email').value,
                password: document.getElementById('password').value,
            }),
        });
        const data = await res.json();
        if (data.success && data.api_token) {
            localStorage.setItem('bebas_token', data.api_token);
            localStorage.setItem('bebas_user', JSON.stringify(data.user || {}));
            window.location.href = '/dashboard';
        } else {
            errBox.textContent = data.message || 'Email atau password salah.';
            errBox.classList.remove('hidden');
        }
    } catch (err) {
        errBox.textContent = 'Gagal menghubungi server.';
        errBox.classList.remove('hidden');
    }
    btn.disabled = false;
    btn.textContent = 'Masuk';
});
</script>
@endsection
