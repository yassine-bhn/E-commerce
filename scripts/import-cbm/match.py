#!/usr/bin/env python3
import csv, json, re, unicodedata, difflib, os, sys
from collections import Counter, defaultdict

BASE = os.path.dirname(os.path.abspath(__file__))
CBM_PATH = os.environ.get("CBM_PRODUCTS_JSON", "/home/yassine/Desktop/freelance/Zidaine-groupe/nobili-scrapping/cbm_catalogue/products.json")
CSV_PATH = os.environ.get("ZIDAINE_CSV", "/home/yassine/Desktop/freelance/Zidaine-groupe/nobili-scrapping/zidaine-groupe-product.csv")
OUT_DIR = os.path.join(BASE, "out")
os.makedirs(OUT_DIR, exist_ok=True)

def norm_text(s):
    s = (s or "").lower()
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9]+", " ", s)).strip()

def norm_ref(s):
    s = (s or "").strip().lower()
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    s = re.sub(r"[^a-z0-9]", "", s)
    s = s.lstrip("0")
    return s if s else "0"

def slugify(s):
    s = (s or "").lower()
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    s = re.sub(r"[^a-z0-9]+", "-", s).strip("-")
    return (s[:128] if s else "product")

def load_csv():
    rows = []
    with open(CSV_PATH, encoding="iso-8859-1", newline="") as f:
        for r in csv.reader(f):
            if len(r) == 55:
                rows.append(r)
    # dedupe by internal ref (col 1), keep first
    seen = set()
    uniq = []
    for r in rows:
        if r[1] in seen:
            continue
        seen.add(r[1])
        uniq.append(r)
    return uniq

def cbm_text(p):
    parts = [p.get("name") or "", p.get("short_description") or "", p.get("description") or ""]
    for c in p.get("categories") or []:
        parts.append(c.get("name") or "")
    for a, v in (p.get("attributes") or {}).items():
        parts.append(f"{a} {v}")
    return " ".join(parts)

def classify(p):
    text = cbm_text(p).lower()
    text = unicodedata.normalize("NFKD", text)
    text = "".join(c for c in text if not unicodedata.combining(c))
    # returns (top_slug, subcategory, confidence)
    if "seche" in text or "serviette" in text:
        return ("chaufferie", "Séche serviette", 0.9)
    if "mitigeur" in text:
        if "cuisine" in text:
            return ("plomberie", "Mitigeur cuisine", 0.9)
        return ("plomberie", "Mitigeur douches et bains de douches", 0.85)
    if "lavabo" in text or "vasque" in text:
        return ("plomberie", "lavabo et lavabo long", 0.85)
    if "colonne" in text or "colone" in text:
        return ("plomberie", "colones", 0.85)
    if "cuvette" in text:
        return ("plomberie", "cuvettes", 0.7)  # ambiguous with toilettes
    if "toilette" in text or "wc" in text or "urinoir" in text:
        return ("plomberie", "toilettes", 0.7)  # ambiguous with cuvettes
    if "robinet" in text:
        return ("plomberie", "robinet", 0.85)
    if "accessoire" in text:
        if "douche" in text or "salle de bain" in text or "miroir" in text:
            return ("plomberie", "Acessoir salle de bain", 0.8)
        return ("plomberie", "accessoirs", 0.75)  # ambiguous
    if any(k in text for k in ["tube", "raccord", "pvc", "cuivre", "pehd", "evacuation", "multicouche", "siphon"]):
        if "raccord" in text:
            return ("plomberie", "raccord", 0.85)
        if "siphon" in text:
            return ("plomberie", "accessoirs", 0.8)
        if "tube" in text or "pvc" in text or "cuivre" in text or "pehd" in text or "multicouche" in text or "evacuation" in text:
            return ("plomberie", "tubes", 0.8)
        return ("plomberie", "accessoirs", 0.75)
    if "douche" in text:
        return ("plomberie", "Acessoir salle de bain", 0.8)
    return (None, None, 0.0)

def main():
    cbm = json.load(open(CBM_PATH, encoding="utf-8"))["products"]
    csv_rows = load_csv()

    # build normalized index for csv
    for r in csv_rows:
        r.append(norm_text(r[2]))  # append normalized name
        r.append(norm_ref(r[1]))   # internal ref normalized
        r.append(norm_ref(r[49]))  # supplier ref normalized
        r.append(norm_ref(r[47]))  # ean normalized

    matched = []
    review = []
    unmatched = []

    multi_match_flags = []

    for p in cbm:
        ref = (p.get("reference") or p.get("name") or "").strip()
        nr = norm_ref(ref)
        candidates_by_ref = []
        # Tier 1: exact reference (trim+case) in col1, col2, col49, col47
        for r in csv_rows:
            if ref and (ref == r[1].strip() or ref.lower() == r[2].strip().lower() or ref == r[49].strip() or ref == r[47].strip()):
                candidates_by_ref.append((r, 1.0, "exact"))
        # Tier 2: normalized reference
        if not candidates_by_ref:
            for r in csv_rows:
                if nr in (r[-3], r[-2], r[-1]) or nr == r[-3] or nr == r[-2] or nr == r[-1]:
                    # ensure normalized ref equality or containment
                    if nr == r[-3] or nr == r[-2] or nr == r[-1] or nr in r[-3] or nr in r[-2]:
                        candidates_by_ref.append((r, 0.95, "normalized"))
        # dedupe candidates by csv internal ref
        seen_csv = set()
        uniq_cands = []
        for r, score, method in candidates_by_ref:
            if r[1] in seen_csv:
                continue
            seen_csv.add(r[1])
            uniq_cands.append((r, score, method))

        if len(uniq_cands) > 1:
            multi_match_flags.append(ref)

        top_slug, subcat, cls_conf = classify(p)

        # Tier 3: fuzzy if no ref hit
        fuzzy_cands = []
        if not uniq_cands:
            q = norm_text(cbm_text(p))
            # remove brand words
            for b in ["nobili", "jika", "ferplast", "ndw", "cgs", "kariba", "bagno", "associat"]:
                q = q.replace(b, "")
            for r in csv_rows:
                cn = r[-4] if len(r) > 4 else norm_text(r[2])
                # require at least one 3+ digit token in common (dimensions) OR high token overlap
                qnums = set(re.findall(r"\d{3,}", q))
                cnums = set(re.findall(r"\d{3,}", cn))
                if not (qnums & cnums):
                    # allow if token jaccard high
                    qtoks = set(q.split())
                    ctoks = set(cn.split())
                    if len(qtoks & ctoks) < 3:
                        continue
                score = difflib.SequenceMatcher(None, q, cn).ratio()
                if score >= 0.70:
                    fuzzy_cands.append((r, score, "fuzzy"))
            fuzzy_cands.sort(key=lambda x: x[1], reverse=True)
            fuzzy_cands = fuzzy_cands[:3]
            if fuzzy_cands:
                best_score = fuzzy_cands[0][1]
                if best_score >= 0.95:
                    uniq_cands = [(fuzzy_cands[0][0], best_score, "fuzzy")]
                elif best_score >= 0.80:
                    # needs review: put in review with top candidates
                    pass

        # Determine bucket
        if uniq_cands:
            best_score = uniq_cands[0][1]
            best_method = uniq_cands[0][2]
            r = uniq_cands[0][0]
            csv_ref = r[1]
            csv_name = r[2]
            csv_price = r[33]
            csv_ttc = r[34]
            csv_vat = r[37]
            csv_stock = r[43]
            csv_ean = r[47]
            row = {
                "cbm_ref": ref,
                "slug": slugify(ref),
                "cbm_name": p.get("name"),
                "cbm_brand": p.get("brand"),
                "csv_ref": csv_ref,
                "csv_name": csv_name,
                "score": round(best_score, 4),
                "method": best_method,
                "category_slug": top_slug,
                "subcategory": subcat,
                "cls_confidence": cls_conf,
                "csv_price_ht": csv_price,
                "csv_price_ttc": csv_ttc,
                "csv_vat": csv_vat,
                "csv_stock_qty": csv_stock,
                "csv_ean": csv_ean,
            "csv_description": (r[6].strip() or r[16].strip()),
            "cbm_description": (p.get("short_description") or p.get("description") or "").strip(),
            "cbm_image": (p.get("thumbnail") or (p.get("image_urls") or [""])[0]),
            "cbm_images": (p.get("image_urls") or []),
            "cbm_attributes": (p.get("attributes") or {}),
            "cbm_colors": (p.get("colors") or []),
            "cbm_labels": (p.get("labels") or []),
                "multi_match": ref in multi_match_flags,
            }
            if best_score >= 0.95 and cls_conf >= 0.7 and top_slug:
                matched.append(row)
            else:
                review.append(row)
        elif fuzzy_cands:
            # review with top candidates
            for r, score, method in fuzzy_cands:
                review.append({
                    "cbm_ref": ref,
                    "cbm_name": p.get("name"),
                    "cbm_brand": p.get("brand"),
                    "csv_ref": r[1],
                    "csv_name": r[2],
                    "score": round(score, 4),
                    "method": method,
                    "category_slug": top_slug,
                    "subcategory": subcat,
                    "cls_confidence": cls_conf,
                    "csv_price_ht": r[33],
                    "csv_price_ttc": r[34],
                    "csv_vat": r[37],
                    "csv_stock_qty": r[43],
                    "csv_ean": r[47],
                    "multi_match": False,
                })
            # if no candidate qualifies, unmatched
            if not fuzzy_cands or fuzzy_cands[0][1] < 0.80:
                unmatched.append({
                    "cbm_ref": ref,
                    "cbm_name": p.get("name"),
                    "cbm_brand": p.get("brand"),
                    "cbm_categories": [c.get("name") for c in (p.get("categories") or [])],
                    "reason": "no match >=0.80",
                })
        else:
            unmatched.append({
                "cbm_ref": ref,
                "cbm_name": p.get("name"),
                "cbm_brand": p.get("brand"),
                "cbm_categories": [c.get("name") for c in (p.get("categories") or [])],
                "reason": "unmatched",
            })

    # Write CSVs
    def write_csv(name, rows, fieldnames):
        path = os.path.join(OUT_DIR, name)
        with open(path, "w", encoding="utf-8", newline="") as f:
            w = csv.DictWriter(f, fieldnames=fieldnames)
            w.writeheader()
            for r in rows:
                w.writerow(r)

    match_fields = ["cbm_ref","slug","cbm_name","cbm_brand","csv_ref","csv_name","score","method","category_slug","subcategory","cls_confidence","csv_price_ht","csv_price_ttc","csv_vat","csv_stock_qty","csv_ean","csv_description","cbm_description","cbm_image","cbm_images","cbm_attributes","cbm_colors","cbm_labels","multi_match"]
    write_csv("matched.csv", matched, match_fields)
    write_csv("review.csv", review, match_fields)
    unmatched_fields = ["cbm_ref","cbm_name","cbm_brand","cbm_categories","reason"]
    write_csv("unmatched.csv", unmatched, unmatched_fields)

    # Build import.json for auto-accepted
    import_rows = []
    for m in matched:
        import_rows.append(m)
    with open(os.path.join(OUT_DIR, "import.json"), "w", encoding="utf-8") as f:
        json.dump(import_rows, f, ensure_ascii=False, indent=2)

    # summary
    cat_counts = Counter((m["category_slug"], m["subcategory"]) for m in matched)
    summary = {
        "total_cbm": len(cbm),
        "total_csv_unique": len(csv_rows),
        "matched_auto": len(matched),
        "review_rows": len(review),
        "unmatched": len(unmatched),
        "multi_match_flags": len(multi_match_flags),
        "category_counts": {f"{a}/{b}": c for (a, b), c in sorted(cat_counts.items())},
        "empty_categories": ["pisines", "electricite"],
    }
    with open(os.path.join(OUT_DIR, "summary.json"), "w", encoding="utf-8") as f:
        json.dump(summary, f, ensure_ascii=False, indent=2)

    print(json.dumps(summary, ensure_ascii=False, indent=2))

if __name__ == "__main__":
    main()