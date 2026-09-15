#!/usr/bin/env python3
"""Assemble the rendered mockup sheets into a single PDF, 1pt per design pixel."""
import glob, os, sys
import pymupdf

OUT = sys.argv[1] if len(sys.argv) > 1 else 'tools/out/karni-popup-mockup.pdf'
doc = pymupdf.open()
for path in sorted(glob.glob('tools/out/mock-p*.png')):
    pix = pymupdf.Pixmap(path)
    w, h = pix.width / 2, pix.height / 2          # rendered at 2x
    page = doc.new_page(width=w, height=h)
    page.insert_image(pymupdf.Rect(0, 0, w, h), filename=path)
    print(f"{os.path.basename(path):16s} {w:.0f}x{h:.0f}pt")
doc.set_metadata({'title': 'קרני תכלת — מוקאפ פופ-אפ מוצר',
                  'subject': 'מוקאפ לאישור לפני בנייה', 'creator': 'Karni Tchelet landing page'})
doc.save(OUT, deflate=True, garbage=4)
print(f"\n{OUT}  {os.path.getsize(OUT)/1e6:.2f} MB  {doc.page_count} pages")
