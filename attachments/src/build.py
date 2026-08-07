#!/usr/bin/env python3
"""Render the email attachment PDFs.

Each source here is a normal HTML file with two <link> stylesheets. The inlined
copy is staged next to the source and removed afterwards.

    ./build.py            render everything
    ./build.py core-services

Output lands in ../ as <name>.pdf, which is what lib/templates.php attaches.

Every rendered PDF then goes through sign_fields.py, which turns any signature
line marked up in the source into a real fillable form field. Documents with no
such markup — the collateral here — come out unchanged, so this is safe to run
over everything and any future agreement or proposal gets working signature
fields without a separate step. See sign_fields.py for the markup.

That step needs pypdf, which is not in the system Python. Run this script with
the venv interpreter to get it:

    ~/.local/venvs/weyney-pdf/bin/python ./build.py

Without it the PDFs still render; they just come out unsigned-fieldless, with a
warning.
"""

import re
import subprocess
import sys
from pathlib import Path

SRC = Path(__file__).resolve().parent
OUT = SRC.parent
# The .deb build. /snap/bin/brave is gone as of 2026-08-06.
BRAVE = "/usr/bin/brave-browser"

DOCS = {
    "core-services":    "weyney-core-services",
    "website-benefits": "weyney-website-benefits",
    "local-growth":     "weyney-local-growth",
}


def inline(html: str) -> str:
    """Replace <link rel=stylesheet href=x.css> with the file's contents."""
    def sub(m):
        css = (SRC / m.group(1)).read_text(encoding="utf-8")
        return "<style>\n" + css + "\n</style>"
    out, n = re.subn(r'<link rel="stylesheet" href="([^"]+)">', sub, html)
    if not n:
        raise SystemExit("no stylesheet links found — check the source")
    return out


def render(name: str, out_name: str) -> Path:
    src = SRC / f"{name}.html"
    staged = SRC / f".build-{name}.html"
    pdf = OUT / f"{out_name}.pdf"

    staged.write_text(inline(src.read_text(encoding="utf-8")), encoding="utf-8")
    try:
        subprocess.run(
            [BRAVE, "--headless", "--no-sandbox", "--disable-gpu",
             "--no-pdf-header-footer", f"--print-to-pdf={pdf}",
             f"file://{staged}"],
            check=True, capture_output=True,
        )
    finally:
        staged.unlink(missing_ok=True)

    add_signature_fields(pdf)
    return pdf


def add_signature_fields(pdf: Path) -> None:
    """Make any marked-up signature line fillable. A no-op without markers."""
    try:
        from sign_fields import stamp
    except ImportError:
        print(f"  ! pypdf missing — {pdf.name} has no fillable signature fields.\n"
              f"    Re-run with ~/.local/venvs/weyney-pdf/bin/python if it needs them.",
              file=sys.stderr)
        return
    if added := stamp(pdf, pdf):
        print(f"  signature fields: {', '.join(added)}")


if __name__ == "__main__":
    wanted = sys.argv[1:] or list(DOCS)
    for name in wanted:
        if name not in DOCS:
            raise SystemExit(f"unknown document: {name} (have: {', '.join(DOCS)})")
        pdf = render(name, DOCS[name])
        print(f"{pdf}  {pdf.stat().st_size // 1024} KB")
