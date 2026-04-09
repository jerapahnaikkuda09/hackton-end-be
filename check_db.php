<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Users ===" . PHP_EOL;
foreach (\App\Models\User::all() as $u) {
    echo "User #{$u->id}: {$u->name} | token: {$u->api_token}" . PHP_EOL;
}

echo PHP_EOL . "=== Scans per User ===" . PHP_EOL;
$scans = \App\Models\Scan::selectRaw('user_id, count(*) as cnt')->groupBy('user_id')->get();
foreach ($scans as $s) {
    echo "user_id: {$s->user_id} => scans: {$s->cnt}" . PHP_EOL;
}

echo PHP_EOL . "=== Latest 5 Scans ===" . PHP_EOL;
foreach (\App\Models\Scan::latest()->take(5)->get() as $s) {
    echo "Scan #{$s->id} | user_id: {$s->user_id} | repo: {$s->repository} | source: {$s->source} | issues_count: " . count($s->issues ?? []) . " | created: {$s->created_at}" . PHP_EOL;
}
