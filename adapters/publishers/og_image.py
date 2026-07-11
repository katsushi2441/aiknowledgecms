"""OGP画像ジェネレータ — 記事タイトルから1200x630のシェア画像をその場で生成する。

「AIエージェントループ」キーワードをオレンジ→ピンクのグラデーションバッジで
常に強調し、クリックしたくなる見た目にする(ユーザー要望)。ロゴ以外は
外部アセット不要(PIL + Noto Sans CJKのみ)なので、生成のたびにテンプレート化できる。
"""
from __future__ import annotations

import io
import os
import random

from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 630
FONT_BOLD = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"
FONT_REGULAR = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"
LOGO_PATH = os.path.join(os.path.dirname(__file__), "..", "..", "images", "aiknowledgecms_logo.png")

NAVY_TOP = (10, 14, 30)
NAVY_BOTTOM = (27, 22, 60)
ACCENT_A = (249, 115, 22)   # orange
ACCENT_B = (236, 72, 153)   # pink
NODE_BLUE = (56, 130, 246)
NODE_CYAN = (56, 189, 248)


def _font(path: str, size: int) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(path, size, index=0)


def _lerp(c1, c2, t):
    return tuple(int(c1[i] + (c2[i] - c1[i]) * t) for i in range(3))


def _bg() -> Image.Image:
    im = Image.new("RGB", (1, H), NAVY_TOP)
    px = im.load()
    for y in range(H):
        px[0, y] = _lerp(NAVY_TOP, NAVY_BOTTOM, y / H)
    return im.resize((W, H))


def _draw_nodes(im: Image.Image) -> None:
    rnd = random.Random(7)  # 固定シード = 毎回同じ散らし方(記事間で見た目を揃える)
    for _ in range(22):
        x, y = rnd.randint(0, W), rnd.randint(0, H)
        r = rnd.choice([2, 3, 4, 5])
        color = rnd.choice([NODE_BLUE, NODE_CYAN])
        layer = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        ImageDraw.Draw(layer).ellipse([x - r, y - r, x + r, y + r], fill=color + (90,))
        im.alpha_composite(layer)


def _fit_lines(draw, text, font_path, max_width, start_size, min_size, max_lines, max_height):
    """幅制約(1行の折返し)と高さ制約(フッターと衝突しない)の両方を満たすまでサイズを縮める。"""
    size = start_size
    lines: list[str] = []
    while size >= min_size:
        font = _font(font_path, size)
        lines = []
        cur = ""
        for ch in text:
            trial = cur + ch
            if draw.textlength(trial, font=font) <= max_width:
                cur = trial
            else:
                lines.append(cur)
                cur = ch
        if cur:
            lines.append(cur)
        line_h = int(size * 1.35)
        if len(lines) <= max_lines and line_h * len(lines) <= max_height:
            return font, lines, line_h
        size -= 4
    font = _font(font_path, min_size)
    line_h = int(min_size * 1.35)
    lines = lines[:max_lines]
    if lines:
        last = lines[-1]
        while draw.textlength(last + "…", font=font) > max_width and len(last) > 1:
            last = last[:-1]
        lines[-1] = last + "…"
    return font, lines, line_h


def generate_og_image(title: str, keyword: str = "AIエージェントループ") -> bytes:
    """記事タイトルから1200x630のPNGバイト列を返す。"""
    im = _bg().convert("RGBA")
    _draw_nodes(im)
    draw = ImageDraw.Draw(im)

    # 左上: ロゴを白いチップの上に置く(ロゴは紺色文字なので暗背景に直置きすると読めない)
    chip_box = (48, 44, 452, 132)
    draw.rounded_rectangle(chip_box, radius=18, fill=(255, 255, 255))
    try:
        logo = Image.open(LOGO_PATH).convert("RGBA")
        target_h = 46
        ratio = target_h / logo.height
        logo = logo.resize((int(logo.width * ratio), target_h))
        im.alpha_composite(logo, (chip_box[0] + 20, chip_box[1] + (chip_box[3] - chip_box[1] - target_h) // 2))
    except Exception:
        pass
    draw = ImageDraw.Draw(im)

    # キーワードバッジ(クリック訴求の主役)
    badge_font = _font(FONT_BOLD, 46)
    badge_text = f"注目キーワード：{keyword}"
    tw = draw.textlength(badge_text, font=badge_font)
    pad_x, pad_y = 34, 20
    bx0, by0 = 48, 168
    bx1, by1 = bx0 + tw + pad_x * 2, by0 + 46 + pad_y * 2
    grad = Image.new("RGBA", (int(bx1 - bx0), int(by1 - by0)), (0, 0, 0, 0))
    gd = ImageDraw.Draw(grad)
    for x in range(grad.width):
        gd.line([(x, 0), (x, grad.height)], fill=_lerp(ACCENT_A, ACCENT_B, x / max(grad.width - 1, 1)) + (255,))
    mask = Image.new("L", grad.size, 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, grad.width - 1, grad.height - 1], radius=26, fill=255)
    im.paste(grad, (int(bx0), int(by0)), mask)
    draw = ImageDraw.Draw(im)
    draw.text((bx0 + pad_x, by0 + pad_y), badge_text, font=badge_font, fill=(255, 255, 255))

    # タイトル(フッターと重ならない高さまでで自動縮小)
    title_top = 300
    footer_top = H - 80
    font, lines, line_h = _fit_lines(
        draw, title, FONT_BOLD, W - 48 * 2, 66, 38, 3, footer_top - title_top)
    y = title_top
    for line in lines:
        draw.text((48, y), line, font=font, fill=(255, 255, 255))
        y += line_h

    # フッター
    draw.text((48, H - 64), "AIKnowledgeCMS Media", font=_font(FONT_BOLD, 26), fill=(180, 190, 220))
    foot2_font = _font(FONT_REGULAR, 24)
    t2 = "aiknowledgecms.exbridge.jp"
    draw.text((W - 48 - draw.textlength(t2, font=foot2_font), H - 62), t2, font=foot2_font, fill=(140, 150, 185))

    out = io.BytesIO()
    im.convert("RGB").save(out, format="PNG", optimize=True)
    return out.getvalue()
