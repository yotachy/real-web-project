<?php
/**
 * 단지별 평단가 집계 — '비슷한 평단가 찾기'의 구/군 전체 검색용.
 *   GET agg.php?LAWD=11680&YMDS=202401,...&PMIN=73&PMAX=98
 *   → { "rows":[{n,u,avg,cnt,lp,ly,lm}, ...] }
 *   · 전용면적 [PMIN, PMAX) ㎡ 버킷만, 거래 3건 이상 단지만.
 *   · 평단가 = 거래가(만원) / (전용㎡ / 3.3058).  (M2_PER_PYEONG 만 서버 상수)
 * batch.php 의 원시 XML 캐시(같은 디렉토리)를 공유 → 추가 upstream 최소화.
 * 집계 결과도 파일 캐시(30분) → 전체 반복 조회는 즉시.
 */
require __DIR__ . '/batch.php';   // 함수(collect_raw/parse_slim/...) + $API_KEY + $cacheDir 재사용

// 집계 결과 캐시 TTL — 6개월 평단가 집계는 시간단위로 거의 안 변함 → 길게(3시간).
// (원시 XML 캐시는 batch.php 의 30분 유지: 관심목록 신선도용)
const AGG_TTL = 10800;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$lawd = isset($_GET['LAWD']) ? preg_replace('/\D/', '', $_GET['LAWD']) : '';
$ymdsRaw = isset($_GET['YMDS']) ? $_GET['YMDS'] : '';
$pmin = isset($_GET['PMIN']) ? (float)$_GET['PMIN'] : 0;
$pmax = isset($_GET['PMAX']) ? (float)$_GET['PMAX'] : 99999;

$ymds = [];
foreach (explode(',', $ymdsRaw) as $y) {
    $y = trim($y);
    if (strlen($y) === 6 && ctype_digit($y)) $ymds[] = $y;
}
if ($lawd === '' || !$ymds) fail(400, 'LAWD/YMDS 파라미터 없음');

// 집계 결과 캐시 (구 + 기간 + 평형버킷)
$aggDir  = $cacheDir . '/agg';
$aggPath = $aggDir . '/' . $lawd . '_' . md5('v2|' . implode(',', $ymds) . '|' . $pmin . '|' . $pmax) . '.json';
if (is_file($aggPath) && (time() - filemtime($aggPath)) < AGG_TTL) {
    $d = @file_get_contents($aggPath);
    if ($d !== false) { echo $d; exit; }
}

$rawMap = collect_raw($cacheDir, $lawd, $ymds, $API_KEY);
$M2 = 3.3058;
$agg = [];
foreach ($ymds as $ymd) {
    foreach (parse_slim(isset($rawMap[$ymd]) ? $rawMap[$ymd] : '') as $r) {
        $area = (float)$r['a'];
        if ($area <= 0 || $area < $pmin || $area >= $pmax) continue;
        if ($r['p'] <= 0) continue;
        $u = (int)round($r['p'] / ($area / $M2));   // 평단가(만원/평)
        if ($u <= 0) continue;
        $ym = $r['y'] . str_pad($r['m'], 2, '0', STR_PAD_LEFT);
        $k = $r['n'] . "\0" . $r['u'];
        if (!isset($agg[$k])) $agg[$k] = ['n' => $r['n'], 'u' => $r['u'], 'sum' => 0, 'psum' => 0, 'cnt' => 0, 'lp' => 0, 'ld' => '', 'ly' => '', 'lm' => '', 'mo' => []];
        $agg[$k]['sum']  += $u;        // 평단가 합
        $agg[$k]['psum'] += $r['p'];   // 총액 합
        $agg[$k]['cnt']++;
        if (!isset($agg[$k]['mo'][$ym])) $agg[$k]['mo'][$ym] = [0, 0];
        $agg[$k]['mo'][$ym][0] += $u;   // 월별 평단가 합
        $agg[$k]['mo'][$ym][1] += 1;    // 월별 건수
        $date = $ym . str_pad($r['d'], 2, '0', STR_PAD_LEFT);
        if ($date > $agg[$k]['ld']) {
            $agg[$k]['ld'] = $date;
            $agg[$k]['lp'] = $r['p'];
            $agg[$k]['ly'] = $r['y'];
            $agg[$k]['lm'] = $r['m'];
        }
    }
}
$rows = [];
foreach ($agg as $a) {
    if ($a['cnt'] < 3) continue;   // 노이즈 방지
    $mv = [];   // 월별 평균 평단가 (클라이언트가 추세 계산)
    foreach ($a['mo'] as $ym => $sc) $mv[$ym] = (int)round($sc[0] / $sc[1]);
    $rows[] = [
        'n'   => $a['n'],
        'u'   => $a['u'],
        'avg' => (int)round($a['sum'] / $a['cnt']),   // 평균 평단가
        'tp'  => (int)round($a['psum'] / $a['cnt']),  // 평균 총액(만원)
        'cnt' => $a['cnt'],
        'lp'  => $a['lp'],
        'ly'  => $a['ly'],
        'lm'  => $a['lm'],
        'mv'  => $mv,
    ];
}
$out = json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE);
if (!is_dir($aggDir)) @mkdir($aggDir, 0777, true);
@file_put_contents($aggPath, $out);
echo $out;
