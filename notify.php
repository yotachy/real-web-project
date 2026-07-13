<?php
/**
 * 관심단지 새 실거래 → 텔레그램 알림 (cron 주기 실행).
 *   GET notify.php?key=<시크릿>              → 감지 후 새 거래를 텔레그램 전송
 *   GET notify.php?key=<시크릿>&getchats=1    → 봇에 온 메시지의 chat_id 목록(초기 설정용)
 *
 * 공유 관심목록(synced/APTREALSHARED01.json)의 각 단지·평형에 대해 최근 3개월 실거래를
 * 조회, 서버 seen(synced/notify_seen.json)과 비교해 '새 거래'만 전송. 첫 실행은 baseline(무전송).
 * 설정(봇 토큰·chat_id·시크릿키)은 notify.config.php (gitignore, 서버 전용).
 * batch.php 의 collect_raw/parse_slim 재사용(원시 XML 캐시 공유).
 */
require __DIR__ . '/batch.php';   // $API_KEY, $cacheDir, collect_raw(), parse_slim()

header('Content-Type: application/json; charset=utf-8');

$cfg = @include __DIR__ . '/notify.config.php';
if (!is_array($cfg) || empty($cfg['token']) || empty($cfg['key'])) {
    http_response_code(500); echo json_encode(['error' => 'notify.config.php 없음/불완전']); exit;
}
if (($_GET['key'] ?? '') !== $cfg['key']) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

function tg($token, $method, $params) {
    $c = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params), CURLOPT_TIMEOUT => 12,
    ]);
    $r = curl_exec($c); curl_close($c);
    return json_decode($r, true);
}

// 초기 설정 도우미: 봇에 메시지 보낸 사람들의 chat_id 나열
if (!empty($_GET['getchats'])) {
    $u = tg($cfg['token'], 'getUpdates', []);
    $chats = [];
    foreach (($u['result'] ?? []) as $up) {
        $m = $up['message'] ?? $up['edited_message'] ?? null;
        if ($m && isset($m['chat']['id'])) $chats[$m['chat']['id']] = trim(($m['chat']['first_name'] ?? '') . ' ' . ($m['chat']['username'] ?? ''));
    }
    echo json_encode(['chats' => $chats], JSON_UNESCAPED_UNICODE); exit;
}

$syncDir = __DIR__ . '/synced';
$wl = @json_decode(@file_get_contents($syncDir . '/APTREALSHARED01.json'), true);
$apts = $wl['apartments'] ?? [];
if (!$apts) { echo json_encode(['ok' => true, 'note' => '공유 관심목록 비어있음']); exit; }

// 평형 버킷(전용면적 ㎡) — 클라 PYEONG_DEFS 와 동일
$PY = ['10' => [0, 49], '20' => [49, 73], '30' => [73, 98], '40' => [98, 122], '50' => [122, 99999]];

// 최근 3개월(신규 감지 충분)
$ymds = [];
for ($i = 0; $i < 3; $i++) $ymds[] = date('Ym', strtotime("-$i month"));

$seenPath = $syncDir . '/notify_seen.json';
$seen = @json_decode(@file_get_contents($seenPath), true);
$firstRun = !is_array($seen);
if ($firstRun) $seen = [];

$byLawd = [];
foreach ($apts as $a) { if (!empty($a['lawdCd'])) $byLawd[$a['lawdCd']][] = $a; }

$current = [];   // 이번 회차에 본 전체 거래 id (seen 갱신용, 용량 자동 관리)
$fresh = [];     // 새 거래
foreach ($byLawd as $lawd => $list) {
    $raw = collect_raw($cacheDir, $lawd, $ymds, $API_KEY);
    $rows = [];
    foreach ($ymds as $ymd) foreach (parse_slim($raw[$ymd] ?? '') as $r) $rows[] = $r;
    foreach ($list as $a) {
        foreach (($a['pyeongs'] ?? []) as $pk) {
            if (!isset($PY[$pk])) continue;
            list($pmin, $pmax) = $PY[$pk];
            foreach ($rows as $r) {
                if ($r['n'] !== $a['aptName']) continue;
                if (($a['dong'] ?? '') !== '' && $r['u'] !== $a['dong']) continue;
                $area = (float)$r['a'];
                if ($area <= 0 || $area < $pmin || $area >= $pmax) continue;
                $id = $r['n'] . '|' . $r['u'] . '|' . $r['y'] . $r['m'] . $r['d'] . '|' . $r['f'] . '|' . $r['a'] . '|' . $r['p'];
                $current[$id] = 1;
                if (!$firstRun && !isset($seen[$id])) {
                    $fresh[] = [
                        'apt' => $a['aptName'] . (($a['dong'] ?? '') ? ' ' . $a['dong'] : ''),
                        'py' => $pk, 'p' => (int)$r['p'], 'f' => $r['f'], 'area' => $area,
                        'date' => $r['y'] . '.' . str_pad($r['m'], 2, '0', STR_PAD_LEFT) . '.' . str_pad($r['d'], 2, '0', STR_PAD_LEFT),
                    ];
                }
            }
        }
    }
}

// seen = 이번 회차 id 집합(3개월 window 기준이라 자동으로 오래된 것 제거 → 파일 크기 관리)
if (!is_dir($syncDir)) { @mkdir($syncDir, 0775, true); @file_put_contents($syncDir . '/.htaccess', "Require all denied\n"); }
@file_put_contents($seenPath, json_encode($current));

$sent = 0;
if (!$firstRun) {
    foreach ($fresh as $n) {
        $eok = rtrim(rtrim(number_format($n['p'] / 10000, 1), '0'), '.');
        $msg = "🏠 새 실거래\n{$n['apt']} ({$n['py']}평대)\n💰 {$eok}억 · {$n['f']}층 · 전용 {$n['area']}㎡\n📅 {$n['date']}\nhttps://aptscoop.com/real/apt-tracker.html";
        foreach (($cfg['chats'] ?? []) as $chat) tg($cfg['token'], 'sendMessage', ['chat_id' => $chat, 'text' => $msg, 'disable_web_page_preview' => true]);
        $sent++;
    }
}
echo json_encode(['ok' => true, 'firstRun' => $firstRun, 'new' => count($fresh), 'sent' => $sent], JSON_UNESCAPED_UNICODE);
