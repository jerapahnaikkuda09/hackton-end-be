<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Project;
use Illuminate\Support\Str; // Tambahkan ini untuk generate string acak

class ProjectController extends Controller
{
    //
    public function store(Request $request)
    {
        // 1. Validasi input dari Frontend
        $request->validate([
            'repo_name' => 'required|string|unique:projects,repo_name'
        ]);

        // 2. Generate Project Token acak (misal: bebas_9a8b7c6d5e...)
        $token = 'bebas_' . Str::random(24);

        // 3. Simpan ke Database
        $project = Project::create([
            'repo_name' => $request->repo_name,
            'project_token' => $token,
        ]);

        // 4. Kembalikan data token ke Frontend untuk ditampilkan ke User
        return response()->json([
            'message' => 'Project berhasil didaftarkan!',
            'data' => $project
        ], 201);
    }
}
