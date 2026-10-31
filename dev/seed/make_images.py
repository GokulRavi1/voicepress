"""Generate simple, consistent product images for the demo store (no stock photos needed)."""
import sys
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont, ImageFilter

OUT = Path(sys.argv[1])
PRODUCTS = [
    ("cotton-kurta-set", "\U0001F457", ("#ffd6e0", "#ffefd5")),
    ("silver-jhumka-earrings", "\U0001F48E", ("#e0e7ff", "#f5f3ff")),
    ("ultralight-running-shoes", "\U0001F45F", ("#d1fae5", "#ecfeff")),
    ("steel-water-bottle-pack-of-2", "\U0001F964", ("#dbeafe", "#f0f9ff")),
    ("vitamin-c-face-serum", "\U0001F9F4", ("#fef3c7", "#fff7ed")),
    ("non-stick-cookware-set", "\U0001F373", ("#fee2e2", "#fff1f2")),
    ("banarasi-silk-saree", "\U0001F97B", ("#fae8ff", "#fdf4ff")),
    ("wireless-earbuds-pro", "\U0001F3A7", ("#e2e8f0", "#f8fafc")),
]
S = 900
emoji_font = ImageFont.truetype("C:/Windows/Fonts/seguiemj.ttf", 360)
brand_font = ImageFont.truetype("C:/Windows/Fonts/seguisb.ttf", 34)

def hex2rgb(h):
    h = h.lstrip("#")
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))

for slug, emoji, (c1, c2) in PRODUCTS:
    a, b = hex2rgb(c1), hex2rgb(c2)
    img = Image.new("RGB", (S, S))
    px = img.load()
    for y in range(S):
        for x in range(S):
            t = (x + y) / (2 * S)
            px[x, y] = tuple(int(a[i] + (b[i] - a[i]) * t) for i in range(3))
    # soft floor shadow
    shadow = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    ImageDraw.Draw(shadow).ellipse((250, 690, 650, 750), fill=(0, 0, 0, 60))
    img.paste(shadow.filter(ImageFilter.GaussianBlur(18)), (0, 0), shadow.filter(ImageFilter.GaussianBlur(18)))
    d = ImageDraw.Draw(img)
    bbox = d.textbbox((0, 0), emoji, font=emoji_font, embedded_color=True)
    w, h = bbox[2] - bbox[0], bbox[3] - bbox[1]
    d.text(((S - w) / 2 - bbox[0], (S - h) / 2 - bbox[1] - 30), emoji, font=emoji_font, embedded_color=True)
    d.text((44, 36), "ShopEase", font=brand_font, fill=(91, 61, 245))
    img.save(OUT / f"{slug}.png", optimize=True)
    print("wrote", slug)
