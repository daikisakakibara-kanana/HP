#!/usr/bin/env python3
"""Generate / download Aburamaru LP assets into img/."""
from __future__ import annotations

import math
import os
from pathlib import Path

import requests
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[1]
IMG = ROOT / "img"
FONT_BOLD = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"

DOWNLOADS: dict[str, str] = {
    "hero-aburasoba.jpg": "https://images.unsplash.com/photo-1569718212165-3a8278d5f624?w=1920&q=85",
    "feature-noodles.jpg": "https://images.unsplash.com/photo-1626804475297-41608ea09aeb?w=900&q=85",
    "feature-condiments.jpg": "https://images.unsplash.com/photo-1596040033229-a9821ebd058d?w=900&q=85",
    "feature-soup.jpg": "https://images.unsplash.com/photo-1547592166-23ac45744acd?w=900&q=85",
    "menu-aburamaru.jpg": "https://images.unsplash.com/photo-1569718212165-3a8278d5f624?w=900&q=85",
    "menu-karamaru.jpg": "https://images.unsplash.com/photo-1563245372-f21724e3856d?w=900&q=85",
    "menu-jiromaru.jpg": "https://images.unsplash.com/photo-1626804475297-41608ea09aeb?w=900&q=85",
    "instagram-01.jpg": "https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=800&q=85",
    "instagram-02.jpg": "https://images.unsplash.com/photo-1506368249639-73a05d6f6488?w=800&q=85",
    "instagram-03.jpg": "https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=800&q=85",
}


def download(name: str, url: str) -> None:
    dest = IMG / name
    print(f"download {name} ...")
    r = requests.get(url, timeout=60, headers={"User-Agent": "Aburamaru-LP-Setup/1.0"})
    r.raise_for_status()
    dest.write_bytes(r.content)


def make_logo() -> None:
    w, h = 440, 152
    img = Image.new("RGBA", (w, h), (0, 0, 0, 255))
    draw = ImageDraw.Draw(img)
    f_sm = ImageFont.truetype(FONT_BOLD, 22)
    f_lg = ImageFont.truetype(FONT_BOLD, 72)
    f_en = ImageFont.truetype(FONT_BOLD, 26)

    draw.text((w // 2, 28), "油そば専門店", font=f_sm, fill="white", anchor="mm")
    draw.text((w // 2, 78), "油丸", font=f_lg, fill="white", anchor="mm")
    draw.text((w // 2, 128), "ABURAMARU", font=f_en, fill="white", anchor="mm")

    out = IMG / "logo.png"
    img.save(out, "PNG")
    print(f"wrote {out}")


def star_points(cx: float, cy: float, outer: float, inner: float, n: int = 16) -> list[tuple[float, float]]:
    pts: list[tuple[float, float]] = []
    for i in range(n * 2):
        r = outer if i % 2 == 0 else inner
        ang = math.pi / 2 + i * math.pi / n
        pts.append((cx + r * math.cos(ang), cy - r * math.sin(ang)))
    return pts


def make_stamp() -> None:
    size = 512
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)
    cx, cy = size // 2, size // 2
    draw.polygon(star_points(cx, cy, 240, 200), fill=(0, 0, 0, 255))
    draw.ellipse((cx - 175, cy - 175, cx + 175, cy + 175), fill=(0, 0, 0, 255))

    f_lg = ImageFont.truetype(FONT_BOLD, 52)
    f_md = ImageFont.truetype(FONT_BOLD, 44)
    f_sm = ImageFont.truetype(FONT_BOLD, 36)
    yellow = (251, 191, 36, 255)

    draw.text((cx, cy - 70), "大盛", font=f_md, fill="white", anchor="mm")
    draw.text((cx, cy - 5), "300g", font=f_lg, fill=yellow, anchor="mm")
    draw.text((cx, cy + 58), "まで無料!!", font=f_sm, fill="white", anchor="mm")

    out = IMG / "stamp-omori-free.png"
    img.save(out, "PNG")
    print(f"wrote {out}")


def main() -> None:
    IMG.mkdir(parents=True, exist_ok=True)
    for name, url in DOWNLOADS.items():
        download(name, url)
    make_logo()
    make_stamp()
    print("done:", len(list(IMG.iterdir())), "files in img/")


if __name__ == "__main__":
    main()
