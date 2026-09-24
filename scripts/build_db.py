#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""data/*.json から公開用の SQLite を1本作る。

  /usr/bin/python3 scripts/build_db.py

出力: php/kacnavi_data/kacnavi.sqlite（PHP1ファイルが読む）

heteml の SQLite には **R*Tree も FTS5 も無い**ことがある（kminpaku で `no such module: rtree`
を本番で踏んだ）。ここでは普通の表と索引だけを使う。
"""
from __future__ import annotations

import json
import os
import re
import sqlite3
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "php", "kacnavi_data", "kacnavi.sqlite")

# 北方領土の6村。総務省のコードには載るが役場が機能していない。窓口ページを作ってはいけない。
HOPPOU = {"016951", "016969", "016977", "016985", "016993", "017001"}

SCHEMA = """
PRAGMA journal_mode=DELETE;
DROP TABLE IF EXISTS meta; DROP TABLE IF EXISTS groups; DROP TABLE IF EXISTS conditions;
DROP TABLE IF EXISTS procedures; DROP TABLE IF EXISTS cities; DROP TABLE IF EXISTS laws;
DROP TABLE IF EXISTS law_articles;
CREATE TABLE meta(k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE groups(id TEXT PRIMARY KEY, name TEXT, descr TEXT, sort INTEGER);
CREATE TABLE conditions(id TEXT PRIMARY KEY, label TEXT, cat TEXT, kw TEXT, sort INTEGER);
CREATE TABLE procedures(
  id TEXT PRIMARY KEY, name TEXT, grp TEXT,
  dl_kind TEXT, dl_n INTEGER, dl_unit TEXT, dl_from TEXT, dl_text TEXT, dl_short TEXT, dl_days INTEGER,
  where_kind TEXT, where_txt TEXT, who TEXT, why TEXT,
  docs TEXT, fee TEXT, need TEXT, need_any TEXT, unneeded_if TEXT, unneeded_text TEXT,
  law TEXT, sort INTEGER);
CREATE INDEX ix_p_grp ON procedures(grp, sort);
CREATE INDEX ix_p_where ON procedures(where_kind);
CREATE TABLE cities(
  code TEXT PRIMARY KEY, pref_code TEXT, pref TEXT, city TEXT, kana TEXT,
  seirei_ku INTEGER, parent TEXT, slug TEXT,
  pop INTEGER, setai INTEGER, death INTEGER, death_rank INTEGER, death_total INTEGER,
  death_per1k REAL, rate_rank INTEGER);
CREATE INDEX ix_c_pref ON cities(pref_code, code);
CREATE INDEX ix_c_slug ON cities(pref, slug);
CREATE TABLE laws(name TEXT PRIMARY KEY, law_id TEXT, title TEXT, url TEXT, enforcement TEXT);
CREATE TABLE law_articles(law TEXT, article TEXT, title TEXT, caption TEXT, text TEXT,
  PRIMARY KEY(law, article));
"""

KANA_H2Z = str.maketrans(
    {**{chr(0xFF61 + i): c for i, c in enumerate("｡｢｣､･ｦｧｨｩｪｫｬｭｮｯｰｱｲｳｴｵｶｷｸｹｺｻｼｽｾｿﾀﾁﾂﾃﾄﾅﾆﾇﾈﾉﾊﾋﾌﾍﾎﾏﾐﾑﾒﾓﾔﾕﾖﾗﾘﾙﾚﾛﾜﾝﾞﾟ")}})


def to_zenkaku_kana(s: str) -> str:
    """ﾅｺﾞﾔｼ → ナゴヤシ。総務省のファイルは半角カナなので、読みで探せるように直す。"""
    import unicodedata
    return unicodedata.normalize("NFKC", s)


def days_of(d: dict) -> int | None:
    """期限を日数にそろえる（並べ替え用。表示には使わない）。"""
    n, u = d.get("n"), d.get("unit")
    if not n:
        return {"期限": 999, "時効": 9999}.get(d.get("kind"))
    return n * {"日": 1, "か月": 30, "年": 365}.get(u, 1)


def slug_of(city: str) -> str:
    """URL に出す市区町村名。日本語のままにする（漢字のURLは検索結果で読める）。"""
    return city


def main() -> int:
    pro = json.load(open(os.path.join(ROOT, "data", "procedures.json"), encoding="utf-8"))
    cit = json.load(open(os.path.join(ROOT, "data", "cities.json"), encoding="utf-8"))
    st = json.load(open(os.path.join(ROOT, "data", "stats.json"), encoding="utf-8"))
    law = json.load(open(os.path.join(ROOT, "data", "law.json"), encoding="utf-8"))

    os.makedirs(os.path.dirname(DB), exist_ok=True)
    if os.path.exists(DB):
        os.remove(DB)
    con = sqlite3.connect(DB)
    con.executescript(SCHEMA)

    con.executemany("INSERT INTO groups VALUES (?,?,?,?)",
                    [(g["id"], g["name"], g["desc"], i) for i, g in enumerate(pro["groups"])])
    con.executemany("INSERT INTO conditions VALUES (?,?,?,?,?)",
                    [(c["id"], c["label"], c["cat"], json.dumps(c.get("kw", []), ensure_ascii=False), i)
                     for i, c in enumerate(pro["conditions"])])

    rows = []
    for i, p in enumerate(pro["procedures"]):
        d = p.get("deadline") or {}
        rows.append((p["id"], p["name"], p["group"],
                     d.get("kind"), d.get("n"), d.get("unit"), d.get("from"), d.get("text"), d.get("short"), days_of(d),
                     p.get("where_kind"), p.get("where"), p.get("who"), p.get("why"),
                     json.dumps(p.get("docs", []), ensure_ascii=False), p.get("fee"),
                     json.dumps(p.get("need", []), ensure_ascii=False),
                     json.dumps(p.get("need_any", []), ensure_ascii=False),
                     json.dumps(p.get("unneeded_if", []), ensure_ascii=False), p.get("unneeded_text"),
                     json.dumps(p.get("law", []), ensure_ascii=False), i))
    con.executemany("INSERT INTO procedures VALUES (" + ",".join("?" * 22) + ")", rows)

    # 引いた条文だけを持つ（全条文を持つと重い。必要なものは verify_law.py が保証している）
    used = {(c["name"], c["article"]) for p in pro["procedures"] for c in p.get("law", [])}
    con.executemany("INSERT INTO laws VALUES (?,?,?,?,?)",
                    [(n, law[n]["law_id"], law[n]["title"], law[n]["url"],
                      law[n].get("amendment_enforcement_date")) for n in {n for n, _ in used}])
    con.executemany("INSERT INTO law_articles VALUES (?,?,?,?,?)",
                    [(n, a, law[n]["articles"][a]["title"], law[n]["articles"][a]["caption"],
                      law[n]["articles"][a]["text"]) for n, a in used])

    ku_parents = {c["city"].split("市")[0] + "市" for c in cit["cities"] if c["seirei_ku"]}
    crows, skipped = [], 0
    for c in cit["cities"]:
        if c["code"] in HOPPOU:
            skipped += 1
            continue
        s = st["cities"].get(c["code"], {})
        parent = c["city"].split("市")[0] + "市" if c["seirei_ku"] else ""
        crows.append((c["code"], c["pref_code"], c["pref"], c["city"], to_zenkaku_kana(c["kana"]),
                      1 if c["seirei_ku"] else 0, parent, slug_of(c["city"]),
                      s.get("pop"), s.get("setai"), s.get("death"), s.get("death_rank"),
                      s.get("death_total"), s.get("death_per1k"), s.get("rate_rank")))
    con.executemany("INSERT INTO cities VALUES (" + ",".join("?" * 15) + ")", crows)

    con.executemany("INSERT INTO meta VALUES (?,?)", [
        ("asof", pro["asof"]),
        ("stats_asof_pop", st["meta"]["asof_pop"]),
        ("stats_asof_doutai", st["meta"]["asof_doutai"]),
        ("stats_source", st["meta"]["source"]),
        ("stats_source_url", st["meta"]["source_url"]),
        ("death_median", str(int(st["meta"]["death_median"]))),
        ("death_national", str(st["meta"]["death_national"])),
        ("n_procedures", str(len(rows))),
        ("n_cities", str(len(crows))),
        ("n_seirei", str(len(ku_parents))),
    ])
    con.commit()
    con.execute("VACUUM")
    con.close()
    print(f"→ {DB} {os.path.getsize(DB):,}B")
    print(f"  手続き {len(rows)} / 条件 {len(pro['conditions'])} / 市区町村 {len(crows):,}（北方領土 {skipped}村を除外）")
    print(f"  条文 {len(used)}件 / 全国の年間死亡者数 {st['meta']['death_national']:,}人")
    return 0


if __name__ == "__main__":
    sys.exit(main())
