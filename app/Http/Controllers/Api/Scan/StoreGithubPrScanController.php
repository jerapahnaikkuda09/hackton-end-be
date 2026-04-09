<?php

namespace App\Http\Controllers\Api\Scan;

use App\Http\Controllers\Controller;
use App\Models\PrComment;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class StoreGithubPrScanController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'repository'  => 'required|string',
            'pr_number'   => 'required|integer',
            'branch'      => 'nullable|string',
            'commit_hash' => 'nullable|string',
            'issues'      => 'present|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $issues        = $request->issues;
        $totalCritical = collect($issues)->where('severity', 'critical')->count();
        $totalWarning  = collect($issues)->where('severity', 'warning')->count();
        $totalInfo     = collect($issues)->where('severity', 'info')->count();

        $maxSeverity = 'none';
        if ($totalCritical > 0) {
            $maxSeverity = 'critical';
        } elseif ($totalWarning > 0) {
            $maxSeverity = 'warning';
        } elseif ($totalInfo > 0) {
            $maxSeverity = 'info';
        }

        $scan = Scan::create([
            'user_id'        => auth()->id(),
            'repository'     => $request->repository,
            'branch'         => $request->branch,
            'commit_hash'    => $request->commit_hash,
            'source'         => 'github_action',
            'pr_number'      => $request->pr_number,
            'issues'         => $issues,
            'total_critical' => $totalCritical,
            'total_warning'  => $totalWarning,
            'total_info'     => $totalInfo,
            'max_severity'   => $maxSeverity,
            'blocked'        => false,
        ]);

        // Post komentar ke GitHub PR
        $commentBody = $this->buildPrComment($scan, $issues);
        $githubCommentId = $this->postGithubComment(
            $request->repository,
            $request->pr_number,
            $commentBody
        );

        $prComment = PrComment::create([
            'scan_id'           => $scan->id,
            'pr_number'         => $request->pr_number,
            'repository'        => $request->repository,
            'comment_body'      => $commentBody,
            'github_comment_id' => $githubCommentId,
            'status'            => $githubCommentId ? 'posted' : 'failed',
        ]);

        return response()->json([
            'success'           => true,
            'scan_id'           => $scan->id,
            'pr_comment_id'     => $prComment->id,
            'github_comment_id' => $githubCommentId,
            'max_severity'      => $maxSeverity,
        ], 201);
    }

    private function buildPrComment(Scan $scan, array $issues): string
    {
        $emoji = match ($scan->max_severity) {
            'critical' => '🔴',
            'warning'  => '🟡',
            'info'     => '🔵',
            default    => '✅',
        };

        $lines = [
            "## {$emoji} Hasil Scan Kode Otomatis",
            "",
            "| Severity | Jumlah |",
            "|---|---|",
            "| 🔴 Critical | {$scan->total_critical} |",
            "| 🟡 Warning  | {$scan->total_warning} |",
            "| 🔵 Info     | {$scan->total_info} |",
            "",
        ];

        if (count($issues) > 0) {
            $lines[] = "### Detail Isu";
            $lines[] = "";
            foreach (array_slice($issues, 0, 10) as $issue) {
                $lines[] = "- **[{$issue['severity']}]** `{$issue['file']}` baris {$issue['line']}: {$issue['message']}";
            }
            if (count($issues) > 10) {
                $remainder = count($issues) - 10;
                $lines[] = "- *...dan {$remainder} isu lainnya. Lihat dashboard untuk detail lengkap.*";
            }
        } else {
            $lines[] = "✅ Tidak ada isu yang terdeteksi.";
        }

        $lines[] = "";
        $lines[] = "> *Scan dilakukan otomatis oleh sistem BEBAS Code Scanner.*";

        return implode("\n", $lines);
    }

    private function postGithubComment(string $repository, int $prNumber, string $body): ?string
    {
        $token = config('services.github.token');
        if (!$token) {
            return null;
        }

        $response = Http::withToken($token)
            ->post("https://api.github.com/repos/{$repository}/issues/{$prNumber}/comments", [
                'body' => $body,
            ]);

        if ($response->successful()) {
            return (string) $response->json('id');
        }

        return null;
    }
}
