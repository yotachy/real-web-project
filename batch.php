<?php
/**
 * 실거래가 배치 프록시 — server.py /batch 엔드포인트의 PHP 이식.
 * cafe24 정적 호스팅(Python 미지원)에서 apt-tracker.html 이 사용.
 *
 * 요청:  batch.php?LAWD_CD=11680&YMDS=202401,202402,...
 * 응답:  { "202401": [ {n,a,f,p,y,m,d}, ... ], ... }   (해제·무효 건 제거, slim 필드)
 *
 * - curl_multi 로 여러 달 병렬 수집 (server.py ThreadPoolExecutor 대응)
 * - 파일 캐시 TTL 30분 (쓰기 불가 환경이면 캐시 없이 동작)
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

// data.go.kr RTMSDataSvcAptTradeDev serviceKey — 소스에 하드코딩 금지.
// 우선순위: 환경변수 DATA_GO_KR_KEY → config.local.php(git 제외) 의 반환값.
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

$lawd = isset($_GET['LAWD_CD']) ? preg_replace('/\D/', '', $_GET['LAWD_CD']) : '';
$ymdsRaw = isset($_GET['YMDS']) ? $_GET['YMDS'] : '';

$ymds = [];
foreach (explode(',', $ymdsRaw) as $y) {
    $y = trim($y);
    if (strlen($y) === 6 && ctype_digit($y)) $ymds[] = $y;
}
if ($lawd === '' || !$ymds) fail(400, 'LAWD_CD/YMDS 파라미터 없음');

// ── 캐시 유틸 (쓰기 불가 시 조용히 무시) ─────────────────────────
$cacheDir = sys_get_temp_dir() . '/apt-tracker-cache';
function cache_path($dir, $lawd, $ymd) { return $dir . '/' . $lawd . '_' . $ymd . '.xml'; }
function cache_get($dir, $lawd, $ymd) {
    $p = cache_path($dir, $lawd, $ymd);
    if (is_file($p) && (time() - filemtime($p)) < CACHE_TTL) {
        $d = @file_get_contents($p);
        if ($d !== false) return $d;
    }
    return null;
}
function cache_set($dir, $lawd, $ymd, $data) {
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    @file_put_contents(cache_path($dir, $lawd, $ymd), $data);
}

// ── 1단계: 캐시 히트 분리 + 미스분 병렬 수집 ─────────────────────
$rawMap = [];
$toFetch = [];
foreach ($ymds as $ymd) {
    $c = cache_get($cacheDir, $lawd, $ymd);
    if ($c !== null) $rawMap[$ymd] = $c;
    else $toFetch[] = $ymd;
}

if ($toFetch) {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($toFetch as $ymd) {
        $url = API_BASE . '?serviceKey=' . $API_KEY
             . '&LAWD_CD=' . urlencode($lawd)
             . '&DEAL_YMD=' . urlencode($ymd)
             . '&numOfRows=1000&pageNo=1';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$ymd] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $status === CURLM_OK);

    foreach ($handles as $ymd => $ch) {
        $body = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $rawMap[$ymd] = ($body === false || $body === null) ? '' : $body;
        if ($rawMap[$ymd] !== '') cache_set($cacheDir, $lawd, $ymd, $rawMap[$ymd]);
    }
    curl_multi_close($mh);
}

// ── 2단계: XML slim 파싱 (parse_slim 대응) ───────────────────────
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
            'u' => $get('umdNm'),   // 법정동 (동명 아파트 구분용)
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

$results = [];
foreach ($ymds as $ymd) {
    $results[$ymd] = parse_slim(isset($rawMap[$ymd]) ? $rawMap[$ymd] : '');
}

echo json_encode($results, JSON_UNESCAPED_UNICODE);
