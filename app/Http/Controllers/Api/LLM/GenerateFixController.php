<?php

namespace App\Http\Controllers\Api\LLM;

use App\Http\Controllers\Controller;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class GenerateFixController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'scan_id'     => 'required|exists:scans,id',
            'issue_index' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $scan = Scan::findOrFail($request->scan_id);
        $issues = $scan->issues ?? [];

        if (!isset($issues[$request->issue_index])) {
            return response()->json([
                'success' => false,
                'message' => 'Isu tidak ditemukan pada index tersebut.',
            ], 404);
        }

        $issue = $issues[$request->issue_index];

        // 1. Minta saran perbaikan spesifik ke AI (hanya kode baris yang di-suggest)
        $prompt = $this->buildPrompt($issue, $scan);
        $suggestion = $this->callGemini($prompt);

        if ($suggestion === null) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mendapatkan perbaikan dari AI. Pastikan GEMINI_API_KEY sudah diset.',
            ], 503);
        }

        // 2. Kirim ke Github Review Comment (jika dari Github PR)
        $githubResponse = false;
        if ($scan->source === 'github_action' && $scan->pr_number && $scan->commit_hash) {
            $githubResponse = $this->postReviewCommentToGithub($scan, $issue, $suggestion);
        }

        return response()->json([
            'success'          => true,
            'issue_file'       => $issue['file'],
            'issue_line'       => $issue['line'],
            'ai_suggestion'    => $suggestion,
            'github_commented' => $githubResponse,
        ]);
    }

    private function buildPrompt(array $issue, Scan $scan): string
    {
        return <<<PROMPT
        Kamu adalah asisten keamanan kode (DevSecOps) yang membantu developer. Terdapat masalah keamanan pada kode berikut:
        File: {$issue['file']}
        Baris: {$issue['line']}
        Pesan Masalah: {$issue['message']}
        Repository: {$scan->repository}
        Branch: {$scan->branch}

        Tugasmu HANYALAH memberikan blok kode perbaikan menggunakan format Markdown 'suggestion' GitHub. 
        Perbaiki isu tersebut, pastikan kode berfungsi dan aman.
        Jangan berikan penjelasan, intro, ataupun penutup. Hanya blok berikut:

        ```suggestion
        // kode yang sudah diperbaiki di sini
        ```
        PROMPT;
    }

    private function callGemini(string $prompt): ?string
    {
        $apiKey = config('services.gemini.key');
        if (!$apiKey) {
            return null;
        }

        $response = Http::post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}",
            [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature'     => 0.1, // Temperatur rendah karena kita butuh jawaban statis (kode)
                    'maxOutputTokens' => 1024,
                ],
            ]
        );

        if ($response->successful()) {
            return data_get($response->json(), 'candidates.0.content.parts.0.text');
        }

        return null;
    }

    private function postReviewCommentToGithub(Scan $scan, array $issue, string $suggestion): bool
    {
        $token = config('services.github.token');
        if (!$token) {
            return false;
        }

        $repository = $scan->repository;
        $prNumber   = $scan->pr_number;

        // API GitHub untuk post pull request review comment
        $url = "https://api.github.com/repos/{$repository}/pulls/{$prNumber}/comments";

        $response = Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/vnd.github.v3+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->post($url, [
                'body'      => $suggestion . "\n\n> *✨ Dihasilkan otomatis menggunakan AI oleh BEBAS Code Scanner*",
                'commit_id' => $scan->commit_hash,
                'path'      => $issue['file'],
                'line'      => (int) $issue['line'],
                'side'      => 'RIGHT',
            ]);

        return $response->successful();
    }
}
