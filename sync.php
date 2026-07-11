<?php
/**
 * 관심목록 동기화 — 코드로 서버에 JSON 저장/조회. (로그인 없는 크로스기기 동기화)
 *  GET  sync.php?code=XXXXXXXX        → 저장된 JSON (없으면 {"empty":true})
 *  POST sync.php?code=XXXXXXXX (body) → JSON 저장
 * 저장 위치: synced/{code}.json (직접 HTTP 접근은 .htaccess 로 차단, PHP 만 파일로 접근)
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

function fail($c, $m) { http_response_code($c); echo json_encode(['error' => $m], JSON_UNESCAPED_UNICODE); exit; }

// 코드: 영숫자 6~16 (경로조작 방지 위해 영숫자만)
$code = isset($_GET['code']) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['code'])) : '';
if (strlen($code) < 6 || strlen($code) > 16) fail(400, '코드 형식 오류');

$dir  = __DIR__ . '/synced';
$path = $dir . '/' . $code . '.json';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    if ($body === false || strlen($body) > 300000) fail(413, '데이터 크기 초과');   // 300KB 상한
    if (json_decode($body) === null && trim($body) !== 'null') fail(400, 'JSON 형식 오류');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        // 직접 HTTP 접근 차단 (PHP 는 파일시스템으로 읽으므로 영향 없음)
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    if (@file_put_contents($path, $body) === false) fail(500, '저장 실패');
    echo json_encode(['ok' => true, 'code' => $code]);
    exit;
}

// GET: 불러오기
if (is_file($path)) {
    $d = @file_get_contents($path);
    echo ($d === false) ? json_encode(['empty' => true]) : $d;
} else {
    echo json_encode(['empty' => true]);
}
