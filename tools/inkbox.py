#!/usr/bin/env python3
"""Compare the ink box of a region between the design render and the build."""
import sys
from PIL import Image
Image.MAX_IMAGE_PIXELS = None

D = Image.open('tools/out/design.png').convert('RGB')
B = Image.open('tools/out/build.png').convert('RGB')
DELTA = B.size[1] - D.size[1]


def box(img, x0, y0, x1, y1, lum):
    px = img.load()
    minx, miny, maxx, maxy = x1, y1, -1, -1
    for y in range(y0, y1):
        for x in range(x0, x1):
            r, g, b = px[x, y]
            if (r * 299 + g * 587 + b * 114) // 1000 < lum:
                minx = min(minx, x); maxx = max(maxx, x)
                miny = min(miny, y); maxy = max(maxy, y)
    return None if maxx < 0 else (minx, miny, maxx + 1, maxy + 1)


def cmp(label, x0, y0, x1, y1, lum=120, shift=0):
    a = box(D, x0, y0, x1, y1, lum)
    b = box(B, x0, y0 + shift, x1, y1 + shift, lum)
    if not a or not b:
        print(f"{label:22s} design={a} build={b}")
        return
    b = (b[0], b[1] - shift, b[2], b[3] - shift)
    print(f"{label:22s} design L{a[0]:5d} R{a[2]:5d} T{a[1]:6d} B{a[3]:6d} W{a[2]-a[0]:4d}"
          f" | build L{b[0]:5d} R{b[2]:5d} T{b[1]:6d} B{b[3]:6d} W{b[2]-b[0]:4d}"
          f" | dL{b[0]-a[0]:+4d} dR{b[2]-a[2]:+4d} dT{b[1]-a[1]:+4d} dW{(b[2]-b[0])-(a[2]-a[0]):+4d}")


if __name__ == '__main__':
    cmp('kicker',        900, 155, 1440, 238)
    cmp('עד',           1290, 239, 1440, 312)
    cmp('הנחה',         1150, 448, 1440, 520)
    cmp('מכירה',        1000, 523, 1440, 577)
    cmp('מתצוגה',       1000, 600, 1440, 700)
    cmp('של גופי תאורה', 900, 715, 1440, 793)
    cmp('בינלאומיים',   1000, 794, 1440, 845)
    cmp('logo',         1090,  25, 1440, 115, lum=150)
    cmp('70%',           940, 245, 1350, 540, lum=150)
    print()
    cmp('step1 title',   580, 990, 1200, 1050)
    cmp('step1 sub',     580, 1056, 1200, 1098)
    cmp('step3 sub2',    580, 1625, 1200, 1680)
    cmp('lead title',    560, 12270, 1440, 12384, shift=DELTA)
    cmp('lead sub',      600, 12385, 1440, 12432, shift=DELTA)
