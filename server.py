#!/usr/bin/env python3
"""
실거래가 모니터 - 로컬 서버
실행: python server.py
그 다음 브라우저에서 http://localhost:8000 접속
"""
import http.server
import urllib.request
import urllib.parse
import json
import os

PORT = 8000
API_KEY = "11dd61957e19e1f4fd453a52a0fd3b35e83ec8696dc1d39030ac588e8c41b53b"

class Handler(http.server.SimpleHTTPRequestHandler):
    def do_GET(self):
        # API 프록시 요청
        if self.path.startswith("/proxy?"):
            self.handle_proxy()
        # HTML 파일 서빙
        elif self.path == "/" or self.path == "/index.html":
            self.serve_html()
        else:
            super().do_GET()

    def handle_proxy(self):
        try:
            params = urllib.parse.parse_qs(self.path[7:])
            lawd_cd = params.get("LAWD_CD", [""])[0]
            deal_ymd = params.get("DEAL_YMD", [""])[0]

            url = (
                f"https://apis.data.go.kr/1613000/RTMSDataSvcAptTradeDev/getRTMSDataSvcAptTradeDev"
                f"?serviceKey={API_KEY}&LAWD_CD={lawd_cd}&DEAL_YMD={deal_ymd}&numOfRows=1000&pageNo=1"
            )
            req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
            with urllib.request.urlopen(req, timeout=15) as resp:
                data = resp.read()

            self.send_response(200)
            self.send_header("Content-Type", "application/xml; charset=utf-8")
            self.send_header("Access-Control-Allow-Origin", "*")
            self.end_headers()
            self.wfile.write(data)
        except Exception as e:
            self.send_response(500)
            self.send_header("Content-Type", "application/json")
            self.send_header("Access-Control-Allow-Origin", "*")
            self.end_headers()
            self.wfile.write(json.dumps({"error": str(e)}).encode())

    def serve_html(self):
        html_path = os.path.join(os.path.dirname(__file__), "apt-tracker.html")
        with open(html_path, "rb") as f:
            data = f.read()
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, format, *args):
        print(f"[서버] {args[0]} {args[1]}")

if __name__ == "__main__":
    os.chdir(os.path.dirname(os.path.abspath(__file__)))
    print(f"✅ 실거래가 모니터 서버 시작!")
    print(f"👉 브라우저에서 http://localhost:{PORT} 접속하세요")
    print(f"🛑 종료하려면 Ctrl+C")
    print("-" * 40)
    with http.server.HTTPServer(("", PORT), Handler) as httpd:
        httpd.serve_forever()
