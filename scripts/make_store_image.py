#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""kacnavi の OGP／kappstore 商品画像 1200×630。

ライトテーマ。**数字は成長するものを焼き込まない**（[[feedback_banner_variety]]）ので、
1,912市区町村のような増える数は入れず、法令の条文で裏を取っていることを主役にする。
題材が題材なので、彩度を落とした落ち着いた配色にする。
"""
import os
from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 630
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'outputs', 'kacnavi_1200x630.png')
MASCOT = '/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png'
FB = '/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc'
FM = '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc'
FR = '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc'

INK, AC, MUT = '#1d2430', '#3f6f8f', '#616c7a'

os.makedirs(os.path.dirname(OUT), exist_ok=True)
img = Image.new('RGB', (W, H), '#fbfbfa')
dr = ImageDraw.Draw(img, 'RGBA')
dr.ellipse([-240, -300, 420, 320], fill=(233, 240, 244, 255))
dr.ellipse([W - 400, H - 270, W + 260, H + 300], fill=(238, 242, 240, 255))

mascot = None
if os.path.exists(MASCOT):
    mascot = Image.open(MASCOT).convert('RGBA')
    mh = 248
    mascot = mascot.resize((int(mascot.width * mh / mascot.height), mh))
cx = 468 if mascot else W // 2

f_badge = ImageFont.truetype(FM, 24)
f_s = ImageFont.truetype(FR, 23)
f_band = ImageFont.truetype(FM, 26)

badge = '期限は、法令の条文で裏を取っています'
bw = dr.textlength(badge, font=f_badge) + 44
dr.rounded_rectangle([cx - bw / 2, 76, cx + bw / 2, 124], radius=24, fill='#e8eef2', outline='#c6d6e0')
dr.text((cx, 99), badge, font=f_badge, fill='#2f5670', anchor='mm')

dr.text((cx, 192), '身内が亡くなったあと、', font=ImageFont.truetype(FB, 42), fill=INK, anchor='mm')
dr.text((cx, 264), '何を、いつまでに。', font=ImageFont.truetype(FB, 47), fill=AC, anchor='mm')
dr.text((cx, 338), '故人の状況をえらぶと、当てはまる手続きだけが残ります。', font=f_s, fill=MUT, anchor='mm')
dr.text((cx, 376), '入力した内容は、どこにも送りません。', font=f_s, fill=MUT, anchor='mm')

bt = '根拠の条文つき ／ PHP 1ファイル＋SQLite'
bw2 = dr.textlength(bt, font=f_band) / 2 + 34
dr.rounded_rectangle([cx - bw2, 430, cx + bw2, 486], radius=28, fill='#eef2f0', outline='#d5dedb')
dr.text((cx, 458), bt, font=f_band, fill='#3d4a4f', anchor='mm')

dr.text((cx, 546), 'Kurage AfterCare Navi（kacnavi）', font=ImageFont.truetype(FM, 27), fill=INK, anchor='mm')
dr.text((cx, 586), '株式会社エクスブリッジ（名古屋市）', font=ImageFont.truetype(FR, 21), fill=MUT, anchor='mm')

if mascot:
    img.paste(mascot, (W - mascot.width - 64, H - mascot.height - 46), mascot)

img.save(OUT)
print(f'→ {OUT} {os.path.getsize(OUT)/1024:.0f}KB')
