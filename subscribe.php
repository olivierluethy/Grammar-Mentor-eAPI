<?php
require_once __DIR__ . '/vendor/autoload.php';

use OpenAI;

// ============================================================================
// CORS – IMPORTANT: must be set BEFORE any output
// ============================================================================
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Trusted production origins (add www. variant if needed)
$trusted_origins = [
    'https://grammar-mentor.com',
    'https://www.grammar-mentor.com',
];

// Allow localhost during development
$dev_origins = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:5173',     // Vite default
    'http://127.0.0.1:5173',
];

$allowed_origins = array_merge($trusted_origins, $dev_origins);

if (in_array($origin, $allowed_origins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');           // Required when reflecting Origin
} else {
    // Fallback – still allow production (but reject others in strict mode)
    header("Access-Control-Allow-Origin: https://grammar-mentor.com");
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");     // cache preflight 24h

// Handle CORS preflight (OPTIONS) request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only allow POST after preflight
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed. Use POST."]);
    exit;
}

// ============================================================================
// Rate Limiting (file-based – keep as is for now)
// ============================================================================
$rateLimitFile = __DIR__ . '/rate-limit.json';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$maxRequests = 20;
$timeWindow  = 5 * 60;

$rateData = file_exists($rateLimitFile) ? json_decode(file_get_contents($rateLimitFile), true) : [];

if (!isset($rateData[$ip])) {
    $rateData[$ip] = ['count' => 0, 'resetTime' => time() + $timeWindow];
}

if (time() > $rateData[$ip]['resetTime']) {
    $rateData[$ip] = ['count' => 0, 'resetTime' => time() + $timeWindow];
}

$rateData[$ip]['count']++;

if ($rateData[$ip]['count'] > $maxRequests) {
    http_response_code(429);
    echo json_encode([
        "error"      => "Rate limit exceeded",
        "retryAfter" => $rateData[$ip]['resetTime'] - time(),
        "limit"      => $maxRequests,
        "window"     => $timeWindow . " seconds"
    ]);
    file_put_contents($rateLimitFile, json_encode($rateData));
    exit;
}

file_put_contents($rateLimitFile, json_encode($rateData), LOCK_EX);

// ============================================================================
// Read & validate input
// ============================================================================
header("Content-Type: application/json");

$input = json_decode(file_get_contents("php://input"), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit;
}

$text     = trim($input['text'] ?? '');
$mode     = trim($input['mode'] ?? 'Authentic (errors only)');
$language = trim($input['language'] ?? 'Auto-detect language');

if (strlen($text) < 10 || strlen($text) > 15000) {
    http_response_code(400);
    echo json_encode(["error" => "Text length must be between 10 and 15,000 characters"]);
    exit;
}

$allowedModes = [
    'Authentic (errors only)',
    'Academic writing',
    'Business professional',
    'Creative Preservation',
    'Simple & Clear',
    'Scientific Precision',
    'Persuasive & Influential',
    'Friendly & Conversational',
    'SEO-Optimized'
];

if (!in_array($mode, $allowedModes, true)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid correction mode"]);
    exit;
}

// ============================================================================
// OpenAI setup
// ============================================================================
$apiKey = '__REDACTED_OPENAI_KEY__';

if (empty($apiKey)) {
    http_response_code(500);
    echo json_encode(["error" => "Server configuration error – API key missing"]);
    exit;
}

$client = OpenAI::client($apiKey);

// ============================================================================
// Dynamic prompt
// ============================================================================
$styleInstruction = match ($mode) {
    'Academic writing' =>
        "Use formal, precise academic style. Avoid contractions. Prefer passive voice where appropriate.",

    'Business professional' =>
        "Use clear, concise, polite and professional business tone.",

    'Creative Preservation' =>
        "Preserve creative style, imagery, and emotional tone. Do not flatten or neutralize expressive language.",

    'Simple & Clear' =>
        "Rewrite only where necessary to improve clarity and readability. Prefer short, direct sentences and simple vocabulary.",

    'Scientific Precision' =>
        "Use highly precise, objective, and unambiguous scientific language. Avoid figurative expressions and ensure terminological consistency.",

    'Persuasive & Influential' =>
    "Use persuasive language, emphasize benefits, include clear calls-to-action, and reinforce reader motivation. Keep tone confident and engaging.",

    'Friendly & Conversational' =>
        "Use natural, friendly, and conversational tone. Short sentences, warmth, and everyday phrasing. Avoid overly formal language.",

    'SEO-Optimized' =>
        "Adjust text for readability and search relevance. Improve structure and keyword placement for online discoverability.",

    default =>
        "Preserve the original personal voice and style. Only correct actual errors – do not rewrite stylistically unless clearly incorrect."
};

$langInstruction = $language === 'Auto-detect language' ? "" : "The text is written in $language.";

$prompt = <<<PROMPT
You are a precise grammar mentor that corrects mistakes while preserving the writer's personal voice and intent.

Rules:
- ONLY correct clear grammar, spelling, punctuation, agreement, word choice errors.
- Do NOT change meaning, tone, vocabulary level, or sentence structure unless it's clearly wrong.
- $styleInstruction
- $langInstruction
- For EVERY correction provide a short, friendly, educational explanation.

Input text:
"""
$text
"""

Return **JSON only** – no other text:

{
  "originalText": "the full original text (unchanged)",
  "correctedText": "the full corrected version",
  "errors": [
    {
      "start": number (0-based character index in originalText),
      "end": number (exclusive),
      "wrong": "the original wrong substring",
      "suggestion": "the corrected substring",
      "message": "Short, friendly explanation why this was wrong and why the suggestion is better"
    },
    ...
  ]
}

If no corrections are needed, return an empty "errors" array.
PROMPT;

// ============================================================================
// Call OpenAI
// ============================================================================
try {
    $response = $client->chat()->create([
        'model'       => 'gpt-4o-mini',
        'messages'    => [
            ['role' => 'system', 'content' => 'You are a helpful grammar expert. Respond with valid JSON only.'],
            ['role' => 'user',   'content' => $prompt],
        ],
        'temperature' => 0.12,
        'max_tokens'  => 4096,
        'response_format' => ['type' => 'json_object'],
    ]);

    $content = $response['choices'][0]['message']['content'] ?? '';

    $result = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($result)) {
        throw new Exception("OpenAI response was not valid JSON");
    }

    // Minimal logging
    $logLine = sprintf(
        "%s | IP: %s | Mode: %s | Lang: %s | Len: %d | Errors: %d | Tokens: %d\n",
        date('c'),
        $ip,
        $mode,
        $language,
        strlen($text),
        count($result['errors'] ?? []),
        $response['usage']['total_tokens'] ?? 0
    );
    file_put_contents(__DIR__ . '/grammar-api.log', $logLine, FILE_APPEND);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'data'   => $result,
        'model'  => $response['model'],
        'usage'  => $response['usage'] ?? null,
    ]);

} catch (Exception $e) {
    error_log("OpenAI Grammar Error: " . $e->getMessage());

    http_response_code(503);
    echo json_encode([
        "error"   => "AI analysis temporarily unavailable",
        "details" => "Please try again in a moment"
    ]);
}