# 실거래가 모니터

국토교통부 아파트 매매 실거래가(data.go.kr `RTMSDataSvcAptTradeDev`)를 지역·기간별로 조회·시각화하는 단일 페이지 앱.

## 구성

| 파일 | 설명 |
|---|---|
| `apt-tracker.html` | 단일 페이지 앱 (UI + 뷰 로직) |
| `apt-tracker.lib.js` | 순수 로직 헬퍼(포맷·평형·통계·매칭·escapeHtml). 브라우저 전역 + Node 양용 |
| `server.py` | 로컬 개발용 Python 서버 (정적 서빙 + 배치 프록시) |
| `batch.php` | PHP 정적 호스팅용 배치 프록시 (server.py 없이 동작) |
| `tests/` | 단위·통합 테스트 (`run.sh`) |
| `config.local.php.example` | API 키 보관 파일 템플릿 |

## 테스트

```bash
./tests/run.sh    # JS 단위·통합(node --test) + Python 단위(server.py parse_slim, unittest)
```

프론트엔드는 `batch.php` 경로로 데이터를 요청한다. **로컬 Python(`server.py`) 과 PHP 정적 호스팅 양쪽에서 동일하게 동작** — `server.py` 가 `/batch` 와 `/batch.php` 를 모두 배치 핸들러로 라우팅한다.

## API 키 설정 (필수)

data.go.kr serviceKey 는 소스에 두지 않는다. 로드 우선순위: 환경변수 `DATA_GO_KR_KEY` → `config.local.php`.

```bash
# PHP 호스팅용
cp config.local.php.example config.local.php   # 값을 실제 키로 교체 (git 제외됨)

# 또는 환경변수 (server.py / PHP 공통)
export DATA_GO_KR_KEY="발급받은_인증키"
```

## 실행

```bash
# 방법 1) Python 개발 서버
export DATA_GO_KR_KEY="..."      # 또는 config.local.php 사용
python3 server.py                # → http://localhost:8000

# 방법 2) PHP 내장 서버 (운영과 동일한 batch.php)
php -S localhost:8000            # → http://localhost:8000/apt-tracker.html
```

정적 호스팅(cafe24 등 PHP 지원 Apache)에 배포할 땐 `apt-tracker.html` + `batch.php` + `config.local.php` 를 올리면 된다. `server.py` 는 로컬 전용.

## 주의

- 실거래가는 신고 기준 원자료로, 해제/정정 건이 포함될 수 있어 참고용이다.
- `config.local.php` 는 절대 커밋하지 말 것(`.gitignore` 처리됨).
