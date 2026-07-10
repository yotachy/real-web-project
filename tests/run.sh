#!/usr/bin/env bash
# 실거래가 모니터(real/) 테스트 — JS 단위 + Python 단위.
# exit 0 = 전체 통과.
set -uo pipefail
cd "$(dirname "$0")/.."

fail=0

echo "▶ JS 단위 (apt-tracker.lib.js)"
node --test tests/apt-tracker.test.js || fail=1

echo
echo "▶ Python 단위 (server.py parse_slim)"
python3 -m unittest tests.test_server || fail=1
rm -rf __pycache__ tests/__pycache__ 2>/dev/null

echo
if [ "$fail" -eq 0 ]; then echo "✓ real/ ALL GREEN"; else echo "✗ real/ 일부 실패"; fi
exit "$fail"
