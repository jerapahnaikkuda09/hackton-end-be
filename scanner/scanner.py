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

def run_gitleaks_on_files(files):
    """Menjalankan Gitleaks dan mengembalikan daftar isu rahasia (secrets)."""
    issues = []
    
    # Path file sementara untuk hasil gitleaks
    report_file = "gitleaks_report_temp.json"
    
    # Pastikan file report lama tidak ada
    if os.path.exists(report_file):
        os.remove(report_file)
        
    try:
        # Menjalankan gitleaks untuk mendeteksi rahasia (tanpa history git, full repo)
        # Akan membutuhkan waktu beberapa milidetik - detik tergantung ukuran repo (yang bukan vendor)
        subprocess.run(
            ['gitleaks', 'detect', '--no-git', '--report-path', report_file, '--report-format', 'json', '--exit-code', '0'],
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
                # Hanya simpan issue jika file tersebut ada di target dipindai (perubahan saat ini)
                leak_file = leak.get('File', '').replace('\\', '/')
                if leak_file in normalized_files:
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

def get_changed_files(mode='push'):
    """Dapatkan daftar file yang berubah dari git."""
    try:
        if mode == 'commit':
            # File yang di-staging untuk commit
            result = subprocess.run(
                ['git', 'diff', '--cached', '--name-only', '--diff-filter=ACMR'],
                capture_output=True, text=True, check=True
            )
        else:
            # File yang berbeda dengan remote (untuk push)
            result = subprocess.run(
                ['git', 'diff', '--name-only', '--diff-filter=ACMR', 'HEAD@{upstream}..HEAD'],
                capture_output=True, text=True
            )
            if result.returncode != 0 or not result.stdout.strip():
                # Fallback: semua file yang berubah dari commit terakhir
                result = subprocess.run(
                    ['git', 'diff', '--name-only', '--diff-filter=ACMR', 'HEAD~1..HEAD'],
                    capture_output=True, text=True
                )
                if result.returncode != 0 or not result.stdout.strip():
                    # Fallback terakhir: scan semua file yang di-track git
                    result = subprocess.run(
                        ['git', 'ls-files'],
                        capture_output=True, text=True, check=True
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

    # Cek ekstensi
    _, ext = os.path.splitext(filepath)
    if ext.lower() in SCAN_EXTENSIONS:
        return True

    # File tanpa ekstensi (misal .env)
    basename = os.path.basename(filepath)
    if basename.startswith('.env'):
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


def run_scan(files, mode='push'):
    """Jalankan scan pada semua file."""
    all_issues = []
    scanned_count = 0
    
    # Jalankan Gitleaks
    print("  [INFO] Menjalankan Gitleaks untuk memeriksa kebocoran rahasia...")
    all_issues.extend(run_gitleaks_on_files(files))

    for filepath in files:
        if not should_scan_file(filepath):
            continue

        if not os.path.isfile(filepath):
            continue

        try:
            with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
                content = f.read()
                lines = content.splitlines()
        except (OSError, IOError):
            continue

        scanned_count += 1
        all_issues.extend(scan_file_for_conflicts(filepath, content))

        # Code smell hanya saat push (bukan tiap commit)
        if mode == 'push':
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


def get_git_info():
    """Ambil info git (repo, branch, commit hash)."""
    def run(cmd):
        try:
            r = subprocess.run(cmd, capture_output=True, text=True)
            return r.stdout.strip() if r.returncode == 0 else ''
        except FileNotFoundError:
            return ''

    return {
        'repository': run(['git', 'rev-parse', '--show-toplevel']).split('/')[-1].split('\\')[-1],
        'branch': run(['git', 'rev-parse', '--abbrev-ref', 'HEAD']),
        'commit_hash': run(['git', 'rev-parse', 'HEAD']),
    }


def send_to_api(result, git_info, api_url, repo_url=None, scan_request_id=None):
    """Kirim hasil scan ke Laravel API."""
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
            'X-API-Token': os.environ.get('BEBAS_API_TOKEN', ''),
        },
        method='POST'
    )

    try:
        with urllib.request.urlopen(req, timeout=5) as response:
            return json.loads(response.read().decode('utf-8'))
    except (urllib.error.URLError, urllib.error.HTTPError, Exception):
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
    # Derive base URL: ganti /scans -> /scan-requests/pending
    pending_url = base_api_url.rstrip('/').replace('/scans', '') + '/scan-requests/pending'
    req = urllib.request.Request(
        pending_url,
        headers={
            'Accept': 'application/json',
            'X-API-Token': os.environ.get('BEBAS_API_TOKEN', ''),
        },
        method='GET'
    )
    try:
        with urllib.request.urlopen(req, timeout=10) as response:
            data = json.loads(response.read().decode('utf-8'))
            return data.get('data', [])
    except (urllib.error.URLError, urllib.error.HTTPError, Exception):
        return []


# ─────────────────────────────────────────────────────────────────────────────
# ENTRY POINT
# ─────────────────────────────────────────────────────────────────────────────

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='BEBAS Code Scanner')
    parser.add_argument('--mode', choices=['commit', 'push', 'all'], default='push',
                        help='commit: scan staged files | push: scan perubahan ke remote | all: scan semua file')
    parser.add_argument('--api-url', default='http://localhost:8000/api/v1/scans',
                        help='URL endpoint Laravel API')
    parser.add_argument('--repo-url', default=None,
                        help='URL publik repository (misal: https://github.com/user/repo)')
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
    args = parser.parse_args()

    # ─── MODE POLL: tunggu request dari user lain ───
    if args.poll:
        import time
        print(f"[POLL] Mode aktif. Interval: {args.poll_interval}s. Tekan Ctrl+C untuk berhenti.")
        print(f"[POLL] Menunggu request scan masuk untuk repo ini...")
        while True:
            pending = fetch_pending_requests(args.api_url)
            if pending:
                for req in pending:
                    print(f"\n[POLL] Request masuk dari user #{req.get('requester_user_id')} untuk: {req.get('repo_url')}")
                    try:
                        res = subprocess.run(['git', 'ls-files'], capture_output=True, text=True, check=True)
                        files = [f.strip() for f in res.stdout.splitlines() if f.strip()]
                    except subprocess.CalledProcessError:
                        files = []

                    if not files:
                        print("[POLL] Tidak ada file untuk di-scan.")
                        continue

                    git_info = get_git_info()
                    result = run_scan(files, mode='all')
                    print_report(result, git_info)

                    if not args.no_send:
                        api_response = send_to_api(
                            result, git_info, args.api_url,
                            repo_url=req.get('repo_url'),
                            scan_request_id=req.get('id'),
                        )
                        if api_response:
                            print(f"  [POLL] Scan terkirim. Scan ID: {api_response.get('scan_id', '-')}\n")
            else:
                print(f"[POLL] Tidak ada request. Cek lagi dalam {args.poll_interval}s...", end='\r')
            time.sleep(args.poll_interval)
        sys.exit(0)

    # Tentukan file yang di-scan
    if args.files:
        files = args.files
    elif args.mode == 'all':
        try:
            res = subprocess.run(['git', 'ls-files'], capture_output=True, text=True, check=True)
            files = [f.strip() for f in res.stdout.splitlines() if f.strip()]
        except subprocess.CalledProcessError:
            files = []
    else:
        files = get_changed_files(mode=args.mode)

    if not files:
        if not args.json:
            print("[INFO] Tidak ada file yang perlu di-scan.")
        sys.exit(0)

    git_info = get_git_info()
    result = run_scan(files, mode=args.mode)

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

    # Exit code: 1 jika ada critical issue (blokir push/commit)
    if result['max_severity'] == 'critical':
        sys.exit(1)

    sys.exit(0)
