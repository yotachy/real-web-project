/*
 * apt-tracker.lib.js — 순수 로직 헬퍼 (DOM 비의존).
 * 브라우저: window 전역으로 노출. Node: module.exports (테스트용).
 * 가격/날짜 포맷·평형 변환·기간 계산·통계·이름매칭·평형버킷.
 */
(function (root) {
  'use strict';

  // 평형 구간 정의 (전용면적 m² 기준, [min, max) )
  // 전용면적(㎡) 구간 → 흔한 평형 호칭(공급 기준 'N평대')에 맞춤.
  // 전용률 ~74% 환산: 공급 20평≈전용49 · 30평≈전용73 · 40평≈전용98 · 50평≈전용122.
  // (예: 전용 59=25평→20평대, 74~75=30평→30평대, 122~131=50~53평→50평이상)
  var PYEONG_DEFS = {
    '10': { label: '10평대',   minM2: 0,   maxM2: 49 },
    '20': { label: '20평대',   minM2: 49,  maxM2: 73 },
    '30': { label: '30평대',   minM2: 73,  maxM2: 98 },
    '40': { label: '40평대',   minM2: 98,  maxM2: 122 },
    '50': { label: '50평이상', minM2: 122, maxM2: 9999 }
  };

  var M2_PER_PYEONG = 3.3058;

  // 만원 단위 금액 → '20.4억' / '8,500만' / '-'
  function formatPrice(v) {
    if (!v || v <= 0) return '-';
    var eok = v / 10000;
    if (eok >= 1) {
      var rounded = Math.round(eok * 10) / 10;
      return rounded + '억';
    }
    return v.toLocaleString() + '만';
  }

  // 해당 연·월로부터 지금(now 주입 가능)까지 경과 개월수
  function monthsSince(year, month, now) {
    now = now || new Date();
    return (now.getFullYear() * 12 + now.getMonth()) - ((parseInt(year, 10) || 0) * 12 + ((parseInt(month, 10) || 1) - 1));
  }
  // 경과 개월 → 'N개월 전' / 'N년 전' / 'N년 M개월 전'
  function agoLabel(months) {
    if (months < 0) months = 0;
    if (months < 12) return months + '개월 전';
    var y = Math.floor(months / 12), m = months % 12;
    return m ? y + '년 ' + m + '개월 전' : y + '년 전';
  }

  // (2024, '03', '16') → "'24.3.16"
  function fmtDate(y, m, d) {
    var mm = parseInt(m, 10);
    var dd = parseInt(d, 10);
    return "'" + String(y).slice(-2) + '.' + (isNaN(mm) ? m : mm) + '.' + (isNaN(dd) ? d : dd);
  }

  function m2ToPyeong(m2) {
    return Math.round(m2 / M2_PER_PYEONG);
  }
  function pyeongStr(m2) {
    return '전용 ' + m2 + '㎡ · ' + m2ToPyeong(m2) + '평';
  }

  // 층 구분(저/중/고). data.go.kr 에 총층수가 없어 절대 층수 기준 근사.
  // 저층 1~5 · 중층 6~15 · 고층 16+ . 지하/불명은 ''.
  function floorTier(floor) {
    var f = parseInt(floor, 10);
    if (isNaN(f) || f <= 0) return '';
    return f <= 5 ? '저층' : f <= 15 ? '중층' : '고층';
  }
  // 층 구분 → CSS 클래스 (색상용): 저층 low(파랑)·중층 mid(회색)·고층 high(골드)
  function floorTierCls(floor) {
    var t = floorTier(floor);
    return t === '저층' ? 'low' : t === '중층' ? 'mid' : t === '고층' ? 'high' : '';
  }
  function pyeongShort(m2) {
    return m2ToPyeong(m2) + '평(' + m2 + '㎡)';
  }

  // 최근 n개월 YYYYMM 배열 (now 주입 가능 — 테스트용)
  function getLastNMonths(n, now) {
    now = now || new Date();
    var r = [];
    for (var i = 0; i < n; i++) {
      var d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      r.push('' + d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0'));
    }
    return r;
  }

  // 개월수 → '3개월' / '1년' / '3년'
  function periodLabel(months) {
    return months >= 12 ? (months / 12) + '년' : months + '개월';
  }

  // 거래 배열(최신순 정렬 가정) → 최근/최저/최고/변동률/고점대비/신고·신저/건수
  function calcStats(txs) {
    if (!txs || !txs.length) return null;
    var latest = txs[0];
    var prices = txs.map(function (t) { return t.price; });
    var minPrice = Math.min.apply(null, prices);
    var maxPrice = Math.max.apply(null, prices);
    var prev = txs[1];
    var change = prev ? ((latest.price - prev.price) / prev.price * 100) : null;
    // 고점 대비 현재가(%). 기간 내 최고가 대비 최근 거래. 0 이하(고점이면 0).
    var fromHigh = maxPrice > 0 ? (latest.price - maxPrice) / maxPrice * 100 : 0;
    return {
      latest: latest, prev: prev || null,
      minPrice: minPrice, maxPrice: maxPrice,
      change: change, fromHigh: fromHigh,
      isHigh: latest.price === maxPrice,   // 신고가(기간 내)
      isLow: latest.price === minPrice,    // 신저가(기간 내)
      count: txs.length
    };
  }

  // 평단가(만원/평) = 거래금액(만원) / 전용평. area 는 ㎡.
  function pyeongUnitPrice(price, areaM2) {
    if (!price || !areaM2) return 0;
    return Math.round(price / (areaM2 / M2_PER_PYEONG));
  }

  // 거래 배열의 평균 평단가(만원/평)
  function avgPyeongPrice(txs) {
    if (!txs || !txs.length) return 0;
    var sum = 0, n = 0;
    for (var i = 0; i < txs.length; i++) {
      var u = pyeongUnitPrice(txs[i].price, txs[i].area);
      if (u > 0) { sum += u; n++; }
    }
    return n ? Math.round(sum / n) : 0;
  }

  function normalizeName(s) {
    return String(s || '').replace(/[\s\-()]/g, '').toLowerCase();
  }

  // innerHTML 삽입 전 사용자/외부 문자열 이스케이프 (XSS 방지)
  var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ESC[c]; });
  }

  // 단지 그룹 키: 이름 + 법정동 (동명 아파트를 물리적으로 분리)
  function aptGroupKey(name, dong) {
    return String(name || '') + '' + String(dong || '');
  }
  // tx 가 (이름,동) 단지에 해당하는지. dong 미지정(구버전 저장·수동입력)이면 이름만 비교(하위호환).
  function sameApt(tx, name, dong) {
    if (tx.aptName !== name) return false;
    if (!dong) return true;
    return (tx.dong || '') === dong;
  }

  // 부분일치 우선, 없으면 정규화 후 양방향 포함 매칭
  function matchByName(txs, name) {
    var matched = txs.filter(function (tx) { return tx.aptName.indexOf(name) !== -1; });
    if (!matched.length) {
      var ns = normalizeName(name);
      matched = txs.filter(function (tx) {
        var n = normalizeName(tx.aptName);
        return n.indexOf(ns) !== -1 || ns.indexOf(n) !== -1;
      });
    }
    return matched;
  }

  var dateKey = function (t) { return '' + t.year + t.month + t.day; };

  function median(arr) {
    if (!arr.length) return 0;
    var s = arr.slice().sort(function (a, b) { return a - b; });
    var m = Math.floor(s.length / 2);
    return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2;
  }
  // 월 인덱스(연*12+월-1) — 실제 시간 간격/공백을 보존
  function monthIndex(t) {
    return (parseInt(t.year, 10) || 0) * 12 + ((parseInt(t.month, 10) || 1) - 1);
  }

  // 기간 내 상승률(%): 월별 '중앙값 평단가'에 최소제곱 회귀선을 적합해
  // 첫 관측월 → 마지막 관측월의 추세선 값 변화율을 반환.
  //  - 월 중앙값: 같은 달 안의 층·향·급매 이상치를 완화(평균 대신 중앙값)
  //  - 회귀: 끝점 2구간이 아니라 전체 관측월을 시간가중으로 반영
  //  - 요구: 거래 3건 이상 + 관측 개월 3개 이상(추세 불가하면 null)
  function growthRate(txs) {
    if (!txs || txs.length < 3) return null;
    var byMonth = {};
    for (var i = 0; i < txs.length; i++) {
      var u = pyeongUnitPrice(txs[i].price, txs[i].area);
      if (u <= 0) continue;
      var mi = monthIndex(txs[i]);
      (byMonth[mi] = byMonth[mi] || []).push(u);
    }
    var pts = [];
    for (var k in byMonth) if (byMonth.hasOwnProperty(k)) pts.push([parseInt(k, 10), median(byMonth[k])]);
    if (pts.length < 3) return null;   // 관측 개월 3개 미만 → 추세 산출 불가
    pts.sort(function (a, b) { return a[0] - b[0]; });
    // 최소제곱 회귀 v = a + b*x  (x = 첫 관측월을 0 으로 이동한 월 인덱스)
    var t0 = pts[0][0], n = pts.length, sx = 0, sy = 0, sxx = 0, sxy = 0;
    for (var j = 0; j < n; j++) {
      var x = pts[j][0] - t0, y = pts[j][1];
      sx += x; sy += y; sxx += x * x; sxy += x * y;
    }
    var denom = n * sxx - sx * sx;
    if (denom === 0) return null;
    var b = (n * sxy - sx * sy) / denom;
    var a = (sy - b * sx) / n;
    var span = pts[n - 1][0] - t0;
    var fitStart = a, fitEnd = a + b * span;
    if (fitStart <= 0) return null;
    return (fitEnd - fitStart) / fitStart * 100;
  }

  // 선형 회귀(최소제곱): pts=[{x,y}] → {slope,intercept}. 2점 미만/수직이면 null.
  // 차트 추세선용 — 좌표를 조합해 상승/횡보/하락 판정 + 추세선 그리기.
  function linreg(pts) {
    if (!pts || pts.length < 2) return null;
    var n = pts.length, sx = 0, sy = 0, sxx = 0, sxy = 0;
    for (var i = 0; i < n; i++) {
      var x = pts[i].x, y = pts[i].y;
      sx += x; sy += y; sxx += x * x; sxy += x * y;
    }
    var d = n * sxx - sx * sx;
    if (d === 0) return null;
    var slope = (n * sxy - sx * sy) / d;
    return { slope: slope, intercept: (sy - slope * sx) / n };
  }

  // 평형 구간으로 필터 + 날짜 내림차순 정렬
  function bucketByPyeong(txs, pyeongKey) {
    var def = PYEONG_DEFS[pyeongKey];
    if (!def) return [];
    return txs
      .filter(function (tx) { return tx.area > 0 && tx.area >= def.minM2 && tx.area < def.maxM2; })
      .sort(function (a, b) { return dateKey(b).localeCompare(dateKey(a)); });
  }

  var api = {
    PYEONG_DEFS: PYEONG_DEFS,
    M2_PER_PYEONG: M2_PER_PYEONG,
    formatPrice: formatPrice,
    monthsSince: monthsSince,
    agoLabel: agoLabel,
    fmtDate: fmtDate,
    m2ToPyeong: m2ToPyeong,
    pyeongStr: pyeongStr,
    pyeongShort: pyeongShort,
    floorTier: floorTier,
    floorTierCls: floorTierCls,
    getLastNMonths: getLastNMonths,
    periodLabel: periodLabel,
    calcStats: calcStats,
    pyeongUnitPrice: pyeongUnitPrice,
    avgPyeongPrice: avgPyeongPrice,
    normalizeName: normalizeName,
    escapeHtml: escapeHtml,
    aptGroupKey: aptGroupKey,
    sameApt: sameApt,
    matchByName: matchByName,
    bucketByPyeong: bucketByPyeong,
    growthRate: growthRate,
    linreg: linreg
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;             // Node (테스트)
  } else {
    for (var k in api) if (api.hasOwnProperty(k)) root[k] = api[k];  // 브라우저 전역
  }
})(typeof window !== 'undefined' ? window : this);
