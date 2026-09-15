#!/usr/bin/env python3
"""Extract the product catalog (names, SKUs, prices, photos) from the Adobe XD design PDF.

Dev-time tool. Run once to seed data/products.json and uploads/products/.
    python3 tools/extract_catalog.py <design.pdf>
"""
import json, os, re, sys, unicodedata
import pymupdf

PDF = sys.argv[1] if len(sys.argv) > 1 else "design.pdf"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
IMG_DIR = os.path.join(ROOT, "uploads", "products")
DATA = os.path.join(ROOT, "data")

SCALE = 2.0                       # 2x so tiles stay sharp on retina
TILE_W, TILE_H = 304.0, 291.0
TEXT_LEFTS = [64.0, 408.0, 752.0, 1096.0]   # text inset per column
TEXT_DX, TEXT_DY = 12.1, 209.2              # text block origin relative to tile top-left
GRID_TOP, GRID_BOTTOM = 1790.0, 12100.0


def slug(name, sku):
    s = unicodedata.normalize("NFKC", f"{name} {sku}").strip()
    s = re.sub(r'[\\/:*?"<>|]+', "", s)
    return re.sub(r"\s+", "-", s)[:80]


def join(spans):
    """Concatenate fragmented Type3 spans left-to-right; Hebrew runs read right-to-left."""
    if not spans:
        return ""
    spans = sorted(spans, key=lambda s: s["bbox"][0])
    if any("Alef" in s["font"] for s in spans):
        spans = sorted(spans, key=lambda s: -s["bbox"][0])
    return re.sub(r"\s+", " ", "".join(s["text"] for s in spans)).strip()


def money(text):
    digits = re.sub(r"[^\d]", "", text)
    return int(digits) if digits else None


def blocks(page):
    """Every visible product text block in the grid, in RTL reading order."""
    cols = [[] for _ in TEXT_LEFTS]
    for b in page.get_text("dict")["blocks"]:
        if b["type"] != 0:
            continue
        for line in b["lines"]:
            for s in line["spans"]:
                x0, y0, x1, y1 = s["bbox"]
                if not (GRID_TOP <= y0 and y1 <= GRID_BOTTOM):
                    continue
                for c, left in enumerate(TEXT_LEFTS):
                    if left - 4 <= x0 and x1 <= left + 232:
                        cols[c].append(s)
                        break

    out = []
    for c, spans in enumerate(cols):
        spans.sort(key=lambda s: s["bbox"][3])
        cluster = []
        for s in spans + [None]:
            if cluster and (s is None or s["bbox"][3] - cluster[-1]["bbox"][3] > 40):
                out.append((c, cluster))
                cluster = []
            if s is not None:
                cluster.append(s)

    products = []
    for c, cluster in out:
        base = min(s["bbox"][3] for s in cluster)
        bands = {"name": [], "sku": [], "old": [], "new": []}
        for s in cluster:
            rel = s["bbox"][3] - base
            key = "name" if rel < 12 else "sku" if rel < 35 else "old" if rel < 58 else "new"
            bands[key].append(s)
        if not bands["name"] or not bands["new"]:
            continue
        rect = pymupdf.Rect(TEXT_LEFTS[c] - TEXT_DX, base - TEXT_DY,
                            TEXT_LEFTS[c] - TEXT_DX + TILE_W, base - TEXT_DY + TILE_H)
        products.append({
            "name": join(bands["name"]),
            "sku": join(bands["sku"]),
            "price_before": money(join(bands["old"])),
            "price_after": money(join(bands["new"])),
            "_rect": rect,
            "_base": base,
            "_col": c,
            "_light": all(s["color"] == 0xFFFFFF for s in bands["name"]),
        })

    # The design carries a leftover row hidden under later tiles. A block counts as
    # visible when erasing it actually changes the page - which also catches the
    # cards whose text is white over a dark photo.
    blank = pymupdf.open(page.parent.name)[page.number]
    for p in products:
        r = p["_rect"]
        blank.add_redact_annot(pymupdf.Rect(r.x0, r.y0 + 180, r.x0 + 260, r.y1 + 2), fill=False)
    blank.apply_redactions(images=pymupdf.PDF_REDACT_IMAGE_NONE,
                           graphics=pymupdf.PDF_REDACT_LINE_ART_REMOVE_IF_COVERED,
                           text=pymupdf.PDF_REDACT_TEXT_REMOVE)
    visible = []
    for p in products:
        r = p["_rect"]
        strip = pymupdf.Rect(r.x0 + TEXT_DX, p["_base"] - 19, r.x0 + TEXT_DX + 150, p["_base"] + 2)
        a = page.get_pixmap(clip=strip, matrix=pymupdf.Matrix(2, 2)).samples
        b = blank.get_pixmap(clip=strip, matrix=pymupdf.Matrix(2, 2)).samples
        changed = sum(1 for i in range(0, min(len(a), len(b)), 3) if abs(a[i] - b[i]) > 40)
        if changed > 40:
            visible.append(p)

    # Row/column, counted from the right: RTL reading order.
    tops = sorted({round(p["_rect"].y0) for p in visible})
    rows, last = [], -1e9
    for y in tops:
        if y - last > 60:
            rows.append(y)
        last = y
    for p in visible:
        p["_row"] = min(range(len(rows)), key=lambda i: abs(rows[i] - p["_rect"].y0))
        p["_ccol"] = len(TEXT_LEFTS) - p["_col"]      # 1 = rightmost
    visible.sort(key=lambda p: (p["_row"], p["_ccol"]))
    return visible


def main():
    doc = pymupdf.open(PDF)
    products = blocks(doc[0])
    print(f"parsed {len(products)} products "
          f"(rows {products[0]['_rect'].y0:.0f}..{products[-1]['_rect'].y0:.0f})")

    # Strip the burned-in text and red strike-through from a working copy,
    # then crop each tile down to a clean product photo.
    work = pymupdf.open(PDF)
    page = work[0]
    for p in products:
        r = p["_rect"]
        page.add_redact_annot(pymupdf.Rect(r.x0, r.y0 + 180, r.x0 + 260, r.y1 + 2), fill=False)
    page.apply_redactions(images=pymupdf.PDF_REDACT_IMAGE_NONE,
                          graphics=pymupdf.PDF_REDACT_LINE_ART_REMOVE_IF_COVERED,
                          text=pymupdf.PDF_REDACT_TEXT_REMOVE)

    os.makedirs(IMG_DIR, exist_ok=True)
    os.makedirs(DATA, exist_ok=True)
    seen, total, out = {}, 0, []
    for i, p in enumerate(products, 1):
        r = p["_rect"]
        base = slug(p["name"], p["sku"]) or f"product-{i}"
        n = seen.get(base, 0) + 1
        seen[base] = n
        fname = f"{base}.webp" if n == 1 else f"{base}-{n}.webp"
        clip = pymupdf.Rect(r.x0 + 0.6, r.y0 + 0.6, r.x1 - 0.6, r.y1 - 0.6)
        pix = page.get_pixmap(clip=clip, matrix=pymupdf.Matrix(SCALE, SCALE))
        path = os.path.join(IMG_DIR, fname)
        pix.pil_save(path, format="WEBP", quality=92, method=6)
        total += os.path.getsize(path)
        out.append({"id": i, "name": p["name"], "sku": p["sku"],
                    "price_before": p["price_before"], "price_after": p["price_after"],
                    "image": fname, "row": p["_row"] + 1, "col": p["_ccol"],
                    "light": p["_light"]})

    with open(os.path.join(DATA, "products.json"), "w", encoding="utf-8") as fh:
        json.dump(out, fh, ensure_ascii=False, indent=1)
    print(f"wrote {len(out)} images, {total/1e6:.1f} MB")


if __name__ == "__main__":
    main()
