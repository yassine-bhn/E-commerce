#!/usr/bin/env python3
"""Copy the CBM catalogue pictures of the imported products into public/uploads.

The importer stores only paths that are served by the backend, never the remote
cbmtunisie.com.tn URLs. This script materialises those paths from the local
scrape folder (``cbm_catalogue/<id>-<slug>/images/...``).
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
    "CBM_CATALOGUE_DIR", os.path.join(WORKSPACE, "nobili-scrapping", "cbm_catalogue")
)
DEST_ROOT = os.environ.get(
    "CBM_UPLOAD_DIR", os.path.join(ECOMMERCE, "public", "uploads", "catalogue", "cbm")
)


def main() -> int:
    rows = json.load(open(IMPORT_JSON, encoding="utf-8"))
    copied = 0
    skipped = 0
    missing = []

    for row in rows:
        slug = (row.get("slug") or "").strip()
        folder = (row.get("cbm_folder") or "").strip()
        local_images = row.get("cbm_local_images") or []
        if slug == "" or folder == "":
            missing.append((row.get("cbm_ref"), "missing slug/folder"))
            continue
        if not local_images:
            missing.append((row.get("cbm_ref"), "no local images"))
            continue

        dest_dir = os.path.join(DEST_ROOT, slug)
        os.makedirs(dest_dir, exist_ok=True)
        for rel in local_images:
            src = os.path.join(SRC_ROOT, folder, rel)
            if not os.path.isfile(src):
                missing.append((row.get("cbm_ref"), rel))
                continue
            dst = os.path.join(dest_dir, os.path.basename(rel))
            if os.path.isfile(dst) and os.path.getsize(dst) == os.path.getsize(src):
                skipped += 1
                continue
            shutil.copy2(src, dst)
            copied += 1

    print(f"products: {len(rows)}")
    print(f"copied: {copied}")
    print(f"already present: {skipped}")
    print(f"missing: {len(missing)}")
    for ref, path in missing[:20]:
        print(f"  MISSING {ref}: {path}")
    if missing:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
