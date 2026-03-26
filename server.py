#!/usr/bin/env python3
"""
실거래가 모니터 - 고성능 서버
- 멀티스레드: 동시 요청 처리
- 서버 캐시: 동일 요청 재사용 (TTL 30분)
- 배치 엔드포인트: 여러 달을 한번에 병렬 처리
"""
import http.server, socketserver, urllib.request, urllib.parse
import json, os, time, threading
from concurrent.futures import ThreadPoolExecutor, as_completed

PORT = 8000
API_KEY = "11dd61957e19e1f4fd453a52a0fd3b35e83ec8696dc1d39030ac588e8c41b53b"
CACHE_TTL = 1800  # 30분
MAX_WORKERS = 10  # 동시 API 호출 수

# ── 서버사이드 캐시 ──────────────────────────────────────────────
cache = {}
cache_lock = threading.Lock()

def cache_get(key):
    with cache_lock:
        entry = cache.get(key)
        if entry and time.time() - entry['ts'] < CACHE_TTL:
            return entry['data']
    return None

def cache_set(key, data):
    with cache_lock:
        cache[key] = {'data': data, 'ts': time.time()}

# ── API 단건 호출 ────────────────────────────────────────────────
def fetch_one(lawd_cd, deal_ymd):
    key = f"{lawd_cd}_{deal_ymd}"
    cached = cache_get(key)
    if cached:
        return deal_ymd, cached, True  # (월, 데이터, 캐시히트)

    url = (
        f"https://apis.data.go.kr/1613000/RTMSDataSvcAptTradeDev/getRTMSDataSvcAptTradeDev"
        f"?serviceKey={API_KEY}&LAWD_CD={lawd_cd}&DEAL_YMD={deal_ymd}&numOfRows=1000&pageNo=1"
    )
    req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
    with urllib.request.urlopen(req, timeout=15) as resp:
        data = resp.read().decode('utf-8')
    cache_set(key, data)
    return deal_ymd, data, False

class Handler(http.server.SimpleHTTPRequestHandler):

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        qs = urllib.parse.parse_qs(parsed.query)

        if parsed.path == '/proxy':
            self.handle_proxy(qs)
        elif parsed.path == '/batch':
            self.handle_batch(qs)
        elif parsed.path in ('/', '/index.html'):
            self.serve_html()
        else:
            super().do_GET()

    # ── 단건 프록시 (기존 호환) ────────────────────────────────
    def handle_proxy(self, qs):
        try:
            lawd_cd  = qs.get('LAWD_CD',  [''])[0]
            deal_ymd = qs.get('DEAL_YMD', [''])[0]
            _, data, hit = fetch_one(lawd_cd, deal_ymd)
            self.respond(200, 'application/xml; charset=utf-8', data.encode())
        except Exception as e:
            self.respond(500, 'application/json', json.dumps({'error': str(e)}).encode())

    # ── 배치: 여러 달 병렬 처리 → 필요 필드만 JSON 반환 ──────────
    def handle_batch(self, qs):
        import xml.etree.ElementTree as ET

        def parse_slim(xml_str):
            try:
                root = ET.fromstring(xml_str)
                items = []
                for item in root.iter('item'):
                    get = lambda t, i=item: (i.findtext(t) or '').strip()
                    if get('cdealType') == '해제':
                        continue
                    price_raw = get('dealAmount').replace(',','').replace(' ','')
                    if not price_raw.isdigit():
                        continue
                    price = int(price_raw)
                    if not price:
                        continue
                    apt_nm = get('aptNm')
                    if not apt_nm:
                        continue
                    items.append({
                        'n': apt_nm,
                        'a': get('excluUseAr'),
                        'f': get('floor'),
                        'p': price,
                        'y': get('dealYear'),
                        'm': get('dealMonth'),
                        'd': get('dealDay'),
                    })
                return items
            except Exception:
                return []

        try:
            lawd_cd = qs.get('LAWD_CD', [''])[0]
            ymds    = qs.get('YMDS', [''])[0].split(',')
            ymds    = [y.strip() for y in ymds if y.strip()]

            # 1단계: 원본 XML 병렬 수집
            raw_map = {}
            hits = 0
            with ThreadPoolExecutor(max_workers=min(MAX_WORKERS, len(ymds))) as ex:
                futures = {ex.submit(fetch_one, lawd_cd, ymd): ymd for ymd in ymds}
                for future in as_completed(futures):
                    ymd, data, hit = future.result()
                    raw_map[ymd] = data
                    if hit: hits += 1

            # 2단계: 병렬 파싱 (필요 필드만)
            results = {}
            with ThreadPoolExecutor(max_workers=4) as ex:
                pfutures = {ex.submit(parse_slim, raw_map[ymd]): ymd for ymd in raw_map}
                for future in as_completed(pfutures):
                    ymd = pfutures[future]
                    results[ymd] = future.result()

            total = sum(len(v) for v in results.values())
            print(f"[배치] {lawd_cd} {len(ymds)}개월 캐시:{hits}건 총:{total}건")
            payload = json.dumps(results, ensure_ascii=False).encode('utf-8')
            self.respond(200, 'application/json; charset=utf-8', payload)
        except Exception as e:
            self.respond(500, 'application/json', json.dumps({'error': str(e)}).encode())

    def serve_html(self):
        path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'apt-tracker.html')
        with open(path, 'rb') as f:
            self.respond(200, 'text/html; charset=utf-8', f.read())

    def respond(self, code, ctype, body):
        self.send_response(code)
        self.send_header('Content-Type', ctype)
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Content-Length', len(body))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt, *args):
        pass  # 배치 로그만 출력

class ThreadingServer(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True

if __name__ == '__main__':
    os.chdir(os.path.dirname(os.path.abspath(__file__)))
    print(f"✅ 실거래가 모니터 서버 시작! (멀티스레드 + 캐시)")
    print(f"👉 http://localhost:{PORT}")
    print(f"🛑 종료: Ctrl+C\n" + "-"*40)
    with ThreadingServer(('', PORT), Handler) as s:
        s.serve_forever()
