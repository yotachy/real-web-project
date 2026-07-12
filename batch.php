<?php
/**
 * 실거래가 배치 프록시 — server.py /batch 의 PHP 이식.
 * 요청:  batch.php?LAWD_CD=11680&YMDS=202401,...[&NAMES=[[name,dong],...]]
 * 응답:  { "202401":[{n,u,a,f,p,y,m,d},...], ... }   (해제·무효 제거, slim 필드)
 *
 * 함수(resp_ok/캐시/collect_raw/parse_slim)는 agg.php 가 require 해서 재사용한다.
 * 아래 '요청 처리'는 batch.php 를 직접 호출할 때만 실행(include 시 스킵).
 */

const CACHE_TTL = 1800; // 30분
const API_BASE  = 'https://apis.data.go.kr/1613000/RTMSDataSvcAptTradeDev/getRTMSDataSvcAptTradeDev';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// data.go.kr serviceKey — 소스 하드코딩 금지. 환경변수 → config.local.php 순.
function load_api_key() {
    $k = getenv('DATA_GO_KR_KEY');
    if ($k) return trim($k);
    $local = __DIR__ . '/config.local.php';
    if (is_file($local)) {
        $v = require $local;
        if (is_string($v) && $v !== '') return trim($v);
    }
    return '';
}
$API_KEY = load_api_key();
if ($API_KEY === '') fail(500, 'API 키 미설정 — DATA_GO_KR_KEY 환경변수 또는 config.local.php 필요');

$cacheDir = sys_get_temp_dir() . '/apt-tracker-cache';

// 정상 API 응답 검증(WAF 차단·에러 HTML 걸러냄). 성공은 <resultCode>000. 0건도 정상.
function resp_ok($body) {
    return is_string($body) && strpos($body, '<resultCode>000') !== false;
}

// ── 캐시 유틸 (쓰기 불가 시 조용히 무시. 오염분은 무시하고 재조회) ──
function cache_path($dir, $lawd, $ymd) { return $dir . '/' . $lawd . '_' . $ymd . '.xml'; }
function cache_get($dir, $lawd, $ymd) {
    $p = cache_path($dir, $lawd, $ymd);
    if (is_file($p) && (time() - filemtime($p)) < CACHE_TTL) {
        $d = @file_get_contents($p);
        if ($d !== false && resp_ok($d)) return $d;
    }
    return null;
}
function cache_set($dir, $lawd, $ymd, $data) {
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    @file_put_contents(cache_path($dir, $lawd, $ymd), $data);
}

// 여러 달을 curl_multi 로 수집(웨이브 8 로 동시성 제한 → WAF 회피). 정상만 반환.
function fetch_months($lawd, $ymds, $apiKey) {
    $out = [];
    $waveSize = 8;
    for ($i = 0; $i < count($ymds); $i += $waveSize) {
        $wave = array_slice($ymds, $i, $waveSize);
        $mh = curl_multi_init();
        $handles = [];
        foreach ($wave as $ymd) {
            $url = API_BASE . '?serviceKey=' . $apiKey
                 . '&LAWD_CD=' . urlencode($lawd)
                 . '&DEAL_YMD=' . urlencode($ymd)
                 . '&numOfRows=1000&pageNo=1';
            $c = curl_init($url);
            curl_setopt_array($c, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0',
            ]);
            curl_multi_add_handle($mh, $c);
            $handles[$ymd] = $c;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $ymd => $c) {
            $body = curl_multi_getcontent($c);
            curl_multi_remove_handle($mh, $c);
            curl_close($c);
            if (resp_ok($body)) $out[$ymd] = $body;
        }
        curl_multi_close($mh);
    }
    return $out;
}

// 캐시 히트 분리 + 미스분 수집(웨이브 + 실패 1회 재시도) → [ymd => rawXml]  (batch/agg 공용)
function collect_raw($cacheDir, $lawd, $ymds, $apiKey) {
    $rawMap = [];
    $toFetch = [];
    foreach ($ymds as $ymd) {
        $c = cache_get($cacheDir, $lawd, $ymd);
        if ($c !== null) $rawMap[$ymd] = $c;
        else $toFetch[] = $ymd;
    }
    if ($toFetch) {
        $got = fetch_months($lawd, $toFetch, $apiKey);
        $failed = array_values(array_diff($toFetch, array_keys($got)));
        if ($failed) {
            usleep(400000);
            foreach (fetch_months($lawd, $failed, $apiKey) as $ymd => $b) $got[$ymd] = $b;
        }
        foreach ($got as $ymd => $body) {
            $rawMap[$ymd] = $body;
            cache_set($cacheDir, $lawd, $ymd, $body);
        }
        foreach ($toFetch as $ymd) { if (!isset($rawMap[$ymd])) $rawMap[$ymd] = ''; }
    }
    return $rawMap;
}

// XML → slim 필드. 해제/무효/0원/빈이름/빈연도 제거.
function parse_slim($xmlStr) {
    if (!$xmlStr) return [];
    $prev = libxml_use_internal_errors(true);
    $root = simplexml_load_string($xmlStr);
    libxml_use_internal_errors($prev);
    if ($root === false) return [];

    $items = [];
    foreach ($root->xpath('//item') as $it) {
        $get = function ($t) use ($it) { return trim((string)$it->$t); };
        if ($get('cdealType') === '해제') continue;
        $priceRaw = str_replace([',', ' ', "\t", "\n"], '', $get('dealAmount'));
        $chk = ltrim($priceRaw, '-');
        if ($priceRaw === '' || $chk === '' || !ctype_digit($chk)) continue;
        $price = (int)$priceRaw;
        if ($price <= 0) continue;
        $aptNm = $get('aptNm');
        if ($aptNm === '') continue;
        $yr = $get('dealYear');
        if ($yr === '' || !ctype_digit($yr)) continue;
        $items[] = [
            'n' => $aptNm,
            'u' => $get('umdNm'),
            'a' => $get('excluUseAr'),
            'f' => $get('floor'),
            'p' => $price,
            'y' => $yr,
            'm' => $get('dealMonth'),
            'd' => $get('dealDay'),
        ];
    }
    return $items;
}

// ── 요청 처리 (batch.php 직접 접근 시만; agg.php 가 require 하면 스킵) ──
if (realpath(__FILE__) === realpath(isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '')) {
    $lawd = isset($_GET['LAWD_CD']) ? preg_replace('/\D/', '', $_GET['LAWD_CD']) : '';
    $ymdsRaw = isset($_GET['YMDS']) ? $_GET['YMDS'] : '';
    $ymds = [];
    foreach (explode(',', $ymdsRaw) as $y) {
        $y = trim($y);
        if (strlen($y) === 6 && ctype_digit($y)) $ymds[] = $y;
    }
    if ($lawd === '' || !$ymds) fail(400, 'LAWD_CD/YMDS 파라미터 없음');

    // NAMES(선택): [[name,dong],...] → 응답을 해당 단지 행으로만 필터(전송량↓).
    $nameFilter = null;
    if (isset($_GET['NAMES']) && $_GET['NAMES'] !== '') {
        $decoded = json_decode($_GET['NAMES'], true);
        if (is_array($decoded)) {
            $full = []; $nameOnly = [];
            foreach ($decoded as $pair) {
                if (!is_array($pair) || !isset($pair[0])) continue;
                $nm = (string)$pair[0];
                $dg = isset($pair[1]) ? (string)$pair[1] : '';
                if ($dg === '') $nameOnly[$nm] = true; else $full[$nm . "\0" . $dg] = true;
            }
            if ($full || $nameOnly) $nameFilter = ['full' => $full, 'nameOnly' => $nameOnly];
        }
    }

    $rawMap = collect_raw($cacheDir, $lawd, $ymds, $API_KEY);
    $results = [];
    foreach ($ymds as $ymd) {
        $rows = parse_slim(isset($rawMap[$ymd]) ? $rawMap[$ymd] : '');
        if ($nameFilter !== null) {
            $f = [];
            foreach ($rows as $r) {
                if (isset($nameFilter['nameOnly'][$r['n']]) ||
                    isset($nameFilter['full'][$r['n'] . "\0" . $r['u']])) $f[] = $r;
            }
            $rows = $f;
        }
        $results[$ymd] = $rows;
    }
    echo json_encode($results, JSON_UNESCAPED_UNICODE);
}
