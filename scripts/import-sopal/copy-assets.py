#!/usr/bin/env python3
"""Copy the Sopal catalogue pictures and PDF technical sheets of the imported
products into public/uploads/catalogue/sopal/<slug>/.

The importer stores only paths served by the backend, never remote sopal.com
URLs. This script materialises those paths from the local scrape folder.
"""
import json
import os
import shutil
import sys

BASE = os.path.dirname(os.path.abspath(__file__))
ECOMMERCE = os.path.abspath(os.path.join(BASE, "..", ".."))
WORKSPACE = os.path.abspath(os.path.join(ECOMMERCE, ".."))

IMPORT_JSON = os.environ.get("IMPORT_JSON", os.path.join(BASE, "out", "import.json"))
SRC_ROOT = os.environ.get(
    "SOPAL_CATALOGUE_DIR", os.path.join(WORKSPACE, "nobili-scrapping", "sopal_catalogue")
)
DEST_ROOT = os.environ.get(
    "SOPAL_UPLOAD_DIR", os.path.join(ECOMMERCE, "public", "uploads", "catalogue", "sopal")
)


def copy_one(rel, slug, missing, copied, skipped):
    src = os.path.join(SRC_ROOT, rel)
    if not os.path.isfile(src):
        missing.append((slug, rel))
        return 0, 0, 1
    dest_dir = os.path.join(DEST_ROOT, slug)
    os.makedirs(dest_dir, exist_ok=True)
    dst = os.path.join(dest_dir, os.path.basename(rel))
    if os.path.isfile(dst) and os.path.getsize(dst) == os.path.getsize(src):
        return 0, 1, 0
    shutil.copy2(src, dst)
    return 1, 0, 0


def main() -> int:
    rows = json.load(open(IMPORT_JSON, encoding="utf-8"))
    copied = skipped = missing_n = 0
    missing = []

    for row in rows:
        slug = (row.get("slug") or "").strip()
        if slug == "":
            missing.append((row.get("sopal_ref"), "missing slug"))
            missing_n += 1
            continue
        for rel in (row.get("sopal_local_images") or []):
            c, s, m = copy_one(rel, slug, missing, copied, skipped)
            copied += c; skipped += s; missing_n += m
        for rel in (row.get("sopal_local_docs") or []):
            c, s, m = copy_one(rel, slug, missing, copied, skipped)
            copied += c; skipped += s; missing_n += m

    print(f"products: {len(rows)}")
    print(f"copied: {copied}")
    print(f"already present: {skipped}")
    print(f"missing: {missing_n}")
    for ref, path in missing[:20]:
        print(f"  MISSING {ref}: {path}")
    if missing_n:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
PY