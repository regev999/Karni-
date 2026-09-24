#!/usr/bin/env python3
"""Extract the static artwork of the landing page from the Adobe XD design PDF.

Dev-time tool. Vector artwork (logo, the 70% mark, step numerals, chevron) comes out
as SVG; photographs come out as raster at 2x.
    python3 tools/extract_assets.py <design.pdf>
"""
import os, sys
import pymupdf

PDF = sys.argv[1] if len(sys.argv) > 1 else "design.pdf"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "assets", "img")
os.makedirs(OUT, exist_ok=True)

CYAN = "#00b8f1"


def path_d(items):
    d, cur = [], None
    for it in items:
        op = it[0]
        if op == "l":
            p1, p2 = it[1], it[2]
            if cur != (p1.x, p1.y):
                d.append(f"M{p1.x:.2f} {p1.y:.2f}")
            d.append(f"L{p2.x:.2f} {p2.y:.2f}")
            cur = (p2.x, p2.y)
        elif op == "c":
            p1, p2, p3, p4 = it[1], it[2], it[3], it[4]
            if cur != (p1.x, p1.y):
                d.append(f"M{p1.x:.2f} {p1.y:.2f}")
            d.append(f"C{p2.x:.2f} {p2.y:.2f} {p3.x:.2f} {p3.y:.2f} {p4.x:.2f} {p4.y:.2f}")
            cur = (p4.x, p4.y)
        elif op == "re":
            r = it[1]
            d.append(f"M{r.x0:.2f} {r.y0:.2f}H{r.x1:.2f}V{r.y1:.2f}H{r.x0:.2f}Z")
            cur = None
        elif op == "qu":
            q = it[1]
            d.append(f"M{q.ul.x:.2f} {q.ul.y:.2f}L{q.ur.x:.2f} {q.ur.y:.2f}"
                     f"L{q.lr.x:.2f} {q.lr.y:.2f}L{q.ll.x:.2f} {q.ll.y:.2f}Z")
            cur = None
    return " ".join(d)


def hex_of(rgb):
    return "#%02x%02x%02x" % tuple(round(c * 255) for c in rgb)


def svg(page, clip, name, recolor=None, pad=0.0):
    """Write every drawing inside `clip` as an SVG whose viewBox is that clip."""
    x0, y0, x1, y1 = clip
    x0, y0, x1, y1 = x0 - pad, y0 - pad, x1 + pad, y1 + pad
    parts = []
    for dr in page.get_drawings():
        r = dr["rect"]
        if not (r.x0 >= x0 - .5 and r.x1 <= x1 + .5 and r.y0 >= y0 - .5 and r.y1 <= y1 + .5):
            continue
        d = path_d(dr["items"])
        if not d:
            continue
        attrs = [f'd="{d}"']
        fill = dr.get("fill")
        stroke = dr.get("color")
        if fill is not None:
            col = hex_of(fill)
            attrs.append(f'fill="{recolor.get(col, col) if recolor else col}"')
        else:
            attrs.append('fill="none"')
        if stroke is not None:
            col = hex_of(stroke)
            attrs.append(f'stroke="{recolor.get(col, col) if recolor else col}"')
            attrs.append(f'stroke-width="{dr.get("width") or 1}"')
            attrs.append('stroke-linecap="round" stroke-linejoin="round"')
        if dr.get("even_odd"):
            attrs.append('fill-rule="evenodd"')
        parts.append("<path " + " ".join(attrs) + "/>")
    body = "\n  ".join(parts)
    out = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{x0:.2f} {y0:.2f} '
           f'{x1-x0:.2f} {y1-y0:.2f}" width="{x1-x0:.2f}" height="{y1-y0:.2f}">\n  {body}\n</svg>\n')
    path = os.path.join(OUT, name)
    open(path, "w").write(out)
    print(f"  {name:18s} {len(parts):3d} paths  {os.path.getsize(path)/1024:6.1f} KB")


def raster(page, clip, name, scale=2.0, fmt="WEBP", **kw):
    pix = page.get_pixmap(clip=pymupdf.Rect(*clip), matrix=pymupdf.Matrix(scale, scale),
                          alpha=kw.pop("alpha", False))
    path = os.path.join(OUT, name)
    pix.pil_save(path, format=fmt, **kw)
    print(f"  {name:18s} {pix.width}x{pix.height}  {os.path.getsize(path)/1024:6.1f} KB")


def main():
    doc = pymupdf.open(PDF)
    page = doc[0]

    print("vector:")
    # Logo: wordmark + cyan rule + tagline. currentColor lets one file serve the
    # dark header logo and the white footer logo.
    svg(page, (1103.6, 29.0, 1374.0, 108.2), "logo.svg",
        recolor={"#212121": "currentColor"})
    svg(page, (962.0, 251.6, 1334.9, 531.7), "seventy.svg")
    # The step numerals are stroked at 10px, so the artwork reaches half a stroke
    # past the path bounds - pad the viewBox or the strokes come out clipped.
    for i, (yy0, yy1) in enumerate([(1013.8, 1145.6), (1284.5, 1418.6), (1554.5, 1691.7)], 1):
        svg(page, (451.0, yy0, 1091.2, yy1), f"step{i}.svg", pad=5.0)
    svg(page, (1299.3, 12496.2, 1366.9, 12539.4), "chevron.svg")

    print("raster:")
    # Hero: drop the headline, logo and 70% mark, keep the photo and its beige wash.
    work = pymupdf.open(PDF)
    wp = work[0]
    wp.add_redact_annot(pymupdf.Rect(860, 0, 1440, 900), fill=False)
    wp.apply_redactions(images=pymupdf.PDF_REDACT_IMAGE_NONE,
                        graphics=pymupdf.PDF_REDACT_LINE_ART_REMOVE_IF_COVERED,
                        text=pymupdf.PDF_REDACT_TEXT_REMOVE)
    raster(wp, (0, 0, 1440, 900), "hero.webp", quality=90, method=6)
    raster(wp, (0, 0, 1440, 900), "hero.jpg", fmt="JPEG", quality=84, optimize=True,
           progressive=True)

    # The dolly straddles the grey, white and dark bands; its baked-in backdrop
    # matches those sections exactly, so it drops straight back into place. The
    # crop stops short of the headline beside it: anything wider bakes the
    # design's own words into the photo, and they then show through whatever
    # the page itself says.
    raster(page, (55.0, 12985.0, 430.0, 13632.0), "dolly.webp", quality=93, method=6)


if __name__ == "__main__":
    main()
