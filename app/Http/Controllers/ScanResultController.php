<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Scan;


class ScanResultController extends Controller
{
    //

    public function store(Request $request)
        {
            // ==========================================
            // 1. CEK KEAMANAN (VALIDASI TOKEN)
            // ==========================================
            $request->validate([
                'project_token' => 'required|string'
            ]);

        // Cari project berdasarkan token yang dikirim
            $project = \App\Models\Project::where('project_token', $request->project_token)->first();

            // Jika token ngawur / tidak ada di database, tolak permintaannya!
            if (!$project) {
                return response()->json([
                    'message' => 'Akses Ditolak: Project Token tidak valid!'
                ], 401);
            }

            // Sesuaikan validasi dengan struktur tabel 'scans' buatanmu
        // ==========================================
        // 2. VALIDASI DATA HASIL SCAN
        // ==========================================
        $validated = $request->validate([
            'repository' => 'nullable|string',
            'branch' => 'nullable|string',
            'commit_hash' => 'nullable|string',
            'source' => 'required|in:local,github_action',
            'pr_number' => 'nullable|integer',
            'issues' => 'nullable|array',
            'total_critical' => 'integer',
            'total_warning' => 'integer',
            'total_info' => 'integer',
            'max_severity' => 'required|in:none,info,warning,critical',
            'blocked' => 'boolean',
        ]);

        // Sisipkan 'project_id' yang didapat dari pengecekan token di atas
        $validated['project_id'] = $project->id;

            // Simpan ke database
            $scan = Scan::create($validated);

            return response()->json([
                'message' => 'Hasil scan BEBAS berhasil dicatat untuk project: ' . $project->repo_name,
                'data' => $scan
            ], 201);
        }

    public function index()
    {
        // Ambil 10 data scan terbaru
        $scans = Scan::orderBy('created_at', 'desc')->take(10)->get();
        
        return response()->json([
            'message' => 'Berhasil mengambil riwayat scan',
            'data' => $scans
        ], 200);
    }


    // Fungsi untuk mengambil statistik (Widget Angka & Grafik Dashboard)
    public function stats()
    {
        $total_scans = Scan::count();
        $blocked_scans = Scan::where('blocked', true)->count();
        $total_critical = Scan::sum('total_critical');

        return response()->json([
            'message' => 'Berhasil mengambil statistik',
            'data' => [
                'total_scans' => $total_scans,
                'blocked_scans' => $blocked_scans,
                'total_critical' => (int) $total_critical,
            ]
        ], 200);
    }



}
