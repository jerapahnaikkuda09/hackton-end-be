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
            'scan_id'        => 'required|exists:scans,id',
            'question'       => 'required|string|max:1000',
            'issue_index'    => 'nullable|integer|min:0',
            'history'        => 'nullable|array',
            'history.*.role' => 'required_with:history|in:user,model',
            'history.*.text' => 'required_with:history|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $scan   = Scan::findOrFail($request->scan_id);
        $issues = $scan->issues ?? [];

        // Konteks: spesifik 1 issue atau semua (max 10)
        $contextIssues = $request->filled('issue_index') && isset($issues[$request->issue_index])
            ? [$issues[$request->issue_index]]
            : array_slice($issues, 0, 10);

        $systemContext = $this->buildSystemContext($scan, $contextIssues);
        $contents      = $this->buildContents($systemContext, $request->history ?? [], $request->question);
        $answer        = $this->callGemini($contents);

        if ($answer === null) {
            return response()->json(['success' => false, 'message' => 'Gagal menghubungi Gemini AI.'], 503);
        }

        return response()->json([
            'success'  => true,
            'scan_id'  => $scan->id,
            'question' => $request->question,
            'answer'   => $answer,
            // Dikembalikan ke frontend agar bisa lanjut multi-turn
            'history'  => array_merge(
                $request->history ?? [],
                [
                    ['role' => 'user',  'text' => $request->question],
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

    private function buildContents(string $systemContext, array $history, string $question): array
    {
        // Injeksi konteks sebagai turn pertama
        $contents = [
            ['role' => 'user',  'parts' => [['text' => $systemContext]]],
            ['role' => 'model', 'parts' => [['text' => 'Siap membantu menganalisis scan ini.']]],
        ];

        // Riwayat percakapan sebelumnya
        foreach ($history as $msg) {
            $contents[] = ['role' => $msg['role'], 'parts' => [['text' => $msg['text']]]];
        }

        // Pertanyaan terbaru
        $contents[] = ['role' => 'user', 'parts' => [['text' => $question]]];

        return $contents;
    }

    private function callGemini(array $contents): ?string
    {
        $apiKey = config('services.gemini.key');
        if (!$apiKey) return null;

        $response = Http::timeout(30)->post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}",
            [
                'contents'         => $contents,
                'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 2048],
            ]
        );

        return $response->successful()
            ? data_get($response->json(), 'candidates.0.content.parts.0.text')
            : null;
    }
}