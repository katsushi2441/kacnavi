#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""公開中のページで、画像が縦横に変形していないかを実測する。

  /usr/bin/python3 scripts/check_images.py

**マスコットを横につぶしていた。** 265×300 の画像に width/height 属性を付けたまま
CSS で幅だけ縮めたので、属性の height=300 が残り、1280pxで 190×300（横28%つぶれ）、
390pxでは 128×300（半分近くつぶれ）で表示されていた（2026-09-24 実測）。
CSS を読むだけでは気づけない。**描画後の箱の縦横比を、元画像の比と突き合わせる。**
"""
from playwright.sync_api import sync_playwright
import json
URLS = {"top":"/", "about":"/about", "cities":"/cities"}
with sync_playwright() as pw:
    br = pw.chromium.launch(args=["--no-sandbox"])
    for w in (390, 1280):
        ctx = br.new_context(viewport={"width": w, "height": 900})
        pg = ctx.new_page()
        for name, path in URLS.items():
            pg.goto("https://kurage.exbridge.jp/kacnavi.php" + path, wait_until="load", timeout=60000)
            rows = pg.evaluate("""() => [...document.images].map(i => ({
                src: i.currentSrc.split('/').pop(),
                nat: i.naturalWidth + 'x' + i.naturalHeight,
                box: Math.round(i.getBoundingClientRect().width) + 'x' + Math.round(i.getBoundingClientRect().height),
                fit: getComputedStyle(i).objectFit,
                natR: (i.naturalWidth / i.naturalHeight),
                boxR: (i.getBoundingClientRect().width / i.getBoundingClientRect().height),
            }))""")
            for r in rows:
                bad = ""
                if r["fit"] in ("fill",) and abs(r["natR"] - r["boxR"]) > 0.02:
                    bad = "  ← 比率が崩れている（つぶれ/伸び）"
                elif r["fit"] == "cover" and abs(r["natR"] - r["boxR"]) > 0.02:
                    bad = "  ← cover で切り取られている"
                print(f"{w:>5}px {name:<7} {r['src']:<34} 元{r['nat']:>9} → 枠{r['box']:>9} object-fit:{r['fit']}{bad}")
        ctx.close()
    br.close()
