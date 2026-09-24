#!/usr/bin/env python3
"""Extract the product catalog (names, SKUs, prices, photos) from the Adobe XD design PDF.

Dev-time tool. Run once to seed data/products.seed.php and uploads/products/.
    python3 tools/extract_catalog.py <design.pdf>
"""
import hashlib, json, os, re, sys, unicodedata
import pymupdf

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from pdfsvg import svg_markup

PDF = sys.argv[1] if len(sys.argv) > 1 else "design.pdf"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
IMG_DIR = os.path.join(ROOT, "uploads", "products")
BRAND_DIR = os.path.join(ROOT, "assets", "img", "brands")
DATA = os.path.join(ROOT, "data")

SCALE = 2.0                       # 2x so tiles stay sharp on retina
TILE_W, TILE_H = 304.0, 291.0
TEXT_LEFTS = [64.0, 408.0, 752.0, 1096.0]   # text inset per column
TEXT_DX, TEXT_DY = 12.1, 209.2              # text block origin relative to tile top-left
GRID_TOP, GRID_BOTTOM = 1790.0, 13250.0
BRAND_STRIP = 70.0                # the band at the top of a tile holding the brand mark
BRAND_LEFT = 120.0                # and it sits in the tile's right half, never the left


# The design has one typo of its own: the tile beside "LAGOON CON00091" reads
# "CON00090LAGO", the neighbour's name having run into the SKU. Corrected here
# rather than in the seed, so re-running this does not bring it back.
SKU_FIXES = {"CON00090LAGO": "CON00090"}


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


HEBREW = re.compile(r"[\u0590-\u05FF]")


def captions(page):
    """Every caption block inside the grid, tagged with its column and paint order."""
    out = []
    for bi, b in enumerate(page.get_text("dict")["blocks"]):
        if b["type"] != 0:
            continue
        spans = [s for line in b["lines"] for s in line["spans"]]
        # The design sets the spaces between words as spans of their own, so they
        # are kept: dropping them is what turns "Romeo Moon" into "RomeoMoon".
        inked = [s for s in spans if s["text"].strip()]
        if not inked:
            continue
        x0 = min(s["bbox"][0] for s in inked)
        y0 = min(s["bbox"][3] for s in inked)
        if not (GRID_TOP <= y0 <= GRID_BOTTOM):
            continue
        # The second design appends rows a few points to the left of the first
        # four columns, so the column is matched loosely and the tile is cut
        # from the caption's own left edge further down rather than from this
        # nominal one.
        for c, left in enumerate(TEXT_LEFTS):
            if left - 16 <= x0 <= left + 232:
                out.append({"col": c, "bi": bi, "y": y0, "spans": spans})
                break
    return out


def blocks(page):
    """
    Every visible product text block in the grid, in RTL reading order.

    The design has captions left behind where a tile was replaced: a stale one
    and its replacement sit at exactly the same spot, and a caption is spread
    over two or three PDF text blocks. Grouping by position alone merged the two
    into one product - two names, two SKUs and two prices concatenated - which is
    where VOIDEtch S hade and its price of 21272414 came from. A PDF paints in
    content order, so of the captions sharing a slot the one with the highest
    block index is the one on top, and the one a visitor actually sees.
    """
    products = []
    caps = captions(page)
    for c in range(len(TEXT_LEFTS)):
        column = sorted([x for x in caps if x["col"] == c], key=lambda x: (x["y"], x["bi"]))
        slot = []
        for cap in column + [None]:
            if slot and (cap is None or cap["y"] - slot[-1]["y"] > 80):
                # One slot holds one caption, or a stale one under its
                # replacement. Read them in paint order: a block sitting on the
                # name line starts a caption, the price blocks after it belong
                # to it, and the caption painted last is the visible one.
                base = min(x["y"] for x in slot)
                groups = []
                for cap2 in sorted(slot, key=lambda x: x["bi"]):
                    if cap2["y"] - base < 12 or not groups:
                        groups.append([cap2])
                    else:
                        groups[-1].append(cap2)
                top = max(groups, key=lambda g: max(x["bi"] for x in g))
                p = caption_to_product(c, [s for x in top for s in x["spans"]])
                if p:
                    products.append(p)
                slot = []
            if cap is not None:
                slot.append(cap)

    # The design also carries a leftover row hidden under later tiles with nothing
    # painted over its text. A block counts as visible when erasing it actually
    # changes the page - which also catches text that is white over a dark photo.
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


def caption_to_product(col, spans):
    """
    One caption, read by what each line is rather than by where it sits.

    The first design put name, SKU and the two prices at four fixed offsets, so
    the bands could be cut by distance from the top. The second adds a colour
    line under the SKU on some tiles, which pushed both prices past the last
    cut-off and welded them together - AIM came out with no "before" and an
    "after" of 2616785. Lines are identified now: the first two are the name
    and the SKU, a line carrying the shekel sign is a price, and the big digits
    are the one being asked today. Anything in Hebrew below the SKU is a note
    the designer left - a finish, or "available in 18 colours".
    """
    lines = []
    for s in sorted(spans, key=lambda s: s["bbox"][3]):
        if lines and abs(s["bbox"][3] - lines[-1]["y"]) <= 6:
            lines[-1]["spans"].append(s)
        else:
            lines.append({"y": s["bbox"][3], "spans": [s]})
    if len(lines) < 2:
        return None

    before = after = None
    notes = []
    for ln in lines[2:]:
        hebrew = [s for s in ln["spans"] if HEBREW.search(s["text"])]
        rest = [s for s in ln["spans"] if not HEBREW.search(s["text"])]
        notes += hebrew
        if not any("\u20aa" in s["text"] for s in rest):
            continue
        digits = [s for s in rest if re.search(r"\d", s["text"])]
        value = money(join(rest))
        # 29pt is the price being asked, 19pt the one struck through.
        if max((s["size"] for s in digits), default=0) >= 24:
            after = value
        else:
            before = value

    name = join(lines[0]["spans"])
    sku = join(lines[1]["spans"])
    if name == "" or after is None:
        return None
    base = lines[0]["y"]
    left = min(s["bbox"][0] for s in spans)      # where this tile actually is
    rect = pymupdf.Rect(left - TEXT_DX, base - TEXT_DY,
                        left - TEXT_DX + TILE_W, base - TEXT_DY + TILE_H)
    return {
        "name": name,
        "sku": SKU_FIXES.get(sku, sku),
        "price_before": before,
        "price_after": after,
        "note": join(notes),
        "_rect": rect,
        "_base": base,
        "_col": col,
    }


def brand_mark(drawings, rect):
    """
    The brand mark in the corner of one tile, as SVG, with its size in design px.

    The designer sets every mark near the top right of its tile, but not at a
    repeatable distance from the edge - the gap runs from 2 to 10px - so on the
    page the marks read as scattered rather than as a column. Lifting each one
    out of its tile is what lets the page line them all up, and it is only
    possible because they are vector: the six tiles whose mark is painted into
    the photograph itself keep it where the photographer put it.
    """
    strip = (rect.x0 + BRAND_LEFT, rect.y0, rect.x1, rect.y0 + BRAND_STRIP)
    inside = [d for d in drawings
              if d["rect"].x0 >= strip[0] and d["rect"].x1 <= strip[2] + .5
              and d["rect"].y0 >= strip[1] - .5 and d["rect"].y1 <= strip[3]]
    if not inside:
        return None
    box = (min(d["rect"].x0 for d in inside), min(d["rect"].y0 for d in inside),
           max(d["rect"].x1 for d in inside), max(d["rect"].y1 for d in inside))
    art = svg_markup(inside, box, origin=True)
    if art is None:
        return None
    return art, box


def main():
    doc = pymupdf.open(PDF)
    products = blocks(doc[0])
    print(f"parsed {len(products)} products "
          f"(rows {products[0]['_rect'].y0:.0f}..{products[-1]['_rect'].y0:.0f})")

    # The brand mark is read off the untouched page, before the working copy has
    # it redacted away: the photo underneath it is what gets cropped, and the
    # mark becomes a file of its own that the card places where it wants.
    drawings = doc[0].get_drawings()
    marks = {}
    for p in products:
        got = brand_mark(drawings, p["_rect"])
        if got:
            art, box = got
            p["_brand"] = (art, box[2] - box[0])
            marks[art] = None

    os.makedirs(BRAND_DIR, exist_ok=True)
    for art in marks:
        name = hashlib.sha1(art.encode()).hexdigest()[:8] + ".svg"
        marks[art] = name
        open(os.path.join(BRAND_DIR, name), "w").write(art)
    print(f"{len(marks)} distinct brand marks over "
          f"{sum(1 for p in products if '_brand' in p)} tiles")

    # Strip the burned-in text, the red strike-through and the brand mark from a
    # working copy, then crop each tile down to a clean product photo.
    work = pymupdf.open(PDF)
    page = work[0]
    for p in products:
        r = p["_rect"]
        page.add_redact_annot(pymupdf.Rect(r.x0, r.y0 + 180, r.x0 + 260, r.y1 + 2), fill=False)
        if "_brand" in p:
            page.add_redact_annot(
                pymupdf.Rect(r.x0 + BRAND_LEFT, r.y0, r.x1, r.y0 + BRAND_STRIP), fill=False)
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
        art = p.get("_brand")
        out.append({"id": i, "name": p["name"], "sku": p["sku"],
                    "price_before": p["price_before"], "price_after": p["price_after"],
                    "image": fname,
                    **({"brand": marks[art[0]], "brand_w": round(art[1], 1)} if art else {}),
                    **({"description": p["note"]} if p.get("note") else {})})

    with open(os.path.join(DATA, "products.seed.php"), "w", encoding="utf-8") as fh:
        fh.write("<?php http_response_code(404); exit; ?>\n")
        json.dump(out, fh, ensure_ascii=False, indent=1)
    print(f"wrote {len(out)} images, {total/1e6:.1f} MB")


if __name__ == "__main__":
    main()
