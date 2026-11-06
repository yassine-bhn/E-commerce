#!/usr/bin/env python3
import csv, json, re, unicodedata, os, sys
from collections import Counter, defaultdict

BASE = os.path.dirname(os.path.abspath(__file__))
SOPAL_PATH = os.environ.get("SOPAL_CATALOGUE_DIR", "/home/yassine/Desktop/freelance/Zidaine-groupe/nobili-scrapping/sopal_catalogue")
CSV_PATH = os.environ.get("ZIDAINE_CSV", "/home/yassine/Desktop/freelance/Zidaine-groupe/nobili-scrapping/zidaine-groupe-product.csv")
OUT_DIR = os.path.join(BASE, "out")
os.makedirs(OUT_DIR, exist_ok=True)

def norm_text(s):
    s = (s or "").lower().replace("œ", "oe")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9]+", " ", s)).strip()

def norm_ref(s):
    s = (s or "").strip().lower().replace("œ", "oe")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    s = re.sub(r"[^a-z0-9]", "", s)
    s = s.lstrip("0")
    return s if s else "0"

def slugify(s):
    s = (s or "").lower().replace("œ", "oe")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    s = re.sub(r"[^a-z0-9]+", "-", s).strip("-")
    return (s[:80] if s else "product")

def load_csv():
    rows = []
    with open(CSV_PATH, encoding="iso-8859-1", newline="") as f:
        for r in csv.reader(f):
            if len(r) == 55:
                rows.append(r)
    # dedupe by internal ref, keep first listing occurrence
    seen = set()
    uniq = []
    for r in rows:
        if r[1] in seen:
            continue
        seen.add(r[1])
        uniq.append(r)
    for r in uniq:
        r.append(norm_text(r[2]))   # 55 normalized name
        r.append(norm_ref(r[2]))    # 56 normalized ref-style name
    return rows, uniq

def classify(d):
    sub = (d.get("subcategory") or "").strip().lower()
    name = norm_text(d.get("name"))
    if sub in ("raccords", "collecteurs") or sub == "solution sopaflo":
        return ("plomberie", "raccord", 0.9)
    if sub == "vannes et robinets":
        return ("plomberie", "robinet", 0.9)
    if sub == "accessoires de salle de bain":
        return ("plomberie", "Acessoir salle de bain", 0.9)
    if sub in ("systemes de vidage", "articles de vidage"):
        return ("plomberie", "accessoirs", 0.85)
    # robinetterie sanitaire by product type
    if "cuisine" in name or "evier" in name:
        return ("plomberie", "Mitigeur cuisine", 0.9)
    if "lavabo" in name or "vasque" in name:
        return ("plomberie", "lavabo et lavabo long", 0.85)
    if "colonne" in name or "colone" in name:
        return ("plomberie", "colones", 0.85)
    if "bidet" in name:
        return ("plomberie", "Mitigeur douches et bains de douches", 0.85)
    if "flexible" in name or "douchette" in name or "barre" in name or "support" in name \
            or "tablette" in name or "pomme" in name or "bras" in name or "prise d eau" in name \
            or "ensemble" in name or "porte " in name or "etagere" in name or "derouleur" in name \
            or "kit pour douchette" in name:
        return ("plomberie", "Acessoir salle de bain", 0.85)
    if "toilette" in name and ("mitigeur" in name or "robinet" in name or "melangeur" in name):
        return ("plomberie", "Mitigeur douches et bains de douches", 0.85)
    if "douche" in name or "bain" in name or "mitigeur" in name or "melangeur" in name:
        return ("plomberie", "Mitigeur douches et bains de douches", 0.85)
    if "robinet" in name or "vanne" in name or "cartouche" in name or "volant" in name:
        return ("plomberie", "robinet", 0.85)
    if "siphon" in name or "vidage" in name or "bonde" in name or "caniveau" in name:
        return ("plomberie", "accessoirs", 0.8)
    if "raccord" in name or "coude" in name or "manchon" in name or "reduction" in name \
            or "collecteur" in name or "bouchon" in name:
        return ("plomberie", "raccord", 0.85)
    return (None, None, 0.0)

def main():
    rows, uniq = load_csv()
    print(f"CSV rows: {len(rows)}, unique internal refs: {len(uniq)}")

    # load sopal products
    products = []
    for root, _, files in os.walk(SOPAL_PATH):
        for fn in files:
            if fn == "product.json":
                products.append(json.load(open(os.path.join(root, fn), encoding="utf-8")))
    # sort by folder for deterministic dedupe
    products.sort(key=lambda p: (p.get("folder") or p.get("slug") or ""))

    # dedupe sopal by ref, keep first listing occurrence
    seen_refs = set()
    deduped = []
    dup_removed = 0
    for p in products:
        ref = (p.get("ref") or "").strip()
        if ref == "":
            deduped.append(p)  # empty refs handled as unmatched later
            continue
        if ref in seen_refs:
            dup_removed += 1
            continue
        seen_refs.add(ref)
        deduped.append(p)
    print(f"Sopal products: {len(products)}, after dedupe: {len(deduped)}, dup removed: {dup_removed}")

    import_rows = []
    review_rows = []
    unmatched_rows = []

    for p in deduped:
        ref = (p.get("ref") or "").strip()
        folder = (p.get("folder") or p.get("slug") or "").strip()
        name = (p.get("name") or "").strip()

        if ref == "":
            unmatched_rows.append({"sopal_ref": "", "sopal_name": name, "sopal_categories": [p.get("category"), p.get("subcategory")], "reason": "empty ref"})
            continue

        raw_refs = [str(v).strip() for v in (p.get("ref"), p.get("code")) if v and str(v).strip()]
        patterns = []
        for v in raw_refs:
            nv = unicodedata.normalize("NFKD", v).lower()
            nv = "".join(c for c in nv if not unicodedata.combining(c))
            nv = re.sub(r"\s+", " ", nv).strip()
            if len(nv) >= 4:
                patterns.append(re.compile(r"(?<![a-z0-9])" + re.escape(nv) + r"(?![a-z0-9])"))
        hits = []
        for r in uniq:
            csvn = unicodedata.normalize("NFKD", r[2]).lower()
            csvn = "".join(c for c in csvn if not unicodedata.combining(c))
            if any(pat.search(csvn) for pat in patterns):
                hits.append(r)

        if not hits:
            unmatched_rows.append({"sopal_ref": ref, "sopal_name": name, "sopal_categories": [p.get("category"), p.get("subcategory")], "reason": "no csv match"})
            continue

        # multi-match -> review
        if len(hits) > 1:
            review_rows.append({
                "sopal_ref": ref,
                "sopal_name": name,
                "csv_refs": [r[1] for r in hits],
                "csv_names": [r[2] for r in hits],
                "reason": "multiple csv matches",
                "sopal_categories": [p.get("category"), p.get("subcategory")],
            })
            continue

        r = hits[0]
        csv_ref = r[1].strip()
        csv_name = r[2].strip()
        price_ht = r[33].strip()
        price_ttc = r[34].strip()
        vat = r[37].strip()
        stock_qty = r[43].strip()
        ean = r[47].strip()

        top_slug, subcat, cls_conf = classify(p)
        if top_slug is None:
            review_rows.append({
                "sopal_ref": ref,
                "sopal_name": name,
                "csv_ref": csv_ref,
                "csv_name": csv_name,
                "reason": "unclassified category",
                "sopal_categories": [p.get("category"), p.get("subcategory")],
            })
            continue

        sopal_desc = (p.get("description") or "").strip()
        # features often duplicate description; append only if distinct
        feats = [f for f in (p.get("features") or []) if f and f.strip() and f.strip() != sopal_desc]
        if feats:
            sopal_desc = (sopal_desc + "\n" + "\n".join(feats)).strip() if sopal_desc else "\n".join(feats)

        csv_desc = ""  # csv has no description text

        images_saved = p.get("images_saved") or []
        docs_saved = p.get("documents_saved") or []

        local_images = [x.get("file") for x in images_saved if x.get("file")]
        local_docs = [x.get("file") for x in docs_saved if x.get("file")]
        doc_labels = [x.get("label") or "Document" for x in docs_saved if x.get("file")]

        if not local_images:
            review_rows.append({
                "sopal_ref": ref,
                "sopal_name": name,
                "csv_ref": csv_ref,
                "csv_name": csv_name,
                "reason": "no images",
                "sopal_categories": [p.get("category"), p.get("subcategory")],
            })
            continue

        sopal_image = (p.get("images") or [None])[0]
        sopal_images = p.get("images") or []

        import_rows.append({
            "sopal_ref": ref,
            "slug": slugify(ref),
            "sopal_name": name,
            "sopal_brand": "SOPAL",
            "csv_ref": csv_ref,
            "csv_name": csv_name,
            "score": 1.0,
            "method": "ref-in-csv-name",
            "category_slug": top_slug,
            "subcategory": subcat,
            "cls_confidence": cls_conf,
            "csv_price_ht": price_ht,
            "csv_price_ttc": price_ttc,
            "csv_vat": vat,
            "csv_stock_qty": stock_qty,
            "csv_ean": ean,
            "csv_description": csv_desc,
            "sopal_description": sopal_desc,
            "sopal_image": sopal_image,
            "sopal_images": sopal_images,
            "sopal_folder": folder,
            "sopal_local_images": local_images,
            "sopal_local_docs": local_docs,
            "sopal_doc_labels": doc_labels,
            "sopal_attributes": {},
            "sopal_colors": [],
            "sopal_labels": [],
            "sopal_collection": (p.get("collection") or "").strip(),
            "sopal_variants": p.get("tables") or [],
            "multi_match": False,
        })

    # write manifest
    with open(os.path.join(OUT_DIR, "import.json"), "w", encoding="utf-8") as f:
        json.dump(import_rows, f, ensure_ascii=False, indent=2)

    # review csv
    with open(os.path.join(OUT_DIR, "review.csv"), "w", encoding="utf-8", newline="") as f:
        w = csv.writer(f)
        w.writerow(["sopal_ref", "sopal_name", "reason", "csv_refs", "csv_names", "categories"])
        for r in review_rows:
            w.writerow([
                r.get("sopal_ref", ""),
                r.get("sopal_name", ""),
                r.get("reason", ""),
                " | ".join(r.get("csv_refs", [])),
                " | ".join(r.get("csv_names", [])),
                " / ".join(str(x) for x in r.get("sopal_categories", [])),
            ])

    # unmatched csv
    with open(os.path.join(OUT_DIR, "unmatched.csv"), "w", encoding="utf-8", newline="") as f:
        w = csv.writer(f)
        w.writerow(["sopal_ref", "sopal_name", "reason", "categories"])
        for r in unmatched_rows:
            w.writerow([
                r.get("sopal_ref", ""),
                r.get("sopal_name", ""),
                r.get("reason", ""),
                " / ".join(str(x) for x in r.get("sopal_categories", [])),
            ])

    # summary
    cat_counts = Counter((m["category_slug"], m["subcategory"]) for m in import_rows)
    summary = {
        "sopal_products_total": len(products),
        "sopal_products_deduped": len(deduped),
        "sopal_duplicates_removed": dup_removed,
        "csv_rows_total": len(rows),
        "csv_unique_refs": len(uniq),
        "matched": len(import_rows),
        "review": len(review_rows),
        "unmatched": len(unmatched_rows),
        "matched_csv_refs": len(set(m["csv_ref"] for m in import_rows)),
        "category_counts": {f"{a}/{b}": c for (a, b), c in sorted(cat_counts.items())},
        "products_with_docs": sum(1 for m in import_rows if m["sopal_local_docs"]),
        "doc_count": sum(len(m["sopal_local_docs"]) for m in import_rows),
        "image_count": sum(len(m["sopal_local_images"]) for m in import_rows),
    }
    with open(os.path.join(OUT_DIR, "summary.json"), "w", encoding="utf-8") as f:
        json.dump(summary, f, ensure_ascii=False, indent=2)

    print(json.dumps(summary, ensure_ascii=False, indent=2))

if __name__ == "__main__":
    main()
