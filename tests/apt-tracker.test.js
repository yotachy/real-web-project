'use strict';
const { test } = require('node:test');
const assert = require('node:assert');
const L = require('../apt-tracker.lib.js');

test('formatPrice — 억/만/빈값', () => {
  assert.equal(L.formatPrice(0), '-');
  assert.equal(L.formatPrice(null), '-');
  assert.equal(L.formatPrice(-5), '-');
  assert.equal(L.formatPrice(8500), '8,500만');
  assert.equal(L.formatPrice(10000), '1억');
  assert.equal(L.formatPrice(204000), '20.4억');   // 20억 4000만
  assert.equal(L.formatPrice(110000), '11억');
});

test('fmtDate — 2자리 연도 + 선행0 제거', () => {
  assert.equal(L.fmtDate('2024', '03', '16'), "'24.3.16");
  assert.equal(L.fmtDate(2024, 3, 6), "'24.3.6");
});

test('평형 변환', () => {
  assert.equal(L.m2ToPyeong(84.95), 26);
  assert.equal(L.pyeongShort(84.95), '26평(84.95㎡)');
  assert.equal(L.pyeongStr(59.99), '전용 59.99㎡ · 18평');
});

test('getLastNMonths — now 주입, 연말 롤오버', () => {
  const now = new Date(2024, 0, 15); // 2024-01
  assert.deepEqual(L.getLastNMonths(3, now), ['202401', '202312', '202311']);
  assert.equal(L.getLastNMonths(12, now).length, 12);
  assert.equal(L.getLastNMonths(12, now)[11], '202302');
});

test('periodLabel', () => {
  assert.equal(L.periodLabel(3), '3개월');
  assert.equal(L.periodLabel(12), '1년');
  assert.equal(L.periodLabel(36), '3년');
});

test('calcStats — 변동률/최저/건수', () => {
  assert.equal(L.calcStats([]), null);
  assert.equal(L.calcStats(null), null);
  const s = L.calcStats([{ price: 110 }, { price: 100 }, { price: 120 }]);
  assert.equal(s.count, 3);
  assert.equal(s.minPrice, 100);
  assert.ok(Math.abs(s.change - 10) < 1e-9);         // (110-100)/100*100
  const single = L.calcStats([{ price: 100 }]);
  assert.equal(single.change, null);                 // 직전 없음
});

test('normalizeName — 공백/괄호/하이픈 제거 + 소문자', () => {
  assert.equal(L.normalizeName('래미안 개포-루체하임 (1동)'), '래미안개포루체하임1동');
});

test('matchByName — 부분일치 우선, 폴백 정규화', () => {
  const txs = [
    { aptName: '헬리오시티' },
    { aptName: '래미안 개포 루체하임' },
    { aptName: '수서' }
  ];
  assert.equal(L.matchByName(txs, '헬리오').length, 1);
  // 공백 차이는 폴백 정규화로 매칭
  assert.equal(L.matchByName(txs, '래미안개포루체하임').length, 1);
  assert.equal(L.matchByName(txs, '없는아파트').length, 0);
});

test('escapeHtml — XSS 문자 이스케이프', () => {
  assert.equal(L.escapeHtml('<img src=x onerror=alert(1)>'), '&lt;img src=x onerror=alert(1)&gt;');
  assert.equal(L.escapeHtml('A & B "C" \'D\''), 'A &amp; B &quot;C&quot; &#39;D&#39;');
  assert.equal(L.escapeHtml(null), '');
  assert.equal(L.escapeHtml('래미안'), '래미안');
});

test('통합: 배치응답 → 매칭 → 평형버킷 → 통계 → 카드표시 (addApartment/refreshAll 흐름)', () => {
  // batch.php 가 돌려주는 형태를 fetchBatchChunk 가 변환한 tx 배열
  const regionAll = [
    { aptName: '헬리오시티', area: 84.95, floor: '10', price: 230000, year: '2024', month: '05', day: '20' },
    { aptName: '헬리오시티', area: 84.95, floor: '7',  price: 245000, year: '2024', month: '06', day: '11' },
    { aptName: '헬리오시티', area: 59.96, floor: '3',  price: 180000, year: '2024', month: '06', day: '02' },
    { aptName: '가락래미안', area: 84.9,  floor: '5',  price: 210000, year: '2024', month: '06', day: '01' }
  ];
  // 사용자가 '헬리오시티' 30평대(84㎡) 등록
  const matched = L.matchByName(regionAll, '헬리오시티');
  assert.equal(matched.length, 3);                 // 가락래미안 제외
  const bucket30 = L.bucketByPyeong(matched, '30');
  assert.equal(bucket30.length, 2);                // 84.95㎡ 2건 (59.96 은 20평대)
  assert.equal(bucket30[0].month, '06');           // 최신 먼저
  const stats = L.calcStats(bucket30);
  assert.equal(stats.count, 2);
  assert.equal(L.formatPrice(stats.latest.price), '24.5억');
  assert.equal(L.formatPrice(stats.minPrice), '23억');
  assert.ok(stats.change > 0);                     // 23억 → 24.5억 상승
  assert.equal(L.bucketByPyeong(matched, '20').length, 1);  // 59.96㎡ 1건
});

test('bucketByPyeong — 구간 경계 + 날짜 내림차순', () => {
  const txs = [
    { aptName: 'A', area: 49, year: '2024', month: '01', day: '10', price: 5 }, // 20평대 (>=49)
    { aptName: 'A', area: 48.9, year: '2024', month: '02', day: '10', price: 4 }, // 10평대 (<49)
    { aptName: 'A', area: 59, year: '2024', month: '03', day: '10', price: 6 },  // 20평대
    { aptName: 'A', area: 0, year: '2024', month: '03', day: '11', price: 9 }    // 면적0 제외
  ];
  const b20 = L.bucketByPyeong(txs, '20');
  assert.equal(b20.length, 2);
  assert.equal(b20[0].month, '03');  // 최신 먼저
  assert.equal(b20[1].month, '01');
  assert.equal(L.bucketByPyeong(txs, '10').length, 1);
  assert.equal(L.bucketByPyeong(txs, '99').length, 0); // 없는 키
});
