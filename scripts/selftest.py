#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""公開する前に、画面が嘘をついていないかを確かめる。

  /usr/bin/python3 scripts/selftest.py

**kminpaku で、京都府の全住所を「未収録」と出したまま公開しかけた。**
市区町村名を最短一致で切って「京都」と読んでいたのが原因で、画面を1つも見ていなかった。
ここでは PHP を実際に走らせ、出てきた HTML の中身を1件ずつ見る。
"""
from __future__ import annotations

import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP = os.path.join(ROOT, "php", "kacnavi.php")


# **PHP の CLI は QUERY_STRING から $_GET を作らない。** `php -f` で試すと、
# 条件を1つも選んでいない扱いになり、絞り込みの検査が全部すり抜ける（2026-09-24 実測）。
# 本番と同じ経路で見るために、組み込みサーバーを立てて HTTP で叩く。
_SRV = None
_PORT = 18394


def _server():
    global _SRV
    if _SRV is None:
        router = os.path.join(ROOT, "scripts", "_router.php")
        open(router, "w", encoding="utf-8").write(
            "<?php $u=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);"
            "if(strpos($u,'/kacnavi.php')===0){$_SERVER['PATH_INFO']=substr($u,strlen('/kacnavi.php'));"
            "require __DIR__.'/../php/kacnavi.php';return true;} return false;\n")
        _SRV = subprocess.Popen(["php", "-S", f"127.0.0.1:{_PORT}", "-t", os.path.join(ROOT, "php"), router],
                                stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        import atexit, time
        atexit.register(_SRV.terminate)
        for _ in range(60):
            try:
                urllib.request.urlopen(f"http://127.0.0.1:{_PORT}/kacnavi.php/", timeout=2).read()
                break
            except Exception:
                time.sleep(0.2)
    return _SRV


def get(path: str, qs: str = "") -> str:
    _server()
    url = f"http://127.0.0.1:{_PORT}/kacnavi.php" + urllib.parse.quote(path) + (("?" + qs) if qs else "")
    try:
        with urllib.request.urlopen(url, timeout=60) as r:
            return r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:      # 404 も中身を見たい
        return e.read().decode("utf-8", "replace")


def strip(html: str) -> str:
    """見える文字だけにする。**script と style の中身は先に落とす** ——
    構造化データ（JSON-LD）の FAQ 本文に手続き名が入っており、落とさないと
    『出ていないはずのものが出ている』と誤判定する（2026-09-24 実測）。"""
    html = re.sub(r"<(script|style)\b[^>]*>.*?</\1>", " ", html, flags=re.S | re.I)
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html))


CHECKS = []


def check(name):
    def deco(fn):
        CHECKS.append((name, fn))
        return fn
    return deco


@check("トップに48件すべてが出る（条件を選ばない人は全部見える）")
def t1():
    t = strip(get("/"))
    assert "全48件" in t or "48件" in t, "件数が出ていない"
    for nm in ("死亡届", "相続放棄", "準確定申告", "相続登記"):
        assert nm in t, f"{nm} が無い"


@check("条件を選ぶと、当てはまらない手続きが消える")
def t2():
    t = strip(get("/", "c=nenkin"))
    assert "未支給年金の請求" in t, "年金を選んだのに未支給年金が出ない"
    assert "農地を相続したときの届出" not in t, "農地を選んでいないのに農地の届出が出ている"
    assert "山林（森林）を相続したときの届出" not in t, "山林の届出が出ている"


@check("マイナンバー収録済みなら、年金の死亡届に『出さなくてよい』が出る")
def t3():
    t = strip(get("/", "c=nenkin,mynumber_nenkin"))
    assert "年金受給権者死亡届" in t, "年金の死亡届が消えている（消してはいけない）"
    assert "出さなくてよい場合があります" in t, "不要かもしれない印が出ていない"


@check("世帯主変更届は、残る人が2人以上のときだけ出る")
def t4():
    one = strip(get("/", "c=setainushi"))
    two = strip(get("/", "c=setainushi,nokoru2"))
    assert "世帯主変更届" not in one, "1人しか残らないのに世帯主変更届が出ている"
    assert "世帯主変更届" in two, "2人以上残るのに世帯主変更届が出ない"


@check("誰にでも要る手続きは、何も選ばなくても出る")
def t5():
    t = strip(get("/", "c=car"))
    for nm in ("死亡届", "火葬許可申請", "死亡診断書"):
        assert nm in t, f"{nm} が消えている"
    assert "自動車の移転登録" in t


@check("死亡届の詳細に、戸籍法86条の条文そのものが出る")
def t6():
    t = strip(get("/p/shibo-todoke"))
    assert "死亡の事実を知つた日から七日以内" in t, "条文が出ていない"
    assert "戸籍法" in t and "第八十六条" in t
    assert "laws.e-gov.go.jp" in get("/p/shibo-todoke"), "e-Gov へのリンクが無い"


@check("市区町村ページに、その市区町村にしか書けない数字が出る")
def t7():
    a = strip(get("/city/愛知県/名古屋市中区"))
    b = strip(get("/city/東京都/千代田区"))
    assert "841" in a, "名古屋市中区の死亡者数841が出ていない"
    assert "463" in b, "千代田区の死亡者数463が出ていない"
    # 2枚が同じ中身になっていないこと（xb4g で289枚が似すぎて索引に入らなかった）
    assert a != b
    assert "名古屋市中区役所" in a, "政令市の区なのに区役所と書いていない"


@check("政令市の市と区が両方あり、区が窓口だと分かる")
def t8():
    t = strip(get("/city/大阪府/大阪市中央区"))
    assert "大阪市は政令指定都市" in t, "政令市の説明が出ていない"
    assert "大阪市中央区役所" in t


@check("都道府県ページの合計が、区を二重に数えていない")
def t9():
    t = strip(get("/pref/愛知県"))
    m = re.search(r"1年間におよそ([\d,]+)人", t)
    assert m, "合計が出ていない"
    v = int(m.group(1).replace(",", ""))
    # 愛知県の年間死亡者数はおよそ7万人台。区を重ねて数えると10万を超える
    assert 60_000 < v < 90_000, f"愛知県の合計が {v:,}人。区を二重に数えていないか"


@check("サイトマップに全ページが入り、索引の中に索引を入れていない")
def t10():
    x = get("/sitemap.xml")
    locs = re.findall(r"<loc>([^<]+)</loc>", x)
    assert "<sitemapindex" not in x, "索引の中に索引を入れている（仕様違反）"
    assert len(locs) > 1900, f"URLが {len(locs)}件しかない"
    assert all(u.startswith("https://kurage.exbridge.jp/kacnavi.php/") for u in locs)
    assert len(set(locs)) == len(locs), "重複したURLがある"
    assert sum(1 for u in locs if "/city/" in u) == 1912


@check("北方領土の6村のページを作っていない")
def t11():
    x = get("/sitemap.xml")
    for v in ("色丹村", "留夜別村", "蘂取村"):
        assert v not in x, f"{v} のページを作っている（役場が機能していない）"


@check("JavaScript が無くても、チェックボックスだけで絞れる")
def t12():
    t = strip(get("/", "k%5B%5D=noka&k%5B%5D=fudosan"))
    assert "農地を相続したときの届出" in t, "k[] で送っても絞れていない"
    assert "相続登記" in t


@check("API が JSON を返し、条文の引用を持っている")
def t13():
    import json
    d = json.loads(get("/api/check", "c=fudosan,yokin"))
    assert d["count"] > 0
    ids = [p["id"] for p in d["procedures"]]
    assert "souzoku-toki" in ids and "yokin-kouza" in ids
    toki = [p for p in d["procedures"] if p["id"] == "souzoku-toki"][0]
    assert toki["law"][0]["name"] == "不動産登記法"


@check("無いページは404を返す")
def t14():
    for p in ("/p/nothing", "/city/愛知県/存在しない市", "/pref/架空県", "/when/g99"):
        t = strip(get(p))
        assert "見つかりません" in t, f"{p} が404になっていない"


@check("すべてのページが横スクロールしない作りになっている")
def t15():
    html = get("/city/愛知県/名古屋市中区")
    assert "width:min(920px,100% - 32px)" in html, "外枠の横幅指定が無い"
    assert 'class="tscroll"' in get("/pref/愛知県"), "表を包む overflow-x が無い"


@check("文章を書いて送ると、そこから状況を読み取る")
def t16():
    import urllib.parse as up
    t = strip(get("/", "q=" + up.quote("母が亡くなりました。要介護3でデイに通っていました。持ち家があり、兄と私の2人です。")))
    assert "読み取った状況" in t, "文章から読み取った旨が出ていない"
    assert "介護保険の被保険者証を持っていた" in t
    assert "家や土地を持っていた" in t
    assert "介護保険の資格喪失" in t, "介護保険の手続きが出ていない"
    assert "農地を相続したときの届出" not in t, "書いていない農地の手続きが出ている"


@check("文章の欄が画面にある")
def t17():
    html = get("/")
    assert 'name="q"' in html, "自由文の入力欄が無い"
    assert "どこにも送りません" in html


@check("画像を縦横に変形させていない（width/height 属性＋CSS幅の事故）")
def t18():
    """**マスコットを横につぶしていた。** 265×300 に width/height 属性を付けたまま
    CSS で幅だけ縮めたので、属性の height=300 が残り 190×300（横28%つぶれ）、
    390px では 128×300（半分近くつぶれ）になっていた（2026-09-24 実測）。
    CSS を読んで、幅を指定する img に height:auto が添えてあるかを見る。"""
    css = get("/")
    import re
    for m in re.finditer(r"\.hero img\{([^}]*)\}", css):
        body = m.group(1)
        if "width" in body and "height:auto" not in body:
            raise AssertionError(f"幅を変える img に height:auto が無い: {body}")
    assert ".hero img{width:100%;max-width:190px;height:auto" in css
    assert "max-width:128px;height:auto" in css
    # ヘッダーの印は切り取らない
    assert "object-fit:contain" in css and "object-fit:cover" not in css


@check("人が着地するページすべてに、オンプレミス版への導線がある")
def t19():
    """**フッターの1行だけでは読まれない。** kappstore は28日18クリックで、
    流入のある下層ページから導線が1本も無かった（2026-09-24 実測）。
    検索で人が着地するページ（市区町村・手続き詳細・時期別・トップ）の
    本文に案内を置く。"""
    # **get() の中で1回だけエンコードする。** ここで quote すると二重になって404になる
    pages = {"トップ": "/", "市区町村": "/city/愛知県/名古屋市中区",
             "手続き詳細": "/p/souzoku-toki", "時期別": "/when/g1",
             "都道府県": "/pref/愛知県", "このサイトについて": "/about"}
    for name, path in pages.items():
        html = get(path)
        assert "app.php?id=60a6c07508d735ac" in html, f"{name} に商品ページへのリンクが無い"
        assert 'class="panel store"' in html or path == "/cities", f"{name} に本文の案内カードが無い"
        assert "ref=kacnavi-" in html, f"{name} のリンクに ref が無い（流入元が分からない）"


@check("題名が、実際に出しているもの（表）を名乗っている")
def t20():
    """「一覧」より「一覧表」のほうが検索されている（死亡後の手続き一覧表1,600／
    親が亡くなった時の手続き一覧表2,400）。このページは実際に表なので言い換えではない。"""
    import re
    t = re.search(r"<title>([^<]*)", get("/")).group(1)
    assert "一覧表" in t, f"題名に「一覧表」が無い: {t}"
    assert "親が亡くなったらすること" in t
    assert "<table" in get("/"), "一覧表と名乗っているのに表が無い"


def main() -> int:
    ng = 0
    for name, fn in CHECKS:
        try:
            fn()
            print(f"  ○ {name}")
        except AssertionError as e:
            print(f"  ✗ {name}\n      {e}")
            ng += 1
        except Exception as e:
            print(f"  ✗ {name}\n      {type(e).__name__}: {e}")
            ng += 1
    print(f"\n{len(CHECKS)}件中 {len(CHECKS) - ng}件が通った")
    return 1 if ng else 0


if __name__ == "__main__":
    sys.exit(main())
