#!/usr/bin/env python3
"""Render the design PDF to a 1440px-wide PNG to diff the build against."""
import sys, pymupdf
pdf = sys.argv[1]
out = sys.argv[2] if len(sys.argv) > 2 else "tools/out/design.png"
page = pymupdf.open(pdf)[0]
pix = page.get_pixmap(matrix=pymupdf.Matrix(1, 1))
pix.save(out)
print(f"{out} {pix.width}x{pix.height}")
