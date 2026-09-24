#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""市区町村ごとの人口・世帯数・年間死亡者数を取る。

  /usr/bin/python3 scripts/fetch_stats.py

出典: 総務省「住民基本台帳に基づく人口、人口動態及び世帯数」
      令和8年1月1日現在の人口・世帯数／令和7年（1〜12月）の人口動態
      https://www.soumu.go.jp/main_sosiki/jichi_gyousei/daityo/jinkou_jinkoudoutai-setaisuu.html
      政府標準利用規約(第2.0版)。出典表示のうえ商用利用可。

**なぜ要るか。** 市区町村ページを1,900枚作っても、中身が同じなら索引に入らない
（xb4g.com の選挙区289枚が `Crawled - currently not indexed` になった前例）。
「この市では1年間に何人が亡くなっているか」は、話題に直結していて、1枚ずつ違う。
数字を足すのではなく、公表されている数字を並べ替えて位置を出す。
"""
from __future__ import annotations

import importlib.util
import json
import os
import re
import statistics
import sys
import urllib.request
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
XLSX = os.path.join(ROOT, "data", "juki_doutai.xlsx")
OUT = os.path.join(ROOT, "data", "stats.json")
URL = "https://www.soumu.go.jp/main_content/000892952.xlsx"
UA = {"User-Agent": "kacnavi/1.0 (+https://kurage.exbridge.jp/)"}

COL = {"pop": 5, "setai": 6, "birth": 10, "death": 16}  # 見出し行で確かめてから使う


def main() -> int:
    if not os.path.exists(XLSX):
        with urllib.request.urlopen(urllib.request.Request(URL, headers=UA), timeout=300) as r:
            open(XLSX, "wb").write(r.read())

    spec = importlib.util.spec_from_file_location("fc", os.path.join(ROOT, "scripts", "fetch_cities.py"))
    fc = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(fc)
    z = zipfile.ZipFile(XLSX)
    rows = fc.sheet_rows(z, "xl/worksheets/sheet1.xml")

    # 見出しが動いていないか毎回見る（列番号の決め打ちは静かに壊れる）
    head = rows[4]
    want = {"pop": "計", "setai": "世帯数", "birth": "出生者数", "death": "死亡者数"}
    for k, i in COL.items():
        if i >= len(head) or head[i] != want[k]:
            print(f"!! 列がずれている: {k} は {i}列目のはずが「{head[i] if i < len(head) else ''}」", file=sys.stderr)
            return 1
    print("  見出し一致:", {k: head[i] for k, i in COL.items()})

    # **cities.json に載っている団体だけを見る。** このファイルには政令市の「市の計」の行と
    # 「区」の行が両方あり、素通しで数えると同じ人を二度数える（全国の死亡者数が
    # 2,070,425人になった。実際の1年間の死亡はおよそ160万人）。
    cj = json.load(open(os.path.join(ROOT, "data", "cities.json"), encoding="utf-8"))["cities"]
    known = {c["code"] for c in cj}
    # 政令市は「市の計」と「区」の両方の行がある。**順位を出すときは市の計を外す**
    # （でないと札幌市の死亡者数を、市で1回・10区で1回ずつ、二重に数える。
    #  外す前の全国合計は1,924,588人だった。実際の1年間の死亡はおよそ160万人）。
    ku_parents = {c["city"].split("市")[0] + "市" for c in cj if c["seirei_ku"]}
    seirei_total = {c["code"] for c in cj if c["city"] in ku_parents}

    out = {}
    for r in rows[6:]:
        code = (r[0] or "").strip()
        if code not in known:
            continue
        city = (r[2] or "").strip()
        if not city or city == "-":   # 都道府県計の行
            continue

        def num(i):
            v = (r[i] or "").replace(",", "").strip() if i < len(r) else ""
            try:
                return int(float(v))
            except ValueError:
                return None
        out[code] = {k: num(i) for k, i in COL.items()}

    # 全国での位置（xb4g の選挙区ページと同じ考え方。数字は足さず、並べ替えて位置を出す）
    rankable = {c: v for c, v in out.items() if c not in seirei_total}
    deaths = sorted(((c, v["death"]) for c, v in rankable.items() if v["death"] is not None), key=lambda x: -x[1])
    med = statistics.median([v for _, v in deaths])
    for i, (c, v) in enumerate(deaths, 1):
        out[c]["death_rank"] = i
        out[c]["death_total"] = len(deaths)
    # 人口あたりの死亡者数（千人あたり）。高齢化の度合いが出る
    rate = sorted(((c, v["death"] / v["pop"] * 1000) for c, v in rankable.items()
                   if v.get("death") and v.get("pop")), key=lambda x: -x[1])
    for i, (c, v) in enumerate(rate, 1):
        out[c]["death_per1k"] = round(v, 1)
        out[c]["rate_rank"] = i
        out[c]["rate_total"] = len(rate)

    meta = {"asof_pop": "2026-01-01", "asof_doutai": "2025年（1〜12月）",
            "source": "総務省 住民基本台帳に基づく人口、人口動態及び世帯数",
            "source_url": "https://www.soumu.go.jp/main_sosiki/jichi_gyousei/daityo/jinkou_jinkoudoutai-setaisuu.html",
            "death_median": med, "death_national": sum(v for _, v in deaths)}
    json.dump({"meta": meta, "cities": out}, open(OUT, "w", encoding="utf-8"), ensure_ascii=False)
    print(f"→ {OUT} {len(out):,}団体 / 死亡者数の中央値 {med:,.0f}人 / 合計 {meta['death_national']:,}人")
    for c in ("231061", "131016", "271284", "014605"):
        v = out.get(c)
        if v:
            print(f"  {c}: 人口{v['pop']:,} 死亡{v['death']:,}人（全国{v['death_rank']}位/{v['death_total']}・千人あたり{v['death_per1k']}）")
    return 0


if __name__ == "__main__":
    sys.exit(main())
