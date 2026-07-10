/*
 * apt-tracker.lib.js — 순수 로직 헬퍼 (DOM 비의존).
 * 브라우저: window 전역으로 노출. Node: module.exports (테스트용).
 * 가격/날짜 포맷·평형 변환·기간 계산·통계·이름매칭·평형버킷.
 */
(function (root) {
  'use strict';

  // 평형 구간 정의 (전용면적 m² 기준, [min, max) )
  var PYEONG_DEFS = {
    '10': { label: '10평대',   minM2: 0,   maxM2: 49 },
    '20': { label: '20평대',   minM2: 49,  maxM2: 76 },
    '30': { label: '30평대',   minM2: 76,  maxM2: 99 },
    '40': { label: '40평대',   minM2: 99,  maxM2: 132 },
    '50': { label: '50평이상', minM2: 132, maxM2: 9999 }
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

  // 거래 배열(최신순 정렬 가정) → 최근/최저/변동률/건수
  function calcStats(txs) {
    if (!txs || !txs.length) return null;
    var latest = txs[0];
    var minPrice = Math.min.apply(null, txs.map(function (t) { return t.price; }));
    var prev = txs[1];
    var change = prev ? ((latest.price - prev.price) / prev.price * 100) : null;
    return { latest: latest, minPrice: minPrice, change: change, count: txs.length };
  }

  function normalizeName(s) {
    return String(s || '').replace(/[\s\-()]/g, '').toLowerCase();
  }

  // innerHTML 삽입 전 사용자/외부 문자열 이스케이프 (XSS 방지)
  var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ESC[c]; });
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
    fmtDate: fmtDate,
    m2ToPyeong: m2ToPyeong,
    pyeongStr: pyeongStr,
    pyeongShort: pyeongShort,
    getLastNMonths: getLastNMonths,
    periodLabel: periodLabel,
    calcStats: calcStats,
    normalizeName: normalizeName,
    escapeHtml: escapeHtml,
    matchByName: matchByName,
    bucketByPyeong: bucketByPyeong
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;             // Node (테스트)
  } else {
    for (var k in api) if (api.hasOwnProperty(k)) root[k] = api[k];  // 브라우저 전역
  }
})(typeof window !== 'undefined' ? window : this);
