#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""全国の市区町村を、総務省の全国地方公共団体コードから取る。

  /usr/bin/python3 scripts/fetch_cities.py

出典: 総務省「全国地方公共団体コード」（市区町村コード）
      https://www.soumu.go.jp/denshijiti/code.html
      政府標準利用規約(第2.0版)。出典表示のうえ商用利用可。

**手続きの窓口は市区町村なので、ここが土台になる。** 政令市は区まで持つ（死亡届も
世帯主変更も区役所に出すため。市で1本にすると「名古屋市中区」の人が行き先を間違える）。
"""
from __future__ import annotations

import json
import os
import re
import sys
import urllib.request
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "data", "cities.json")
XLSX = os.path.join(ROOT, "data", "soumu_code.xlsx")
URL = "https://www.soumu.go.jp/main_content/000925835.xlsx"
UA = {"User-Agent": "kacnavi/1.0 (+https://kurage.exbridge.jp/)"}
NS = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"


def sheet_rows(z: zipfile.ZipFile, path: str) -> list[list[str]]:
    """xlsx を openpyxl 無しで読む（共有文字列＋行）。"""
    import xml.etree.ElementTree as ET

    shared = []
    if "xl/sharedStrings.xml" in z.namelist():
        for si in ET.fromstring(z.read("xl/sharedStrings.xml")):
            # **rPh（ふりがな）の中の t を拾ってはいけない。** 素通しで集めると
            # 「色丹村」が「色丹村シコタンムラ」に、「滝沢市」が「滝沢市シ」になる（2026-09-24 実測）。
            parts = []
            for child in si:
                if child.tag == NS + "rPh":
                    continue
                parts += [t.text or "" for t in child.iter(NS + "t")] if child.tag == NS + "r" else (
                    [child.text or ""] if child.tag == NS + "t" else [])
            shared.append("".join(parts))
    rows = []
    for row in ET.fromstring(z.read(path)).iter(NS + "row"):
        cells = {}
        for c in row.iter(NS + "c"):
            ref = c.get("r") or ""
            col = re.sub(r"\d", "", ref)
            v = c.find(NS + "v")
            txt = ""
            if c.get("t") == "s" and v is not None:
                txt = shared[int(v.text)]
            elif c.get("t") == "inlineStr":
                txt = "".join(t.text or "" for t in c.iter(NS + "t"))
            elif v is not None:
                txt = v.text or ""
            cells[col] = txt.strip()
        if cells:
            width = max(ord(k[-1]) - 64 + (26 * (ord(k[0]) - 64) if len(k) > 1 else 0) for k in cells)
            rows.append([cells.get(chr(64 + i) if i <= 26 else "A" + chr(64 + i - 26), "") for i in range(1, width + 1)])
    return rows


def main() -> int:
    if not os.path.exists(XLSX):
        os.makedirs(os.path.dirname(XLSX), exist_ok=True)
        with urllib.request.urlopen(urllib.request.Request(URL, headers=UA), timeout=180) as r:
            open(XLSX, "wb").write(r.read())
        print(f"  取得 {os.path.getsize(XLSX):,}B")

    z = zipfile.ZipFile(XLSX)
    # シート1=全団体（政令市は市で1行）、シート2=政令指定都市の区。**両方要る。**
    # 死亡届も世帯主変更も政令市では区役所に出すので、市で1本にすると行き先を間違える
    # （kminpaku で大阪市中央区を市で1本にして誤判定した前例）。
    out, prefs, seen = [], {}, set()
    for sheet in ("xl/worksheets/sheet1.xml", "xl/worksheets/sheet2.xml"):
        rows = sheet_rows(z, sheet)
        n0 = len(out)
        for r in rows[1:]:
            code = (r[0] or "").strip()
            if not re.fullmatch(r"\d{6}", code) or code in seen:
                continue
            pref, city = (r[1] or "").strip(), ((r[2] or "").strip() if len(r) > 2 else "")
            kana_pref = (r[3] or "").strip() if len(r) > 3 else ""
            kana_city = (r[4] or "").strip() if len(r) > 4 else ""
            if not city:  # 都道府県そのものの行
                prefs.setdefault(code[:2], {"code": code, "pref": pref, "kana": kana_pref})
                continue
            seen.add(code)
            ku = city.endswith("区") and pref != "東京都"
            out.append({"code": code, "pref_code": code[:2], "pref": pref, "city": city,
                        "kana": kana_city, "seirei_ku": ku})
        print(f"  {sheet.split('/')[-1]}: {len(rows)}行 → {len(out) - n0}件")
    json.dump({"prefs": prefs, "cities": out}, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"→ {OUT} 都道府県 {len(prefs)} / 市区町村 {len(out):,}")
    kinds = {}
    for c in out:
        k = "区" if c["city"].endswith("区") else ("市" if c["city"].endswith("市") else
             ("町" if c["city"].endswith("町") else ("村" if c["city"].endswith("村") else "他")))
        kinds[k] = kinds.get(k, 0) + 1
    print("  内訳:", kinds)
    return 0


if __name__ == "__main__":
    sys.exit(main())
