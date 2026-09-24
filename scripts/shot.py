#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""ローカルの kacnavi.php を実際の端末幅で撮り、横はみ出しを実測する。

  /usr/bin/python3 scripts/shot.py <ベースURL> <出力先>

headless chrome の --screenshot は --window-size を「切り取り幅」としてしか扱わないので、
390px で撮ったつもりでも広い版面の左390pxが写るだけになる（khoudei で実測）。
ここでは CDP の Emulation.setDeviceMetricsOverride で本当に端末幅にしてから撮り、
あわせて documentElement.scrollWidth を読んで**はみ出しを数字で確かめる**。
"""
from __future__ import annotations

import base64
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.request
from pathlib import Path

from playwright.sync_api import sync_playwright

WIDTHS = (320, 360, 390, 430)
PAGES = {"top": "/", "result": "/?c=nenkin,fudosan,setainushi,nokoru2,yokin,kokuho,kaigo", "when": "/when/g1", "proc": "/p/souzoku-hoki", "city": "/city/%E6%84%9B%E7%9F%A5%E7%9C%8C/%E5%90%8D%E5%8F%A4%E5%B1%8B%E5%B8%82%E4%B8%AD%E5%8C%BA", "city2": "/city/%E5%8C%97%E6%B5%B7%E9%81%93/%E9%9F%B3%E5%A8%81%E5%AD%90%E5%BA%9C%E6%9D%91", "pref": "/pref/%E6%84%9B%E7%9F%A5%E7%9C%8C", "cities": "/cities", "about": "/about"}


def main() -> int:
    base = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:18398/kacnavi.php"
    out = Path(sys.argv[2] if len(sys.argv) > 2 else "outputs/shots")
    out.mkdir(parents=True, exist_ok=True)
    bad = 0
    with sync_playwright() as pw:
        # **chrome-profile は絶対に開かない。**使い捨ての空プロファイルで立てる。
        br = pw.chromium.launch(args=["--no-sandbox"])
        for w in WIDTHS:
            ctx = br.new_context(viewport={"width": w, "height": 900}, device_scale_factor=2)
            pg = ctx.new_page()
            for name, path in PAGES.items():
                pg.goto(base + path, wait_until="load", timeout=30000)
                sw = pg.evaluate("document.documentElement.scrollWidth")
                over = sw - w
                mark = "  " if over <= 0 else "← はみ出し"
                print(f"{w:>4}px {name:<7} scrollWidth={sw:<5} {mark}")
                if over > 0:
                    bad += 1
                    pg.screenshot(path=str(out / f"over_{w}_{name}.png"), full_page=True)
                elif w == 390:
                    pg.screenshot(path=str(out / f"{name}_390.png"), full_page=True)
            ctx.close()
        ctx = br.new_context(viewport={"width": 1280, "height": 900}, device_scale_factor=1)
        pg = ctx.new_page()
        for name, path in PAGES.items():
            pg.goto(base + path, wait_until="load", timeout=30000)
            pg.screenshot(path=str(out / f"{name}_1280.png"), full_page=True)
        br.close()
    print(f"\nはみ出し {bad} 件")
    return 1 if bad else 0


if __name__ == "__main__":
    raise SystemExit(main())
