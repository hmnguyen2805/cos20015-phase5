<?php
declare(strict_types=1);

/*
 * DEVELOPMENT ONLY: a stand-in for the Claude Messages API and the Gemini
 * generateContent API, so the AI pipeline can be tested without an API key or
 * credit. It answers with rule-based "extractions" in each API's own format.
 *
 *   C:\xampp\php\php.exe -S 127.0.0.1:8765 app\tools\mock_claude_api.php
 * and point a config file at it: 'endpoint' => 'http://127.0.0.1:8765/v1/messages' (claude)
 * or 'http://127.0.0.1:8765/v1beta/models/{model}:generateContent' (gemini),
 * with CTT_AI_CONFIG=<that file> for the command-line tools.
 * Special keys: 'mock-bad-shape-...' returns an answer that breaks the JSON
 * Schema; 'mock-overloaded-...' returns HTTP 529.
 */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

header('Content-Type: application/json');
$gemini = str_contains($_SERVER['REQUEST_URI'] ?? '', ':generateContent');
$key = $gemini ? ($_SERVER['HTTP_X_GOOG_API_KEY'] ?? '') : ($_SERVER['HTTP_X_API_KEY'] ?? '');
$req = json_decode((string)file_get_contents('php://input'), true) ?: [];
if (!str_starts_with($key, 'mock-')) {
    http_response_code(401);
    exit(json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]));
}
if (str_starts_with($key, 'mock-overloaded-')) {
    http_response_code(529);
    exit(json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]));
}
$text = $gemini ? ($req['contents'][0]['parts'][0]['text'] ?? '') : ($req['messages'][0]['content'] ?? '');
$n = preg_match('~<narrative>\s*(.*?)\s*</narrative>~s', $text, $m) ? $m[1] : '';

$lab = null;
if (preg_match('/\b(ALT|WBC|white blood cell count|Haemoglobin|creatinine)\b[^0-9]*([0-9]+(?:\.[0-9]+)?)\s*(U\/L|x10\^9\/L|g\/dL|umol\/L)?(?: at ([A-Za-z\- ]+Visit(?: [12])?))?/i', $n, $l)) {
    $test = ['alt' => 'ALT', 'wbc' => 'WBC', 'white blood cell count' => 'WBC', 'haemoglobin' => 'Haemoglobin', 'creatinine' => 'Creatinine'][strtolower($l[1])];
    $lab = ['test' => $test, 'value' => (float)$l[2], 'unit' => $l[3] ?? null, 'visit_type' => isset($l[4]) ? trim($l[4]) : null,
            'upper_limit' => preg_match('/upper limit of normal ([0-9]+)/', $n, $u) ? (float)$u[1] : null,
            'repeat_value' => preg_match('/repeat [A-Z]+ at follow-up was ([0-9]+)/', $n, $r) ? (float)$r[1] : null];
}
$withdrawn = (bool)preg_match('/withdr/i', $n);
$answer = [
    'event_term' => trim(strtok($n, ':') ?: 'Unknown event'),
    'symptoms' => array_values(array_filter(['nausea', 'vomiting', 'headache', 'rash'], fn($s) => stripos($n, $s) !== false)),
    'visit_type' => preg_match('/(Screening Visit|Dosing Visit [12]|Follow-up Visit)/', $n, $v) ? $v[1] : null,
    'treatments' => preg_match_all('/(ondansetron|paracetamol|loratadine) ?([0-9]+ mg)?/i', $n, $t, PREG_SET_ORDER)
        ? array_map(fn($x) => ['name' => strtolower($x[1]), 'dose' => $x[2] ?? null], $t) : [],
    'action_with_study_drug' => $withdrawn ? 'withdrawn' : (str_contains($n, 'continued') ? 'continued with monitoring' : 'none'),
    'outcome' => preg_match('/resolved|normal|recovered/i', $n) ? 'recovered' : ($lab && ($lab['repeat_value'] ?? 0) > ($lab['upper_limit'] ?? 1e9) ? 'recovering' : 'unknown'),
    'suggested_severity' => preg_match('/severe|intravenous/i', $n) ? 'Severe' : (preg_match('/ALT|withdraw|persistent/i', $n) ? 'Moderate' : 'Mild'),
    'serious' => false,
    'withdrawn' => $withdrawn,
    'lab' => $lab,
    'evidence' => mb_substr($n, 0, 120),
    'confidence' => 0.8,
];
if (str_starts_with($key, 'mock-bad-shape-')) {
    $answer['suggested_severity'] = 'Critical';      // not in the enum: MySQL must refuse it
}
if ($gemini) {   // Google generateContent response shape
    if (!isset($req['generationConfig']['responseJsonSchema']['properties']['lab']['anyOf'])) {
        http_response_code(400);
        exit(json_encode(['error' => ['code' => 400, 'message' => 'mock: expected responseJsonSchema with anyOf for lab']]));
    }
    exit(json_encode([
        'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => json_encode($answer)]]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 700, 'candidatesTokenCount' => 160],
        'modelVersion' => 'mock-gemini',
    ]));
}
echo json_encode([
    'id' => 'msg_mock_' . bin2hex(random_bytes(6)), 'type' => 'message', 'role' => 'assistant', 'model' => 'mock-extractor',
    'content' => [['type' => 'tool_use', 'id' => 'toolu_mock', 'name' => $req['tool_choice']['name'] ?? 'record_adverse_event', 'input' => $answer]],
    'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 900 + strlen($n) >> 2, 'output_tokens' => 180],
]);
