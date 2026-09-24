#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""自由文（故人の状況）を、手元のLLMで条件タグに変える。**外部へは1バイトも出さない。**

  /usr/bin/python3 scripts/judge_jevlocal.py "父が3日前に亡くなった。年金受給者。持ち家あり。世帯主だった。国民健康保険。"
  /usr/bin/python3 scripts/judge_jevlocal.py --compare "…"   # 辞書の照合と突き合わせる

なぜ手元でやるか。ここに入るのは**故人の財産・保険・家族構成**です。
外部APIへ投げる設計にはできません（jev-ultrafast をそのまま使えなかったのと同じ理由）。

jevlocal（`/home/kojima/work/jevlocal`）に、条件1つにつき「はい／いいえ」の選択肢を渡します。
文章は1トークンも生成せず、2つの選択肢のロジットを1回の順伝播で読むだけです。

本番（heteml の PHP）は辞書の照合で動きます。これは**自分のサーバーに置く版**のための道具で、
「持ち家」と書いていない『実家の土地の名義が父のまま』のような言い方を拾うためのものです。
"""
from __future__ import annotations

import json
import os
import sqlite3
import sys
import time
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "php", "kacnavi_data", "kacnavi.sqlite")
URL = os.environ.get("JEVLOCAL_URL", "http://127.0.0.1:18370") + "/v1/systemone"
BATCH = int(os.environ.get("KACNAVI_JUDGE_BATCH", "8"))


def ask(state: dict, questions: dict) -> dict:
    body = json.dumps({"model": "jevlocal", "state": state, "questions": questions}).encode("utf-8")
    req = urllib.request.Request(URL, data=body, headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=300) as r:
        return json.loads(r.read())


def judge(text: str, conds: list[dict], threshold: float = 0.5) -> tuple[list[str], dict]:
    """条件ごとに「当てはまる／当てはまらない／書かれていない」を選ばせる。

    **「書かれていない」を選べるようにするのが要**。はい／いいえの2択にすると、
    文章に出てこない話まで「いいえ」と断定され、必要な手続きが消える。
    消すのは『違う』と書いてあるときだけにする。
    """

    hits, detail = [], {}
    for i in range(0, len(conds), BATCH):
        chunk = conds[i:i + BATCH]
        qs = {}
        for c in chunk:
            qs[c["id"]] = {
                "type": "choice",
                # **調べたい条件は criteria ではなく instructions（criterion）に置く。**
                # 選択肢の文それぞれに条件を書き込むと、3つとも似た文になって
                # 差が出ず、37項目すべてが unknown（確信0.985）になった（2026-09-24 実測）。
                # 選択肢は短い共通語にして、問いのほうに条件を入れると当たる。
                "instructions": {"question": f"この文章から、亡くなった方は「{c['label']}」と言えるか"},
                "criteria": {"yes": "当てはまる", "no": "当てはまらない",
                             "unknown": "文章からは分からない"},
            }
        a = ask({"故人について遺族が書いた文章": text}, qs)["answers"]
        for c in chunk:
            r = a.get(c["id"], {})
            detail[c["id"]] = {"label": c["label"], "choice": r.get("choice"),
                               "p": r.get("probabilities", {})}
            if r.get("choice") == "yes" and float(r.get("probabilities", {}).get("yes", 0)) >= threshold:
                hits.append(c["id"])
    return hits, detail


def by_dictionary(text: str, conds: list[dict]) -> list[str]:
    """PHP と同じ辞書の照合。突き合わせ用。"""
    out = []
    for c in conds:
        for w in json.loads(c["kw"] or "[]"):
            if w and w in text:
                out.append(c["id"])
                break
    return out


def combine(dic: list[str], hits: list[str], detail: dict, veto: float = 0.75) -> tuple[list[str], list[str], list[str]]:
    """辞書の結果を土台に、LLM が拾った分を足す。**消すのは打ち消しが明らかなときだけ。**

    Qwen3.5-4B 単独では足りないことを実測した（2026-09-24）。
    「主人が勤務先で倒れて」→ 亡くなったとき働いていた を no（0.60）、
    「高校生の娘がいます」→ 18歳までの子がいる を no（0.50）と外す。
    一方、辞書は「遺言らしきものは何も残していません」を『遺言』の1語で拾ってしまう。

    得意が逆なので、両方使う:
      辞書 … 書いてある語に強い（取りこぼさない）
      LLM  … 言い換えに強い（「デイに通っていて」→介護サービス、
              「実家の土地の名義は母のまま」→家や土地、「兄と私の2人」→相続人2人以上）
      LLM の no … 打ち消しに強い。ここだけ辞書を取り消す
    """
    out = list(dic)
    added = [c for c in hits if c not in out]
    out += added
    vetoed = []
    for c in list(out):
        d = detail.get(c) or {}
        if d.get("choice") == "no" and float(d.get("p", {}).get("no", 0)) >= veto:
            out.remove(c)
            vetoed.append(c)
    return out, added, vetoed


def main() -> int:
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    compare = "--compare" in sys.argv
    if not args:
        print(__doc__)
        return 1
    text = args[0]

    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    conds = [dict(r) for r in con.execute("SELECT id, label, kw FROM conditions ORDER BY sort")]

    t0 = time.time()
    hits, detail = judge(text, conds)
    dt = time.time() - t0

    print(f"入力: {text}")
    print(f"\n手元のLLMが当てはまると判断: {len(hits)}件（{len(conds)}項目を {dt:.1f}秒・外部通信ゼロ）")
    for cid in hits:
        d = detail[cid]
        print(f"  ○ {d['label']}（確信 {float(d['p'].get('yes', 0)):.2f}）")

    lab = {c["id"]: c["label"] for c in conds}
    dic = by_dictionary(text, conds)
    final, added, vetoed = combine(dic, hits, detail)
    print(f"辞書の照合: {len(dic)}件")
    for c in added:
        print(f"  ＋ LLMが足した（辞書に無い言い回し）: {lab[c]}　確信 {float(detail[c]['p'].get('yes', 0)):.2f}")
    for c in vetoed:
        print(f"  － LLMが取り消した（打ち消しの表現）: {lab[c]}　no {float(detail[c]['p'].get('no', 0)):.2f}")

    if compare:
        print("\n（参考）LLM だけの結果との差")
        for c in dic:
            if c not in hits:
                d = detail.get(c, {})
                print(f"  LLMが落としていた: {lab[c]}　{d.get('choice')} "
                      f"yes={float(d.get('p', {}).get('yes', 0)):.2f}")

    print(f"\n最終: {len(final)}件")
    print(f"→ https://kurage.exbridge.jp/kacnavi.php/?c={','.join(final)}" if final else "→ 条件なし")
    return 0


if __name__ == "__main__":
    sys.exit(main())
