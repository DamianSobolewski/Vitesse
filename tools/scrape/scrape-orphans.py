#!/usr/bin/env python3
"""Drugi przebieg: modele, ktore nie maja poziomu generacji.

Na starym serwisie czesc modeli (glownie silniki przemyslowe i ciagniki)
ma tabele silnikow bezposrednio na stronie modelu. Pierwszy przebieg ich
nie zlapal, bo szukal silnikow tylko na poziomie generacji.
Dopisuje im sztuczna generacje o slugu 'wszystkie'.
"""
import json, pathlib, importlib.util
from concurrent.futures import ThreadPoolExecutor

spec = importlib.util.spec_from_file_location("sc", "tools/scrape/scrape-catalog.py")
sc = importlib.util.module_from_spec(spec); spec.loader.exec_module(sc)

OUT = pathlib.Path("content/catalog")
load = lambda n: json.loads((OUT / f"{n}.json").read_text(encoding="utf-8"))

models, gens, engines, gains = load("models"), load("generations"), load("engines"), load("gains")
have = {(g["make_slug"], g["model_slug"]) for g in gens}
orphans = [m for m in models if (m["make_slug"], m["slug"]) not in have]
print(f"modeli bez generacji: {len(orphans)}")

def work(m):
    doc = sc.fetch(m["legacy_key"])
    return m, (sc.parse_engines(doc) if doc else [])

with ThreadPoolExecutor(3) as ex:
    results = list(ex.map(work, orphans))

added_g = added_e = 0
broken = []


for m, rows in results:
    rows = [r for r in rows if r["stock_hp"]]
    if not rows:
        broken.append(m["legacy_key"])
        continue

    gens.append({"make_slug": m["make_slug"], "model_slug": m["slug"], "slug": "wszystkie",
                 "name": "Wszystkie wersje", "legacy_key": m["legacy_key"] + "#all",
                 "sort": 0, "year_from": None, "year_to": None})
    added_g += 1

    for i, e in enumerate(rows):
        lk = e["legacy_key"] or f"{m['legacy_key']}_{e['name']}"
        engines.append({"make_slug": m["make_slug"], "model_slug": m["slug"], "gen_slug": "wszystkie",
                        "slug": sc.slugify(e["name"]), "name": e["name"], "fuel": e["fuel"],
                        "stock_kw": e["stock_kw"], "stock_hp": e["stock_hp"],
                        "stock_nm": e["stock_nm"], "legacy_key": lk, "sort": i})
        added_e += 1
        if e["chip_hp"]:
            gains.append({"engine_legacy_key": lk, "service_code": "chip",
                          "tuned_hp": e["chip_hp"], "tuned_nm": e["chip_nm"]})
        if e["box_hp"]:
            gains.append({"engine_legacy_key": lk, "service_code": "powerbox",
                          "tuned_hp": e["box_hp"], "tuned_nm": e["box_nm"]})

for name, data in [("generations", gens), ("engines", engines), ("gains", gains)]:
    (OUT / f"{name}.json").write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")

(OUT / "_gaps.json").write_text(json.dumps(broken, ensure_ascii=False, indent=1), encoding="utf-8")

man = json.loads((OUT / "MANIFEST.json").read_text(encoding="utf-8"))
man["counts"] = {"makes": len(load("makes")), "models": len(models),
                 "generations": len(gens), "engines": len(engines), "gains": len(gains)}
man["broken_pages"] = len(broken)
(OUT / "MANIFEST.json").write_text(json.dumps(man, ensure_ascii=False, indent=1), encoding="utf-8")

print(f"dodano {added_g} generacji zbiorczych i {added_e} silnikow")
print(f"stron nie do pobrania (HTTP 500 na starym serwisie): {len(broken)}")
print("nowe liczby:", json.dumps(man["counts"], ensure_ascii=False))
