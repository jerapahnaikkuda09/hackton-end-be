#!/usr/bin/env python3

import os
import re
import sys
import json
import subprocess
import argparse
import urllib.request
import urllib.error

# ─────────────────────────────────────────────────────────────────────────────
# KONFIGURASI
# ─────────────────────────────────────────────────────────────────────────────

# Ekstensi file yang akan di-scan
SCAN_EXTENSIONS = {
    '.php', '.js', '.ts', '.py', '.env', '.json',
    '.yml', '.yaml', '.xml', '.config', '.conf',
    '.sh', '.bat', '.rb', '.go', '.java', '.cs',
    '.html', '.blade', '.vue', '.jsx', '.tsx',
}

# Folder yang diabaikan
IGNORED_DIRS = {
    'vendor', 'node_modules', '.git', 'storage',
    'bootstrap/cache', '.idea', '.vscode', 'dist', 'build',
}

# ─────────────────────────────────────────────────────────────────────────────
# GITLEAKS INTEGRATION
# ─────────────────────────────────────────────────────────────────────────────

def run_gitleaks_on_files(files, target_dir='.', mode='push'):
    """Menjalankan Gitleaks dan mengembalikan daftar isu rahasia (secrets)."""
    issues = []
    
    # Path file sementara untuk hasil gitleaks
    report_file = os.path.join(target_dir, "gitleaks_report_temp.json")
    
    # Pastikan file report lama tidak ada
    if os.path.exists(report_file):
        os.remove(report_file)

    # Dapatkan daftar file yang di-ignore oleh git (agar tidak false-positive pada .env)
    gitignored_files = set()
    try:
        res = subprocess.run(
            ['git', 'ls-files', '--others', '--ignored', '--exclude-standard'],
            capture_output=True, text=True, cwd=target_dir
        )
        if res.returncode == 0:
            gitignored_files = {f.strip().replace('\\', '/') for f in res.stdout.splitlines() if f.strip()}
    except (FileNotFoundError, subprocess.CalledProcessError):
        pass

    try:
        # Menjalankan gitleaks untuk mendeteksi rahasia (tanpa history git, full repo)
        subprocess.run(
            ['gitleaks', 'detect', '--no-git', '--source', target_dir,
             '--report-path', report_file, '--report-format', 'json', '--exit-code', '0'],
            capture_output=True, text=True
        )
        
        if os.path.exists(report_file):
            with open(report_file, 'r', encoding='utf-8') as f:
                try:
                    gitleaks_data = json.load(f)
                except json.JSONDecodeError:
                    gitleaks_data = []
                    
            # Hapus file report setelah dibaca
            os.remove(report_file)
            
            # Normalisasi path untuk pencocokan file
            normalized_files = [f.replace('\\', '/') for f in files]
            
            for leak in gitleaks_data:
                # Format dari Gitleaks -> leak['File']
                leak_file = leak.get('File', '').replace('\\', '/')
                basename = os.path.basename(leak_file)
                
                # Normalisasi path relatif untuk pencocokan gitignore
                leak_relative = leak_file
                target_prefix = target_dir.replace('\\', '/').rstrip('/') + '/'
                if leak_relative.startswith(target_prefix):
                    leak_relative = leak_relative[len(target_prefix):]
                
                # Skip file yang ada di .gitignore (seperti .env) — file ini tidak akan ter-commit
                if leak_relative in gitignored_files:
                    continue
                
                # Masukkan jika: mode=all, ATAU file ada di daftar, ATAU file .env yang DI-TRACK git
                if mode == 'all' or leak_file in normalized_files or basename.startswith('.env'):
                    secret_censored = leak.get('Secret', '')
                    secret_censored = secret_censored[:20] + '...' if len(secret_censored) > 20 else secret_censored
                    
                    issues.append({
                        'file': leak_file,
                        'line': leak.get('StartLine', 0),
                        'severity': 'critical',
                        'type': 'secret',
                        'message': f"Gitleaks [{leak.get('RuleID')}]: {leak.get('Description')} ({secret_censored})",
                    })

    except FileNotFoundError:
        print("[WARNING] Gitleaks tidak terinstal atau tidak ada di PATH.")
        print("          Rahasia (Secrets) tidak akan terpindai! Silakan install: https://github.com/gitleaks/gitleaks")

    return issues

# ─────────────────────────────────────────────────────────────────────────────
# POLA CODE SMELL / WARNING
# ─────────────────────────────────────────────────────────────────────────────

CODE_SMELL_PATTERNS = [
    {
        'name': 'Hardcoded Secret / API Key (BEBAS-Aggressive)',
        'pattern': r'(?i)(\$|)(api_key|apikey|secret_key|token|auth_token|password)\s*(=|=>|:)\s*["\'][a-zA-Z0-9_\-\.\+]{8,}["\']',
        'severity': 'critical',
    },
    {
        'name': 'Debug Statement (var_dump)',
        'pattern': r'\bvar_dump\s*\(',
        'severity': 'warning',
    },
    {
        'name': 'Debug Statement (console.log)',
        'pattern': r'\bconsole\.(log|warn|error|debug)\s*\(',
        'severity': 'info',
    },
    {
        'name': 'PHP die/exit hardcoded',
        'pattern': r'(?<!\w)(die|exit)\s*\(\s*["\'][^"\']*["\']\s*\)',
        'severity': 'warning',
    },
    {
        'name': 'TODO / FIXME / HACK Comment',
        'pattern': r'(?i)#\s*(todo|fixme|hack|xxx|bug)\b',
        'severity': 'info',
    },
    {
        'name': 'Eval Usage (PHP/JS)',
        'pattern': r'\beval\s*\(',
        'severity': 'warning',
    },
    {
        'name': 'SQL Injection Risk (raw query)',
        'pattern': r'(?i)\$_(GET|POST|REQUEST|COOKIE)\s*\[',
        'severity': 'warning',
    },
]

# ─────────────────────────────────────────────────────────────────────────────
# FUNGSI UTAMA
# ─────────────────────────────────────────────────────────────────────────────

def get_changed_files(mode='push', target_dir='.'):
    """Dapatkan daftar file yang berubah dari git."""
    try:
        if mode == 'commit':
            # File yang di-staging untuk commit
            result = subprocess.run(
                ['git', 'diff', '--cached', '--name-only', '--diff-filter=ACMR'],
                capture_output=True, text=True, check=True, cwd=target_dir
            )
        else:
            # File yang berbeda dengan remote (untuk push)
            result = subprocess.run(
                ['git', 'diff', '--name-only', '--diff-filter=ACMR', 'HEAD@{upstream}..HEAD'],
                capture_output=True, text=True, cwd=target_dir
            )
            if result.returncode != 0 or not result.stdout.strip():
                # Fallback: semua file yang berubah dari commit terakhir
                result = subprocess.run(
                    ['git', 'diff', '--name-only', '--diff-filter=ACMR', 'HEAD~1..HEAD'],
                    capture_output=True, text=True, cwd=target_dir
                )
                if result.returncode != 0 or not result.stdout.strip():
                    # Fallback terakhir: scan semua file yang di-track git
                    result = subprocess.run(
                        ['git', 'ls-files'],
                        capture_output=True, text=True, check=True, cwd=target_dir
                    )

        files = [f.strip() for f in result.stdout.splitlines() if f.strip()]
        return files
    except (subprocess.CalledProcessError, FileNotFoundError):
        return []


def should_scan_file(filepath):
    """Cek apakah file perlu di-scan."""
    # Abaikan folder tertentu
    parts = filepath.replace('\\', '/').split('/')
    for part in parts[:-1]:
        if part in IGNORED_DIRS:
            return False

    filename = os.path.basename(filepath)
    # Jangan lewatkan fle konfigurasi tersembunyi (dotfiles) karena rawan rahasia
    if filename.startswith('.'):
        if filename not in {'.gitignore', '.gitattributes', '.editorconfig', '.eslintignore', '.prettierignore', '.bebas'}:
            return True

    # Cek ekstensi
    _, ext = os.path.splitext(filepath)
    if ext.lower() in SCAN_EXTENSIONS:
        return True

    return False


def scan_file_for_conflicts(filepath, content):
    """Cek apakah ada merge conflict marker yang belum diselesaikan."""
    issues = []
    conflict_patterns = [
        (r'^<{7} ', 'Conflict marker HEAD (<<<<<<< HEAD)'),
        (r'^={7}$', 'Conflict separator (=======)'),
        (r'^>{7} ', 'Conflict marker incoming (>>>>>>> branch)'),
    ]
    lines = content.splitlines()
    for line_num, line in enumerate(lines, 1):
        for pattern, label in conflict_patterns:
            if re.match(pattern, line):
                issues.append({
                    'file': filepath,
                    'line': line_num,
                    'severity': 'critical',
                    'type': 'conflict',
                    'message': f"Merge conflict belum diselesaikan: {label}",
                })
    return issues


def scan_file_for_code_smells(filepath, content):
    """Scan code smell dan pola berbahaya."""
    issues = []
    for pattern_info in CODE_SMELL_PATTERNS:
        regex = re.compile(pattern_info['pattern'], re.MULTILINE)
        for match in regex.finditer(content):
            line_num = content[:match.start()].count('\n') + 1
            issues.append({
                'file': filepath,
                'line': line_num,
                'severity': pattern_info['severity'],
                'type': 'code_smell',
                'message': f"{pattern_info['name']} ditemukan",
            })
    return issues


def run_scan(files, mode='push', target_dir='.'):
    """Jalankan scan pada semua file."""
    all_issues = []
    scanned_count = 0
    
    # Jalankan Gitleaks
    print("  [INFO] Menjalankan Gitleaks untuk memeriksa kebocoran rahasia...")
    all_issues.extend(run_gitleaks_on_files(files, target_dir, mode))

    for filepath in files:
        if not should_scan_file(filepath):
            continue

        # Resolve path relatif ke target directory
        full_path = os.path.join(target_dir, filepath) if not os.path.isabs(filepath) else filepath
        if not os.path.isfile(full_path):
            continue

        try:
            with open(full_path, 'r', encoding='utf-8', errors='ignore') as f:
                content = f.read()
                lines = content.splitlines()
        except (OSError, IOError):
            continue

        scanned_count += 1
        all_issues.extend(scan_file_for_conflicts(filepath, content))

        # Code smell hanya saat push (bukan tiap commit)
        if mode == 'push' or mode == 'all':
            all_issues.extend(scan_file_for_code_smells(filepath, content))

    # Hitung severity tertinggi
    severities = [i['severity'] for i in all_issues]
    if 'critical' in severities:
        max_severity = 'critical'
    elif 'warning' in severities:
        max_severity = 'warning'
    elif 'info' in severities:
        max_severity = 'info'
    else:
        max_severity = 'none'

    return {
        'scanned_files': scanned_count,
        'max_severity': max_severity,
        'total_critical': severities.count('critical'),
        'total_warning': severities.count('warning'),
        'total_info': severities.count('info'),
        'issues': all_issues,
    }


def get_git_info(target_dir='.'):
    """Ambil info git (repo, branch, commit hash)."""
    def run(cmd):
        try:
            r = subprocess.run(cmd, capture_output=True, text=True, cwd=target_dir)
            return r.stdout.strip() if r.returncode == 0 else ''
        except FileNotFoundError:
            return ''

    return {
        'repository': run(['git', 'rev-parse', '--show-toplevel']).split('/')[-1].split('\\')[-1],
        'branch': run(['git', 'rev-parse', '--abbrev-ref', 'HEAD']),
        'commit_hash': run(['git', 'rev-parse', 'HEAD']),
    }

import time

# Misalkan ini adalah fungsi di sekitar scanner.py baris 307
def panggil_ai_dengan_retry():
    max_retries = 3
    
    for attempt in range(max_retries):
        try:
            # --- MASUKKAN KODE ASLI ANDA DI BARIS 307 DI SINI ---
            # Contoh: response = model.generate_content(prompt)
            # return response
            pass
            
        except Exception as e:
            error_msg = str(e).lower()
            # Cek apakah error disebabkan oleh server sibuk (High Demand / Rate Limit)
            if "high demand" in error_msg or "429" in error_msg or "503" in error_msg:
                jeda_waktu = 2 ** attempt  # Akan menunggu 1 detik, lalu 2 detik, lalu 4 detik
                print(f"[Peringatan] Server AI sibuk. Mencoba lagi dalam {jeda_waktu} detik... (Percobaan {attempt + 1}/{max_retries})")
                time.sleep(jeda_waktu)
            else:
                # Jika errornya bukan karena server sibuk (misal: salah API key), lemparkan errornya
                raise e
                
    # Jika sudah mencoba 3 kali dan tetap gagal
    print("[Error] Gagal menghubungi AI setelah beberapa kali percobaan.")
    return None

def send_to_api(result, git_info, api_url, repo_url=None, scan_request_id=None):
    """Kirim hasil scan ke Laravel API."""
    token = os.environ.get('BEBAS_API_TOKEN', '')
    
    if not token:
        print("\n  [ERROR] BEBAS_API_TOKEN belum di-set!")
        print("          Atur environment variable BEBAS_API_TOKEN terlebih dahulu.")
        print("          Contoh (PowerShell): $env:BEBAS_API_TOKEN = 'bebas_xxxxx'")
        print("          Contoh (Bash/Linux): export BEBAS_API_TOKEN='bebas_xxxxx'")
        print("          Token dapat ditemukan di halaman Dashboard web BEBAS Scanner.\n")
        return None
    
    payload = {
        'repository':      git_info.get('repository'),
        'repo_url':        repo_url,
        'scan_request_id': scan_request_id,
        'branch':          git_info.get('branch'),
        'commit_hash':     git_info.get('commit_hash'),
        'issues':          result['issues'],
    }

    data = json.dumps(payload).encode('utf-8')
    req = urllib.request.Request(
        api_url,
        data=data,
        headers={
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-API-Token': token,
        },
        method='POST'
    )

    try:
        with urllib.request.urlopen(req, timeout=15) as response:
            return json.loads(response.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        error_body = ''
        try:
            error_body = e.read().decode('utf-8')
            error_json = json.loads(error_body)
            error_msg = error_json.get('message', error_body)
        except Exception:
            error_msg = error_body or str(e)
        
        print(f"\n  [ERROR] API mengembalikan HTTP {e.code}")
        if e.code == 401:
            print("          API token tidak ditemukan. Pastikan BEBAS_API_TOKEN sudah benar.")
        elif e.code == 403:
            print("          API token tidak valid. Periksa token Anda di Dashboard.")
        elif e.code == 422:
            print(f"          Validasi gagal: {error_msg}")
        else:
            print(f"          Detail: {error_msg}")
        return None
    except urllib.error.URLError as e:
        print(f"\n  [ERROR] Tidak bisa menghubungi API server: {e.reason}")
        print(f"          Pastikan server berjalan di: {api_url}")
        return None
    except Exception as e:
        print(f"\n  [ERROR] Gagal mengirim ke API: {e}")
        return None


def print_report(result, git_info):
    """Cetak laporan ke terminal dengan format yang jelas."""
    sep = '-' * 60
    print(f"\n{sep}")
    print("  BEBAS Code Scanner - Laporan Scan")
    print(sep)
    print(f"  Repository : {git_info.get('repository', '-')}")
    print(f"  Branch     : {git_info.get('branch', '-')}")
    print(f"  Commit     : {git_info.get('commit_hash', '-')[:8] or '-'}")
    print(f"  File dipindai : {result['scanned_files']}")
    print(sep)

    if not result['issues']:
        print("  [OK] Tidak ada isu yang terdeteksi.")
        print(f"{sep}\n")
        return

    severity_label = {
        'critical': '[CRITICAL]',
        'warning' : '[WARNING ]',
        'info'    : '[INFO    ]',
    }

    for issue in result['issues']:
        label = severity_label.get(issue['severity'], '[INFO    ]')
        print(f"  {label} {issue['file']}:{issue['line']}")
        print(f"           {issue['message']}")
        print()

    print(sep)
    print(f"  Ringkasan : Critical={result['total_critical']}  Warning={result['total_warning']}  Info={result['total_info']}")
    print(f"  Status    : {'DIBLOKIR' if result['max_severity'] == 'critical' else 'PERHATIAN' if result['max_severity'] in ('warning','info') else 'AMAN'}")
    print(f"{sep}\n")


def fetch_pending_requests(base_api_url):
    """Ambil daftar scan request yang pending dari server."""
    token = os.environ.get('BEBAS_API_TOKEN', '')
    if not token:
        print("  [ERROR] BEBAS_API_TOKEN belum di-set! Tidak bisa mengambil pending requests.")
        return []
    
    # Derive base URL: ganti /scans -> /scan-requests/pending
    pending_url = base_api_url.rstrip('/').replace('/scans', '') + '/scan-requests/pending'
    req = urllib.request.Request(
        pending_url,
        headers={
            'Accept': 'application/json',
            'X-API-Token': token,
        },
        method='GET'
    )
    try:
        with urllib.request.urlopen(req, timeout=10) as response:
            data = json.loads(response.read().decode('utf-8'))
            return data.get('data', [])
    except urllib.error.HTTPError as e:
        if e.code == 401:
            print("  [ERROR] API token tidak valid. Periksa BEBAS_API_TOKEN.")
        elif e.code == 403:
            print("  [ERROR] API token ditolak. Periksa token Anda.")
        return []
    except (urllib.error.URLError, Exception) as e:
        return []


def run_setup(api_url):
    """Setup interaktif untuk scanner: register/login dan simpan token."""
    sep = '-' * 60
    print(f"\n{sep}")
    print("  BEBAS Code Scanner - Setup")
    print(sep)
    
    # Cek apakah token sudah ada
    existing = os.environ.get('BEBAS_API_TOKEN', '')
    if existing:
        print(f"  Token sudah di-set: {existing[:20]}...")
        confirm = input("  Ingin mengganti? (y/n): ").strip().lower()
        if confirm != 'y':
            print("  Setup dibatalkan.")
            return
    
    auth_url = api_url.rstrip('/').replace('/scans', '') + '/auth'
    
    print("\n  Pilih opsi:")
    print("  1. Login (sudah punya akun)")
    print("  2. Register (buat akun baru)")
    choice = input("  Pilihan (1/2): ").strip()
    
    if choice == '2':
        name = input("  Nama: ").strip()
        email = input("  Email: ").strip()
        password = input("  Password (min 8 karakter): ").strip()
        
        payload = json.dumps({'name': name, 'email': email, 'password': password}).encode('utf-8')
        req = urllib.request.Request(
            auth_url + '/register',
            data=payload,
            headers={'Content-Type': 'application/json', 'Accept': 'application/json'},
            method='POST'
        )
    else:
        email = input("  Email: ").strip()
        password = input("  Password: ").strip()
        
        payload = json.dumps({'email': email, 'password': password}).encode('utf-8')
        req = urllib.request.Request(
            auth_url + '/login',
            data=payload,
            headers={'Content-Type': 'application/json', 'Accept': 'application/json'},
            method='POST'
        )
    
    try:
        with urllib.request.urlopen(req, timeout=10) as response:
            data = json.loads(response.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        try:
            err = json.loads(e.read().decode('utf-8'))
            print(f"\n  [ERROR] {err.get('message', 'Gagal')}")
            if 'errors' in err:
                for field, msgs in err['errors'].items():
                    for m in msgs:
                        print(f"          - {field}: {m}")
        except Exception:
            print(f"\n  [ERROR] HTTP {e.code}")
        return
    except Exception as e:
        print(f"\n  [ERROR] Tidak bisa menghubungi server: {e}")
        return
    
    if data.get('success') and data.get('api_token'):
        token = data['api_token']
        print(f"\n  [OK] Berhasil! Token Anda:")
        print(f"  {token}")
        print(f"\n  Simpan token ini dengan cara:")
        print(f"  PowerShell : $env:BEBAS_API_TOKEN = '{token}'")
        print(f"  Bash/Linux : export BEBAS_API_TOKEN='{token}'")
        print(f"  Atau buat file .env di project Anda dengan isi:")
        print(f"  BEBAS_API_TOKEN={token}")
        print(f"\n  Atau jalankan scanner dengan: --token {token}")
        print(f"{sep}\n")
    else:
        print(f"\n  [ERROR] {data.get('message', 'Email atau password salah.')}")


def load_token_from_env_file(target_dir='.'):
    """Coba baca BEBAS_API_TOKEN dari file .bebas atau .env di target directory."""
    # Cek .bebas file dulu
    bebas_file = os.path.join(target_dir, '.bebas')
    if os.path.isfile(bebas_file):
        try:
            with open(bebas_file, 'r') as f:
                for line in f:
                    line = line.strip()
                    if line.startswith('BEBAS_API_TOKEN='):
                        return line.split('=', 1)[1].strip().strip('"').strip("'")
        except Exception:
            pass
    
    # Cek .env file
    env_file = os.path.join(target_dir, '.env')
    if os.path.isfile(env_file):
        try:
            with open(env_file, 'r') as f:
                for line in f:
                    line = line.strip()
                    if line.startswith('BEBAS_API_TOKEN='):
                        return line.split('=', 1)[1].strip().strip('"').strip("'")
        except Exception:
            pass
    
    return None


# ─────────────────────────────────────────────────────────────────────────────
# ENTRY POINT & TOOLS
# ─────────────────────────────────────────────────────────────────────────────

def install_git_hooks(target_dir):
    """Memasang git hook pre-commit dan pre-push ke dalam .git/hooks dengan absolute path."""
    import sys
    git_dir = os.path.join(target_dir, '.git')
    if not os.path.isdir(git_dir):
        print("[ERROR] Ini bukan repository git. Folder .git tidak ditemukan.")
        sys.exit(1)
        
    hooks_dir = os.path.join(git_dir, 'hooks')
    os.makedirs(hooks_dir, exist_ok=True)
    
    # Dapatkan path mutlak tempat scanner.py ini berada
    scanner_path = os.path.abspath(__file__)
    python_exe = sys.executable
    
    # Pre-commit hook
    pre_commit_path = os.path.join(hooks_dir, 'pre-commit')
    pre_commit_content = f'''#!/bin/sh
echo "[BEBAS Scanner] Memeriksa keamanan file sebelum commit..."
"{python_exe}" "{scanner_path}" --mode commit
if [ $? -ne 0 ]; then
    echo "[BEBAS Scanner] [X] COMMIT DITOLAK! Ada isu keamanan (critical) di kode yang akan di-commit."
    exit 1
fi
'''
    with open(pre_commit_path, 'w', newline='\n') as f:
        f.write(pre_commit_content)
        
    # Pre-push hook
    pre_push_path = os.path.join(hooks_dir, 'pre-push')
    pre_push_content = f'''#!/bin/sh
echo "[BEBAS Scanner] Memeriksa semua perubahan sebelum push ke server jarak jauh..."
"{python_exe}" "{scanner_path}" --mode push
if [ $? -ne 0 ]; then
    echo "[BEBAS Scanner] [X] PUSH DITOLAK! Ada kerentanan kritis / rahasia bocor! Cek dashboard untuk lognya."
    exit 1
fi
'''
    with open(pre_push_path, 'w', newline='\n') as f:
        f.write(pre_push_content)
        
    # Buat executable jika di sistem berbasis Unix (Mac/Linux)
    if os.name != 'nt':
        os.chmod(pre_commit_path, 0o755)
        os.chmod(pre_push_path, 0o755)
        
    print("[SUCCESS] Git Hooks Berhasil Terpasang!")
    print("  - pre-commit: Akan memblokir commit jika mendeteksi isu di file yang berubah.")
    print("  - pre-push: Akan memblokir push jika mendeteksi kebocoran dan menyimpan lognya.")

if __name__ == '__main__':
    parser = argparse.ArgumentParser(
        description='BEBAS Code Scanner - Scan kode untuk keamanan dan code smell',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Contoh penggunaan:
  # Scan semua file di project saat ini
  python scanner.py --mode all

  # Scan project di folder lain
  python scanner.py --mode all --target-dir /path/to/project

  # Scan tanpa kirim ke API
  python scanner.py --mode all --no-send

  # Scan dengan token langsung (tanpa env variable)
  python scanner.py --mode all --token bebas_xxxxx

  # Setup akun baru
  python scanner.py --setup

  # Mode polling (menunggu request scan dari user lain)
  python scanner.py --poll
        """
    )
    parser.add_argument('--mode', choices=['commit', 'push', 'all'], default='push',
                        help='commit: scan staged files | push: scan perubahan ke remote | all: scan semua file')
    parser.add_argument('--api-url', default='http://localhost:8000/api/v1/scans',
                        help='URL endpoint Laravel API (default: http://localhost:8000/api/v1/scans)')
    parser.add_argument('--repo-url', default=None,
                        help='URL publik repository (misal: https://github.com/user/repo)')
    parser.add_argument('--target-dir', default='.',
                        help='Direktori project yang akan di-scan (default: direktori saat ini)')
    parser.add_argument('--token', default=None,
                        help='API token langsung (alternatif dari environment variable BEBAS_API_TOKEN)')
    parser.add_argument('--poll', action='store_true',
                        help='Mode polling: tunggu & proses request scan masuk dari user lain')
    parser.add_argument('--poll-interval', type=int, default=30,
                        help='Interval polling dalam detik (default: 30)')
    parser.add_argument('--no-send', action='store_true',
                        help='Jangan kirim hasil ke API')
    parser.add_argument('--json', action='store_true',
                        help='Output format JSON saja')
    parser.add_argument('--files', nargs='*',
                        help='Scan file tertentu saja')
    parser.add_argument('--setup', action='store_true',
                        help='Setup interaktif: register/login dan dapatkan API token')
    parser.add_argument('--setup-hooks', action='store_true',
                        help='Membangun/memasang otomatis pemblokir Git Hooks (pre-commit & pre-push) ke folder project saat ini')
    args = parser.parse_args()


    # ─── Resolve target directory ───
    target_dir = os.path.abspath(args.target_dir)
    if not os.path.isdir(target_dir):
        print(f"[ERROR] Direktori tidak ditemukan: {target_dir}")
        sys.exit(1)

    # ─── MODE SETUP (Manual interaktif server) ───
    if args.setup:
        run_setup(args.api_url)
        sys.exit(0)

    # ─── Set token dari argumen atau file ───
    if args.token:
        os.environ['BEBAS_API_TOKEN'] = args.token
    elif not os.environ.get('BEBAS_API_TOKEN') and not args.no_send:
        file_token = load_token_from_env_file(target_dir)
        if file_token:
            os.environ['BEBAS_API_TOKEN'] = file_token
        else:
            if sys.stdin.isatty():
                try:
                    print(f"\n[INFO] Sistem mendeteksi project baru (Token belum disetel di {target_dir}).")
                    user_token = input("[KEY] Silakan paste BEBAS_API_TOKEN Anda di sini: ").strip()
                    if user_token:
                        bebas_file_path = os.path.join(target_dir, '.bebas')
                        with open(bebas_file_path, 'w') as f:
                            f.write(f"BEBAS_API_TOKEN={user_token}\n")
                        os.environ['BEBAS_API_TOKEN'] = user_token
                        print(f"✅ [SUCCESS] Token berhasil diamankan di file {bebas_file_path}!\n")
                    else:
                        print("[ERROR] Token tidak boleh kosong!")
                        sys.exit(1)
                except (EOFError, UnicodeEncodeError):
                    # Git hooks tidak bisa menerima input interaktif.
                    # Lanjutkan scan tanpa kirim ke API.
                    print("\n[WARNING] Tidak bisa menerima input (dalam mode git hook).")
                    print("          Scan tetap berjalan secara lokal (tanpa kirim ke API).")
                    print("          Untuk mengatur token, jalankan manual:")
                    print(f"          python \"{os.path.abspath(__file__)}\" --setup")
                    print(f"          atau buat file .bebas di {target_dir} berisi: BEBAS_API_TOKEN=token_anda\n")
                    args.no_send = True
            else:
                print("[ERROR] BEBAS_API_TOKEN tidak ditemukan (Coba sertakan file .bebas atau flag --token).")
                sys.exit(1)

    # ─── Setup Git Hooks Mode ───
    if args.setup_hooks:
        install_git_hooks(target_dir)
        sys.exit(0)

    # ─── MODE POLL: tunggu request dari user lain ───
    if args.poll:
        import time
        print(f"[POLL] Mode aktif. Interval: {args.poll_interval}s. Tekan Ctrl+C untuk berhenti.")
        print(f"[POLL] Menunggu request scan masuk untuk repo ini...")
        
        if not os.environ.get('BEBAS_API_TOKEN'):
            print("[ERROR] BEBAS_API_TOKEN belum di-set! Jalankan --setup terlebih dahulu.")
            sys.exit(1)
        
        while True:
            try:
                pending = fetch_pending_requests(args.api_url)
                if pending:
                    for req_item in pending:
                        print(f"\n[POLL] Request masuk dari user #{req_item.get('requester_user_id')} untuk: {req_item.get('repo_url')}")
                        try:
                            res = subprocess.run(['git', 'ls-files'], capture_output=True, text=True, check=True, cwd=target_dir)
                            files = [f.strip() for f in res.stdout.splitlines() if f.strip()]
                        except subprocess.CalledProcessError:
                            files = []

                        if not files:
                            print("[POLL] Tidak ada file untuk di-scan.")
                            continue

                        git_info = get_git_info(target_dir)
                        result = run_scan(files, mode='all', target_dir=target_dir)
                        print_report(result, git_info)

                        if not args.no_send:
                            api_response = send_to_api(
                                result, git_info, args.api_url,
                                repo_url=req_item.get('repo_url'),
                                scan_request_id=req_item.get('id'),
                            )
                            if api_response:
                                print(f"  [POLL] Scan terkirim. Scan ID: {api_response.get('scan_id', '-')}\n")
                else:
                    print(f"[POLL] Tidak ada request. Cek lagi dalam {args.poll_interval}s...", end='\r')
                time.sleep(args.poll_interval)
            except KeyboardInterrupt:
                print("\n[POLL] Dihentikan.")
                break
        sys.exit(0)

    # Tentukan file yang di-scan
    if args.files:
        files = args.files
    elif args.mode == 'all':
        try:
            res = subprocess.run(['git', 'ls-files'], capture_output=True, text=True, check=True, cwd=target_dir)
            files = [f.strip() for f in res.stdout.splitlines() if f.strip()]
        except subprocess.CalledProcessError:
            # Fallback: scan all files recursively if not a git repo
            files = []
            for root, dirs, filenames in os.walk(target_dir):
                # Remove ignored dirs
                dirs[:] = [d for d in dirs if d not in IGNORED_DIRS]
                for filename in filenames:
                    rel_path = os.path.relpath(os.path.join(root, filename), target_dir).replace('\\', '/')
                    files.append(rel_path)
    else:
        files = get_changed_files(mode=args.mode, target_dir=target_dir)

    if not files:
        if not args.json:
            print("[INFO] Tidak ada file yang perlu di-scan.")
            print("       Tips: Gunakan --mode all untuk scan semua file.")
        sys.exit(0)

    git_info = get_git_info(target_dir)
    result = run_scan(files, mode=args.mode, target_dir=target_dir)

    if args.json:
        output = {**git_info, **result}
        print(json.dumps(output, indent=2))
    else:
        print_report(result, git_info)

    # Kirim ke API jika tidak di-skip
    if not args.no_send:
        api_response = send_to_api(
            result, git_info, args.api_url,
            repo_url=args.repo_url,
        )
        if api_response and not args.json:
            print(f"  [API] Hasil scan terkirim. Scan ID: {api_response.get('scan_id', '-')}\n")
        elif not api_response and not args.json:
            print("  [API] Gagal mengirim hasil scan. Gunakan --no-send untuk skip.\n")

    # Exit code: 1 jika ada critical issue (blokir push/commit)
    if result['max_severity'] == 'critical':
        sys.exit(1)

    sys.exit(0)
