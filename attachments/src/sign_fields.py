#!/usr/bin/env python3
"""Turn signature-line markers into real, fillable PDF form fields.

A printed PDF has no form fields — Chrome's print-to-PDF draws the signature
lines as graphics, so a client who opens the file can look at the signature
block but cannot type into it. Their only route is print, sign, scan.

This adds AcroForm text fields on top of those lines. Plain text fields, not
the cryptographic kind: the client types their name and that typed name is the
signature, which is valid under E-SIGN/UETA and needs no special software.
Chrome, Brave, Edge, Firefox, macOS Preview and the free Acrobat Reader app all
fill and save them.

How the position is found
-------------------------
Chrome converts every <a href> into a PDF link annotation with an exact
rectangle. So the document marks each signature line as a link to

    https://weyney.invalid/sigfield#<field-name>

and this script swaps each of those links for a form field at the same rect.
`.invalid` is reserved by RFC 2606 and can never resolve, so a stray marker
that reaches a client is a dead link rather than a real destination.

Nothing happens to a PDF with no markers, which is why build.py can run this
over every document it renders — a leaflet stays a leaflet.

    ./sign_fields.py agreement.pdf              # in place
    ./sign_fields.py in.pdf -o out.pdf
"""

import argparse
import sys
from pathlib import Path

from pypdf import PdfReader, PdfWriter
from pypdf.generic import (
    ArrayObject, BooleanObject, DictionaryObject, FloatObject, NameObject,
    NumberObject, TextStringObject,
)

MARKER = "weyney.invalid/sigfield#"

# Tooltips shown on hover. A field name not listed here still works; it just
# gets no tooltip, so a new document can invent names without editing this.
TOOLTIPS = {
    "client-name":      "Your name and title",
    "client-signature": "Type your full name — a typed name is your signature",
    "client-date":      "Today's date",
}


def _font(base):
    return DictionaryObject({
        NameObject("/Type"):     NameObject("/Font"),
        NameObject("/Subtype"):  NameObject("/Type1"),
        NameObject("/BaseFont"): NameObject(base),
        NameObject("/Encoding"): NameObject("/WinAnsiEncoding"),
    })


def stamp(src: Path, dst: Path) -> list[str]:
    writer = PdfWriter(clone_from=str(src))

    helv = writer._add_object(_font("/Helvetica"))
    times = writer._add_object(_font("/Times-Roman"))

    fields, added = ArrayObject(), []

    for page in writer.pages:
        annots = page.get("/Annots")
        if not annots:
            continue

        keep = ArrayObject()
        for ref in annots:
            annot = ref.get_object()
            uri = (annot.get("/A") or {}).get("/URI", "")

            if annot.get("/Subtype") != "/Link" or MARKER not in str(uri):
                keep.append(ref)
                continue

            name = str(uri).split(MARKER, 1)[1].strip()
            if not name:
                keep.append(ref)
                continue

            rect = [float(v) for v in annot["/Rect"]]
            height = abs(rect[3] - rect[1])

            # The document sets a typed signature in the serif face, so the
            # field it is typed into should match rather than fight it.
            signature = "signature" in name
            size = 16 if signature else 12
            res = "/TiRo" if signature else "/Helv"

            # Sit the text on the ruled line instead of floating above it.
            rect[1] += 1.0
            # Auto-fit scales to the box, so the box height is what actually sets
            # the type size. 1.25 lands it near `size` and keeps the client's
            # column matching the countersigned one opposite.
            rect[3] = rect[1] + min(height, size * 1.25)

            widget = DictionaryObject({
                NameObject("/Type"):     NameObject("/Annot"),
                NameObject("/Subtype"):  NameObject("/Widget"),
                NameObject("/FT"):       NameObject("/Tx"),
                NameObject("/Ff"):       NumberObject(0),
                NameObject("/T"):        TextStringObject(name),
                NameObject("/V"):        TextStringObject(""),
                # Size 0 means auto-fit. "Abdirahman Abdullahi, Director of
                # Operations" overflows a fixed 12pt and silently loses its tail;
                # auto-fit shrinks it instead. `size` still sets the box height.
                NameObject("/DA"):       TextStringObject(f"{res} 0 Tf 0 g"),
                NameObject("/F"):        NumberObject(4),      # printable
                NameObject("/Rect"):     ArrayObject(FloatObject(v) for v in rect),
                NameObject("/P"):        page.indirect_reference,
                NameObject("/MK"):       DictionaryObject(),   # no border, no fill
            })
            if name in TOOLTIPS:
                widget[NameObject("/TU")] = TextStringObject(TOOLTIPS[name])

            ref = writer._add_object(widget)
            keep.append(ref)
            fields.append(ref)
            added.append(name)

        page[NameObject("/Annots")] = keep

    if not added:
        return []

    writer._root_object[NameObject("/AcroForm")] = writer._add_object(DictionaryObject({
        NameObject("/Fields"): fields,
        # Readers draw the text themselves. Without this a filled field can
        # look empty in viewers that trust a stale appearance stream.
        NameObject("/NeedAppearances"): BooleanObject(True),
        NameObject("/DA"): TextStringObject("/Helv 12 Tf 0 g"),
        NameObject("/DR"): DictionaryObject({
            NameObject("/Font"): DictionaryObject({
                NameObject("/Helv"): helv,
                NameObject("/TiRo"): times,
            })
        }),
    }))

    with open(dst, "wb") as fh:
        writer.write(fh)
    return added


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("pdf", type=Path)
    ap.add_argument("-o", "--out", type=Path, help="default: overwrite in place")
    ap.add_argument("-q", "--quiet", action="store_true")
    args = ap.parse_args()

    if not args.pdf.is_file():
        print(f"no such file: {args.pdf}", file=sys.stderr)
        return 1

    added = stamp(args.pdf, args.out or args.pdf)
    if not args.quiet:
        target = args.out or args.pdf
        print(f"{target.name}: {len(added)} field(s) — {', '.join(added)}"
              if added else f"{args.pdf.name}: no signature markers, left as is")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
