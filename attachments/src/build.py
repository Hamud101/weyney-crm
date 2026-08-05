#!/usr/bin/env python3
"""Render the email attachment PDFs.

Each source here is a normal HTML file with two <link> stylesheets. Brave is the
snap build, so it cannot read /tmp — the inlined copy is staged next to the
source (under ~/weyney) and removed afterwards.

    ./build.py            render everything
    ./build.py core-services

Output lands in ../ as <name>.pdf, which is what lib/templates.php attaches.
"""

import re
import subprocess
import sys
from pathlib import Path

SRC = Path(__file__).resolve().parent
OUT = SRC.parent
BRAVE = "/snap/bin/brave"

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
    return pdf


if __name__ == "__main__":
    wanted = sys.argv[1:] or list(DOCS)
    for name in wanted:
        if name not in DOCS:
            raise SystemExit(f"unknown document: {name} (have: {', '.join(DOCS)})")
        pdf = render(name, DOCS[name])
        print(f"{pdf}  {pdf.stat().st_size // 1024} KB")
