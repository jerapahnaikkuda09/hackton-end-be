#!/bin/bash

echo "==================================================="
echo "🛡️ Memulai Instalasi BEBAS Code Scanner 🛡️"
echo "==================================================="

# 1. Pastikan user ada di dalam repository Git
if [ ! -d ".git" ]; then
    echo "❌ ERROR: Folder .git tidak ditemukan!"
    echo "Pastikan kamu menjalankan script ini di root folder proyek Git-mu."
    exit 1
fi

# 2. Buat folder hooks jika belum ada
mkdir -p .git/hooks

# 3. Copy file penjaga ke sarangnya
echo "⏳ Memasang Gatekeeper (pre-push hook)..."
cp pre-push .git/hooks/pre-push

# 4. Berikan izin eksekusi agar script bisa jalan (Penting untuk Windows/Mac/Linux)
chmod +x .git/hooks/pre-push

echo "✅ Instalasi Berhasil!"
echo "Sistem Anti-Cheat BEBAS kini aktif menjaga kodemu."
echo "Setiap kali kamu melakukan 'git push', scanner akan berjalan otomatis."
echo "==================================================="