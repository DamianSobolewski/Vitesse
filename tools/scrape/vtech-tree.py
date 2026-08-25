#!/usr/bin/env python3
"""Pobiera drzewo pojazdow z konfiguratora V-techa i normalizuje je do naszego formatu.

Zrodlo: https://sklep.vtech.pl/konfigurator-powerchip/
Dane siedza w zmiennej JS `var vtFitmentSearch = {...}` w HTML strony.

Struktura zrodlowa to marka -> model -> rocznik -> lista silnikow, przy czym
generacja jest zaszyta w polu `value` jako "generacja::silnik". Rozbijamy to
na nasze cztery poziomy i wyliczamy zakres rocznikow dla kazdej pary.
"""
import json, pathlib, re, sys, urllib.request

SRC = "https://sklep.vtech.pl/konfigurator-powerchip/"
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36"
OUT = pathlib.Path("content/catalog")
NEEDLE = "var vtFitmentSearch = "

TRUCK_HINT = ("truck", "daf", "iveco", "man", "scania", "volvo-truck", "mercedes-truck")


def slugify(s: str) -> str:
    tr = str.maketrans("ąćęłńóśźżĄĆĘŁŃÓŚŹŻ", "acelnoszzACELNOSZZ")
    s = s.translate(tr).lower()
    s = re.sub(r"[^a-z0-9]+", "-", s)
    return re.sub(r"-{2,}", "-", s).strip("-") or "x"


def extract_json(html: str) -> dict:
    i = html.find(NEEDLE)
    if i < 0:
        raise SystemExit("BLAD: nie znaleziono 'var vtFitmentSearch' — V-tech zmienil strukture strony.")
    start = i + len(NEEDLE)
    depth = 0; in_str = False; esc = False
    for j in range(start, len(html)):
        c = html[j]
        if esc:
            esc = False; continue
        if in_str:
            if c == "\\": esc = True
            elif c == '"': in_str = False
            continue
        if c == '"': in_str = True
        elif c == "{": depth += 1
        elif c == "}":
            depth -= 1
            if depth == 0:
                return json.loads(html[start:j + 1])
    raise SystemExit("BLAD: nie udalo sie domknac obiektu JSON.")


def main() -> None:
    req = urllib.request.Request(SRC, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=60) as r:
        html = r.read().decode("utf-8", "replace")
    print(f"pobrano {len(html)/1024:.0f} KB")

    data = extract_json(html)
    tree = data["tree"]
    print(f"drzewo: {len(tree)} marek, {len(json.dumps(data))/1024/1024:.1f} MB")

    makes, models, gens, engines, combos = [], [], [], [], []
    seen_gen, seen_eng = {}, {}

    for bi, (bslug, brand) in enumerate(sorted(tree.items())):
        is_truck = any(h in bslug for h in TRUCK_HINT)
        makes.append({"slug": bslug, "name": brand.get("label") or bslug,
                      "legacy_key": bslug, "sort": bi, "is_truck": is_truck})

        for mi, (mslug, model) in enumerate(sorted((brand.get("models") or {}).items())):
            models.append({"make_slug": bslug, "slug": mslug,
                           "name": model.get("label") or mslug,
                           "legacy_key": f"{bslug}/{mslug}", "sort": mi,
                           "vehicle_class": "ciezarowe" if is_truck else "osobowe"})

            # generacja::silnik -> zakres rocznikow
            span: dict[tuple[str, str], dict] = {}
            for ykey, ydata in (model.get("years") or {}).items():
                year = None
                m = re.search(r"\d{4}", str(ydata.get("label") or ykey))
                if m:
                    year = int(m.group(0))
                for e in (ydata.get("engines") or []):
                    if "::" not in e.get("value", ""):
                        continue
                    g, en = e["value"].split("::", 1)
                    rec = span.setdefault((g, en), {
                        "gen_label": e.get("gen_label") or g,
                        "engine_label": e.get("engine") or en,
                        "years": [], "year_keys": [],
                    })
                    if year:
                        rec["years"].append(year)
                    rec["year_keys"].append(str(ykey))

            for gi, ((g, en), rec) in enumerate(sorted(span.items())):
                gkey = (bslug, mslug, g)
                if gkey not in seen_gen:
                    seen_gen[gkey] = True
                    ys = [y for (gg, _), r in span.items() if gg == g for y in r["years"]]
                    gens.append({
                        "make_slug": bslug, "model_slug": mslug, "slug": g,
                        "name": rec["gen_label"],
                        "legacy_key": f"{bslug}/{mslug}/{g}",
                        "year_from": min(ys) if ys else None,
                        "year_to": max(ys) if ys else None,
                        "sort": gi,
                    })

                ekey = (bslug, mslug, g, en)
                if ekey in seen_eng:
                    continue
                seen_eng[ekey] = True

                lbl = rec["engine_label"]
                kw = re.search(r"(\d+)\s*kW", lbl, re.I)
                hp = re.search(r"(\d+)\s*KM", lbl, re.I)
                fuel = "diesel"
                low = lbl.lower()
                if any(k in low for k in ("tsi", "ecoboost", "tfsi", "benz", "gti", "mpi", "fsi")):
                    fuel = "benzyna"
                if "hybrid" in low or "hybryda" in low:
                    fuel = "hybryda"
                if "electric" in low or " ev" in low:
                    fuel = "elektryk"

                engines.append({
                    "make_slug": bslug, "model_slug": mslug, "gen_slug": g,
                    "slug": slugify(en), "name": lbl, "fuel": fuel,
                    "stock_kw": int(kw.group(1)) if kw else None,
                    "stock_hp": int(hp.group(1)) if hp else None,
                    "stock_nm": None,
                    "legacy_key": f"{bslug}/{mslug}/{g}/{en}",
                    "sort": gi,
                })
                # do pobrania wynikow potrzebny jest konkretny rocznik
                combos.append({"brand": bslug, "model": mslug, "gen": g, "engine": en,
                               "year": sorted(rec["year_keys"])[-1],
                               "key": f"{bslug}/{mslug}/{g}/{en}"})

    OUT.mkdir(parents=True, exist_ok=True)
    for name, rows in (("makes", makes), ("models", models),
                       ("generations", gens), ("engines", engines)):
        (OUT / f"{name}.json").write_text(json.dumps(rows, ensure_ascii=False), encoding="utf-8")
    (OUT / "_combos.json").write_text(json.dumps(combos, ensure_ascii=False), encoding="utf-8")

    print(f"marki {len(makes)}  modele {len(models)}  generacje {len(gens)}  silniki {len(engines)}")
    print(f"kombinacji do pobrania wynikow: {len(combos)}")
    bez_hp = sum(1 for e in engines if not e["stock_hp"])
    print(f"silnikow bez odczytanej mocy fabrycznej: {bez_hp}")


if __name__ == "__main__":
    main()
