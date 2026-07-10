"""server.py parse_slim 단위 테스트. 실거래가 XML → slim dict 변환 검증."""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from server import parse_slim  # noqa: E402


def _xml(items):
    body = ''.join(items)
    return f'<response><body><items>{body}</items></body></response>'


ITEM_OK = (
    '<item><aptNm>강남데시앙파크</aptNm><dealAmount>110,000</dealAmount>'
    '<excluUseAr>84.95</excluUseAr><floor>2</floor>'
    '<dealYear>2024</dealYear><dealMonth>1</dealMonth><dealDay>31</dealDay></item>'
)


class ParseSlimTest(unittest.TestCase):
    def test_valid_row(self):
        rows = parse_slim(_xml([ITEM_OK]))
        self.assertEqual(len(rows), 1)
        r = rows[0]
        self.assertEqual(r['n'], '강남데시앙파크')
        self.assertEqual(r['p'], 110000)   # 콤마 제거 + int
        self.assertEqual(r['a'], '84.95')
        self.assertEqual(r['y'], '2024')

    def test_cancelled_excluded(self):
        cancelled = ITEM_OK.replace('<floor>2</floor>', '<floor>2</floor><cdealType>해제</cdealType>')
        self.assertEqual(parse_slim(_xml([cancelled])), [])

    def test_invalid_rows_skipped(self):
        no_price = '<item><aptNm>A</aptNm><dealAmount></dealAmount><dealYear>2024</dealYear></item>'
        zero_price = '<item><aptNm>A</aptNm><dealAmount>0</dealAmount><dealYear>2024</dealYear></item>'
        no_name = '<item><aptNm></aptNm><dealAmount>50000</dealAmount><dealYear>2024</dealYear></item>'
        bad_year = '<item><aptNm>A</aptNm><dealAmount>50000</dealAmount><dealYear>--</dealYear></item>'
        rows = parse_slim(_xml([no_price, zero_price, no_name, bad_year, ITEM_OK]))
        self.assertEqual(len(rows), 1)  # ITEM_OK 만 통과

    def test_malformed_xml_returns_empty(self):
        self.assertEqual(parse_slim('<not xml'), [])
        self.assertEqual(parse_slim(''), [])

    def test_whitespace_price(self):
        spaced = ITEM_OK.replace('<dealAmount>110,000</dealAmount>', '<dealAmount> 90,500 </dealAmount>')
        rows = parse_slim(_xml([spaced]))
        self.assertEqual(rows[0]['p'], 90500)


if __name__ == '__main__':
    unittest.main()
