<?php

namespace App\Http\Controllers\Api\LLM;

use App\Http\Controllers\Controller;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class AskIssueController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'scan_id' => 'required|exists:scans,id',
            'question' => 'required|string|max:1000',
            'issue_index' => 'nullable|integer|min:0',
            'history' => 'nullable|array',
            'history.*.role' => 'required_with:history|in:user,model',
            'history.*.text' => 'required_with:history|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $scan = Scan::findOrFail($request->scan_id);
        $issues = $scan->issues ?? [];

        // Konteks: spesifik 1 issue atau semua (max 10)
        $contextIssues = $request->filled('issue_index') && isset($issues[$request->issue_index])
            ? [$issues[$request->issue_index]]
            : array_slice($issues, 0, 10);

        $systemContext = $this->buildSystemContext($scan, $contextIssues);
        $contents = $this->buildContents($request->history ?? [], $request->question);

        try {
            $answer = $this->callGemini($systemContext, $contents);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses dengan AI: ' . $e->getMessage()
            ], 503);
        }

        return response()->json([
            'success' => true,
            'scan_id' => $scan->id,
            'question' => $request->question,
            'answer' => $answer,
            'history' => array_merge(
                $request->history ?? [],
                [
                    ['role' => 'user', 'text' => $request->question],
                    ['role' => 'model', 'text' => $answer],
                ]
            ),
        ]);
    }

    private function buildSystemContext(Scan $scan, array $issues): string
    {
        $issueLines = '';
        foreach ($issues as $i => $issue) {
            $issueLines .= "\n  " . ($i + 1) . ". [{$issue['severity']}] {$issue['file']}:{$issue['line']} "
                . "- {$issue['type']}: {$issue['message']}";
        }

        $blocked = $scan->blocked ? 'YA' : 'TIDAK';

        return <<<CONTEXT
        Kamu adalah asisten DevSecOps bernama BEBAS AI.
        
        Konteks scan yang sedang dibahas:
        - Repository : {$scan->repository}
        - Branch     : {$scan->branch}
        - Commit     : {$scan->commit_hash}
        - Total isu  : Critical={$scan->total_critical}, Warning={$scan->total_warning}, Info={$scan->total_info}
        - Diblokir   : {$blocked}
        
        Daftar isu:{$issueLines}
        
        Jawab dalam Bahasa Indonesia, gunakan Markdown, berikan contoh kode jika ditanya perbaikan.
        CONTEXT;
    }

    /**
     * Membangun array contents untuk Gemini API
     */
    private function buildContents(array $history, string $question): array
    {
        $contents = [];

        // Riwayat percakapan sebelumnya
        foreach ($history as $msg) {
            $role = $msg['role'] === 'model' ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $msg['text']]]
            ];
        }

        // Pertanyaan terbaru
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $question]]
        ];

        return $contents;
    }

    /**
     * Memanggil API Gemini langsung (menggunakan GEMINI_API_KEY yang sudah ada)
     */
    private function callGemini(string $systemContext, array $contents): string
    {
        $apiKey = env('GEMINI_API_KEY');
        if (!$apiKey) {
            throw new \Exception('GEMINI_API_KEY tidak dikonfigurasi di file .env server Anda.');
        }

        $response = Http::timeout(60)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key={$apiKey}", [
                'system_instruction' => [
                    'parts' => [['text' => $systemContext]]
                ],
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => 0.4,
                    'maxOutputTokens' => 2048,
                ]
            ]);

        if (!$response->successful()) {
            $errorMsg = data_get($response->json(), 'error.message', 'Unknown error dari API Gemini');
            throw new \Exception($errorMsg);
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if ($text === null) {
            throw new \Exception('Respon tidak valid dari API Gemini');
        }

        return $text;
    }
}