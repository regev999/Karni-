#!/usr/bin/env python3
"""Turn the vector drawings of a PDF region into an SVG whose viewBox is that region.

Shared by the two extractors: the page artwork (logo, the 70% mark, the step
numerals) and the brand mark that sits in the corner of every product tile.
"""
import re


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


def shift(d, dx, dy):
    """
    Move a path by rewriting its coordinates, which `path_d` writes as absolute.

    A wrapping transform would render the same but leave the file different for
    every tile the mark was cut from; rewriting is what makes two cuts of one
    brand come out byte for byte identical.
    """
    out = []
    for m in re.finditer(r"([A-Za-z])([^A-Za-z]*)", d):
        cmd, args = m.group(1), m.group(2)
        nums = [float(v) for v in re.findall(r"-?\d*\.?\d+", args)]
        if cmd in ("M", "L", "C"):
            nums = [v + (dx if k % 2 == 0 else dy) for k, v in enumerate(nums)]
        elif cmd == "H":
            nums = [v + dx for v in nums]
        elif cmd == "V":
            nums = [v + dy for v in nums]
        # -0.00 and 0.00 are the same point but not the same byte, and one such
        # digit is enough to stop two cuts of a brand hashing alike.
        out.append(cmd + " ".join(f"{v if round(v, 2) else 0.0:.2f}" for v in nums))
    return " ".join(out)


def hex_of(rgb):
    return "#%02x%02x%02x" % tuple(round(c * 255) for c in rgb)


def svg_markup(drawings, clip, recolor=None, pad=0.0, origin=False):
    """
    Every drawing that falls inside `clip`, as SVG. None when the region is empty.

    `origin` puts the viewBox at 0 0 and rewrites the artwork into it. That is
    what a mark lifted out of a tile wants: the same brand cut from two different
    tiles then comes out byte for byte the same, and one file serves every tile
    that carries it.
    """
    x0, y0, x1, y1 = clip
    x0, y0, x1, y1 = x0 - pad, y0 - pad, x1 + pad, y1 + pad
    parts = []
    for dr in drawings:
        r = dr["rect"]
        if not (r.x0 >= x0 - .5 and r.x1 <= x1 + .5 and r.y0 >= y0 - .5 and r.y1 <= y1 + .5):
            continue
        d = path_d(dr["items"])
        if not d:
            continue
        attrs = [f'd="{shift(d, -x0, -y0) if origin else d}"']
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
    if not parts:
        return None

    body = "\n  ".join(parts)
    vx, vy = (0.0, 0.0) if origin else (x0, y0)
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{vx:.2f} {vy:.2f} '
            f'{x1-x0:.2f} {y1-y0:.2f}" width="{x1-x0:.2f}" height="{y1-y0:.2f}">\n  {body}\n</svg>\n')
