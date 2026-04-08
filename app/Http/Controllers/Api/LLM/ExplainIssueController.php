<?php

namespace App\Http\Controllers\Api\LLM;

use App\Http\Controllers\Controller;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ExplainIssueController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'scan_id'     => 'required|exists:scans,id',
            'issue_index' => 'nullable|integer|min:0',
            'question'    => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $scan   = Scan::findOrFail($request->scan_id);
        $issues = $scan->issues ?? [];

        if ($request->has('issue_index') && isset($issues[$request->issue_index])) {
            $targetIssues = [$issues[$request->issue_index]];
        } else {
            $targetIssues = array_slice($issues, 0, 5);
        }

        $prompt = $this->buildPrompt($targetIssues, $scan, $request->question);
        $aiResponse = $this->callGemini($prompt);

        if ($aiResponse === null) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghubungi AI. Pastikan GEMINI_API_KEY sudah diset.',
            ], 503);
        }

        return response()->json([
            'success'      => true,
            'scan_id'      => $scan->id,
            'explanation'  => $aiResponse,
        ]);
    }

    private function buildPrompt(array $issues, Scan $scan, ?string $userQuestion): string
    {
        $issueText = '';
        foreach ($issues as $i => $issue) {
            $issueText .= "\n" . ($i + 1) . ". File: {$issue['file']}, Baris: {$issue['line']}, "
                . "Severity: {$issue['severity']}, Tipe: {$issue['type']}, Pesan: {$issue['message']}";
        }

        $question = $userQuestion
            ? "Pertanyaan developer: {$userQuestion}"
            : "Jelaskan masalah ini dan berikan rekomendasi perbaikannya.";

        return <<<PROMPT
Kamu adalah asisten keamanan kode yang membantu developer memperbaiki masalah pada kode mereka.

Berikut adalah hasil scan kode dari repository "{$scan->repository}" branch "{$scan->branch}":
{$issueText}

{$question}

Berikan penjelasan dalam bahasa Indonesia yang mudah dipahami, sertakan:
1. Penjelasan singkat mengapa ini berbahaya atau bermasalah
2. Contoh kode yang salah (jika relevan)
3. Cara memperbaikinya dengan contoh kode yang benar
4. Tips pencegahan ke depannya

Format jawaban dengan rapi menggunakan markdown.
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
                    'temperature'     => 0.3,
                    'maxOutputTokens' => 1024,
                ],
            ]
        );

        if ($response->successful()) {
            return data_get($response->json(), 'candidates.0.content.parts.0.text');
        }

        return null;
    }
}
