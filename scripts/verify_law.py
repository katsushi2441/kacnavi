#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""procedures.json に書いた条文の引用が、本当にその条文かを1件ずつ突き合わせる。

  /usr/bin/python3 scripts/verify_law.py

**なぜ要るか。** 期限は改正で動く。相続登記の3年は2024年4月1日施行で、それ以前は
義務ですらなかった。条番号だけ持っていても、中身が入れ替われば静かに嘘になる。
ここで落とせば、公開されている画面が嘘をつく前に気づける。

見るもの:
  1) 引いた条番号が data/law.json にあるか
  2) expect に書いた語（七日・三箇月…）が、その条文の本文に出てくるか
  3) deadline の日数と、条文の語が合っているか（14日と書いて条文が「十日」なら落とす）
"""
from __future__ import annotations

import json
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

KANSUJI = {1: "一", 2: "二", 3: "三", 4: "四", 5: "五", 6: "六", 7: "七", 8: "八", 9: "九",
           10: "十", 14: "十四", 15: "十五", 90: "九十", 10_0: "十"}


def kanji(n: int) -> str:
    if n in KANSUJI:
        return KANSUJI[n]
    if n < 100:
        t, o = divmod(n, 10)
        return ("十" if t == 1 else KANSUJI[t] + "十") + (KANSUJI.get(o, "") if o else "")
    return ""


def unit_words(n: int, unit: str) -> list[str]:
    """14日 → ["十四日"]、3か月 → ["三箇月", "三月"]、10か月 → ["十箇月", "十月"]。

    法令の書き方は一定でない。所得税法125条は「四月を経過した日の前日」、
    相続税法27条は「十月以内」で、どちらも「箇月」を使わない。
    """
    k = kanji(n)
    if not k:
        return []
    if unit == "日":
        return [k + "日"]
    if unit == "か月":
        return [k + "箇月", k + "月"]
    if unit == "年":
        return [k + "年"]
    return []


def main() -> int:
    law = json.load(open(os.path.join(ROOT, "data", "law.json"), encoding="utf-8"))
    pro = json.load(open(os.path.join(ROOT, "data", "procedures.json"), encoding="utf-8"))

    bad, checked = [], 0
    for p in pro["procedures"]:
        for c in p.get("law", []):
            checked += 1
            name, art = c["name"], c["article"]
            if name not in law:
                bad.append(f"{p['id']}: 法令 {name} を data/law.json が持っていない（fetch_law.py の LAWS に足す）")
                continue
            a = law[name]["articles"].get(art)
            if not a:
                bad.append(f"{p['id']}: {name} 第{art}条 が存在しない")
                continue
            expect = c.get("expect")
            if expect and expect not in a["text"]:
                bad.append(f"{p['id']}: {name}{a['title']} の本文に「{expect}」が無い（{a['caption']}）")

        # 期限の数字と、引いた条文の語が合っているか
        d = p.get("deadline") or {}
        if d.get("kind") in ("期限", "時効") and d.get("n") and p.get("law"):
            words = unit_words(d["n"], d.get("unit", ""))
            if words:
                texts = [law[c["name"]]["articles"][c["article"]]["text"]
                         for c in p["law"]
                         if c["name"] in law and c["article"] in law[c["name"]]["articles"]]
                if texts and not any(w in t for w in words for t in texts):
                    bad.append(f"{p['id']}: 期限 {d['n']}{d.get('unit')} に当たる語 {words} が、引いた条文のどれにも無い")

    # 条件タグの綴り違い
    ids = {c["id"] for c in pro["conditions"]}
    for p in pro["procedures"]:
        for k in ("need", "need_any", "unneeded_if"):
            for t in p.get(k, []):
                if t not in ids:
                    bad.append(f"{p['id']}: 条件タグ {t} が conditions に無い")
    # 使われていない条件タグ
    used = {t for p in pro["procedures"] for k in ("need", "need_any", "unneeded_if") for t in p.get(k, [])}
    unused = sorted(ids - used)

    print(f"条文の引用 {checked}件を検算")
    for b in bad:
        print("  ✗ " + b)
    if unused:
        print(f"  ・どの手続きの条件にも使っていないタグ {len(unused)}件: {'・'.join(unused)}")
    if bad:
        print(f"\n{len(bad)}件おかしい。直すまで公開しない")
        return 1
    print("  すべて条文と一致")
    return 0


if __name__ == "__main__":
    sys.exit(main())
