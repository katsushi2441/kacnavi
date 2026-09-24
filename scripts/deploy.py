#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""kacnavi を heteml（kurage.exbridge.jp）へ上げる。

  /usr/bin/python3 scripts/deploy.py           # PHP・OGP・SQLite
  /usr/bin/python3 scripts/deploy.py --php     # PHP と OGP だけ（SQLite を送らない）

**FTP は1接続にまとめる。** 短時間に接続を重ねると、うちのIPが全ポートで
15〜20分遮断される（[[reference_heteml_ftp_block]]）。確認は HTTPS で行う。

置き場所:
  /web/kurage_exbridge_jp/kacnavi.php
  /web/kurage_exbridge_jp/kacnavi_data/…   … .htaccess で直読み禁止
  /web/kurage_exbridge_jp/images/ogp/kacnavi.png
"""
import ftplib
import os
import sys
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BASE = "https://kurage.exbridge.jp"
REMOTE = "/web/kurage_exbridge_jp"
PHP_ONLY = "--php" in sys.argv
DATA = os.path.join(ROOT, "php", "kacnavi_data")

FILES = [(f"{ROOT}/php/kacnavi.php", f"{REMOTE}/kacnavi.php"),
         (f"{DATA}/.htaccess", f"{REMOTE}/kacnavi_data/.htaccess"),
         (f"{ROOT}/outputs/kacnavi_1200x630.png", f"{REMOTE}/images/ogp/kacnavi.png")]
if not PHP_ONLY:
    FILES.append((f"{DATA}/kacnavi.sqlite", f"{REMOTE}/kacnavi_data/kacnavi.sqlite"))


def env():
    for line in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        if "=" in line and not line.startswith("#"):
            k, v = line.rstrip("\n").split("=", 1)
            os.environ.setdefault(k, v.strip().strip('"').strip("'"))


def main() -> int:
    env()
    total = sum(os.path.getsize(l) for l, _ in FILES)
    print(f"{len(FILES)}ファイル / {total/1e6:.1f}MB を1接続で送ります")
    f = ftplib.FTP(os.environ["FTP_HOST"], timeout=1800)
    f.login(os.environ["FTP_USER"], os.environ["FTP_PASS"])
    cur = None
    for local, remote in FILES:
        d = os.path.dirname(remote)
        if d != cur:
            try:
                f.cwd(d)
            except ftplib.error_perm:
                f.mkd(d); f.cwd(d)
            cur = d
        with open(local, "rb") as fh:
            f.storbinary("STOR " + os.path.basename(remote), fh, blocksize=1 << 18)
        print(f"  {remote}  {os.path.getsize(local)/1024:.0f}KB", flush=True)
    f.quit()

    for path in ("/kacnavi.php/", "/kacnavi.php/when/g1",
                 "/kacnavi.php/p/shibo-todoke",
                 "/kacnavi.php/city/%E6%84%9B%E7%9F%A5%E7%9C%8C/%E5%90%8D%E5%8F%A4%E5%B1%8B%E5%B8%82%E4%B8%AD%E5%8C%BA",
                 "/kacnavi.php/pref/%E6%84%9B%E7%9F%A5%E7%9C%8C", "/kacnavi.php/cities",
                 "/kacnavi.php/about", "/kacnavi.php/sitemap.xml", "/kacnavi.php/llms.txt",
                 "/images/ogp/kacnavi.png"):
        try:
            with urllib.request.urlopen(urllib.request.Request(BASE + path, headers={"User-Agent": "kacnavi-deploy/1.0"}), timeout=90) as r:
                print(f"  {r.status} {len(r.read(400))}B+ {BASE}{path}")
        except Exception as e:
            print(f"  ! {BASE}{path}: {e}")
    try:
        with urllib.request.urlopen(BASE + "/kacnavi_data/kacnavi.sqlite", timeout=60) as r:
            print(f"  ! SQLite が直接読めてしまう: {r.status}")
    except Exception as e:
        print(f"  {getattr(e, 'code', '?')} SQLite の直読みは拒否されている（想定どおり）")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
