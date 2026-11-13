<?php
/* =========================================================
   MURAD AI — server proxy
   Keeps your API key secret on the server and forwards chat
   requests to a free AI provider. If one provider or model
   fails (limit reached, model removed), it tries the next.
   ========================================================= */
declare(strict_types=1);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) out(500, ['error' => 'config.php is missing on the server.']);
$config = require $configFile;

$PROVIDERS = [
    'groq'       => 'https://api.groq.com/openai/v1/chat/completions',
    'gemini'     => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
    'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
];

function key_for(array $config, string $p): string {
    $k = trim((string)($config['api_keys'][$p] ?? ''));
    return (stripos($k, 'PASTE') !== false) ? '' : $k;
}

/* ---------- GET: health check ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $have = [];
    foreach (($config['order'] ?? []) as $p) if (key_for($config, $p) !== '') $have[] = $p;
    out(200, [
        'ok' => true, 'name' => 'Murad AI',
        'providers' => $have,
        'curl' => function_exists('curl_init'),
        'passcode_required' => ($config['passcode'] ?? '') !== '',
    ]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['error' => 'Method not allowed']);
if (!function_exists('curl_init')) out(500, ['error' => 'PHP cURL is not available on this server.']);

/* ---------- read & validate ---------- */
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') out(400, ['error' => 'Empty request (the message may be too large for this server).']);
if (strlen($raw) > 12 * 1024 * 1024) out(413, ['error' => 'Request too large. Try fewer or smaller files.']);
$in = json_decode($raw, true);
if (!is_array($in) || !isset($in['messages']) || !is_array($in['messages'])) out(400, ['error' => 'Bad request.']);

$pass = (string)($config['passcode'] ?? '');
if ($pass !== '' && !hash_equals($pass, (string)($in['passcode'] ?? ''))) {
    out(401, ['error' => 'Wrong or missing passcode. Open Settings and enter the passcode.']);
}

$messages = [];
$hasImages = false;
foreach (array_slice($in['messages'], -40) as $m) {
    if (!is_array($m)) continue;
    $role = $m['role'] ?? '';
    if (!in_array($role, ['system', 'user', 'assistant'], true)) continue;
    $c = $m['content'] ?? '';
    if (is_string($c)) {
        $messages[] = ['role' => $role, 'content' => mb_substr($c, 0, 400000)];
    } elseif (is_array($c) && $role === 'user') {
        $parts = [];
        foreach ($c as $part) {
            if (!is_array($part)) continue;
            if (($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) {
                $parts[] = ['type' => 'text', 'text' => mb_substr($part['text'], 0, 400000)];
            } elseif (($part['type'] ?? '') === 'image_url') {
                $url = (string)($part['image_url']['url'] ?? '');
                if (preg_match('#^data:image/(jpeg|png|webp|gif);base64,[A-Za-z0-9+/=]+$#', $url)) {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
                    $hasImages = true;
                }
            }
        }
        if ($parts) $messages[] = ['role' => 'user', 'content' => $parts];
    }
}
if (!$messages) out(400, ['error' => 'No messages.']);

/* ---------- simple per-visitor rate limit ---------- */
$limit = (int)($config['max_requests_per_hour'] ?? 0);
if ($limit > 0) {
    $dir = __DIR__ . '/.ratelimit';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n"); }
    $file = $dir . '/' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'x')) . '.json';
    $now = time();
    $hits = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
    $hits = array_values(array_filter($hits, fn($t) => is_int($t) && $t > $now - 3600));
    if (count($hits) >= $limit) out(429, ['error' => 'Too many messages this hour. Please try again a little later.']);
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    if (mt_rand(1, 50) === 1) foreach ((glob($dir . '/*.json') ?: []) as $f) if (@filemtime($f) < $now - 7200) @unlink($f);
}

/* ---------- build the list of (provider, model) to try ---------- */
$kind = $hasImages ? 'vision_models' : 'models';
$candidates = [];
foreach (($config['order'] ?? array_keys($PROVIDERS)) as $p) {
    if (!isset($PROVIDERS[$p]) || key_for($config, $p) === '') continue;
    foreach ((array)($config[$kind][$p] ?? []) as $model) $candidates[] = [$p, (string)$model];
}
if (!$candidates) {
    out(500, ['error' => $hasImages
        ? 'No vision model is set up on the server, so images and scanned PDFs cannot be read yet. Add a Gemini or Groq key in config.php.'
        : 'No API key is set on the server yet. Open config.php and paste your free key.']);
}

/* ---------- call providers with failover ---------- */
$lastError = 'Unknown error';
foreach ($candidates as [$p, $model]) {
    $payload = ['model' => $model, 'messages' => $messages, 'temperature' => 0.7, 'max_tokens' => (int)($config['max_tokens'] ?? 4096)];
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . key_for($config, $p)];
    if ($p === 'openrouter') { $headers[] = 'HTTP-Referer: https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'); $headers[] = 'X-Title: Murad AI'; }

    $ch = curl_init($PROVIDERS[$p]);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 55,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($body === false) { $lastError = "$p: could not connect ($cerr)"; continue; }
    $j = json_decode((string)$body, true);
    if ($status === 200 && is_array($j)) {
        $text = $j['choices'][0]['message']['content'] ?? '';
        if (is_array($text)) { $t = ''; foreach ($text as $part) $t .= is_array($part) ? ($part['text'] ?? '') : (string)$part; $text = $t; }
        if (is_string($text) && trim($text) !== '') out(200, ['reply' => $text, 'model' => $model]);
        $lastError = "$p/$model returned an empty answer";
        continue;
    }
    $msg = '';
    if (is_array($j)) $msg = (string)($j['error']['message'] ?? ($j[0]['error']['message'] ?? ''));
    $lastError = "$p/$model: " . ($msg !== '' ? mb_substr($msg, 0, 300) : "HTTP $status");
    // any failure -> try the next model/provider
}

$friendly = (stripos($lastError, 'rate') !== false || stripos($lastError, 'quota') !== false || stripos($lastError, '429') !== false)
    ? 'The free limit is used up for the moment. Wait a minute and try again.'
    : 'The AI service could not answer right now.';
out(502, ['error' => $friendly . ' (' . $lastError . ')']);
