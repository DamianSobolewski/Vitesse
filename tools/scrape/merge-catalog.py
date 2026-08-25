#!/usr/bin/env python3
"""Scala katalog V-techa z tym, czego V-tech nie obejmuje.

Konfigurator V-techa dotyczy pojazdow drogowych. Stary katalog Vitesse mial
dodatkowo MAN-a oraz maszyny rolnicze (Fendt, Case) — a "Chip tuning ciagnikow
i maszyn" jest w ofercie serwisu. Dokladamy wiec te marki, ktorych u V-techa nie ma.

Nie scalamy marek wystepujacych w obu zrodlach — unikamy sprzecznych wartosci
dla tego samego silnika. V-tech jest zawsze zrodlem nadrzednym.

Przyrosty ujednolicamy: V-tech podaje delty (+KM/+Nm), stary katalog wartosci
bezwzgledne. Trzymamy jedno i drugie tam, gdzie da sie policzyc.
"""
import json, pathlib, re

N = pathlib.Path("content/catalog")
L = N / "legacy"
load = lambda d, n: json.loads((d / f"{n}.json").read_text(encoding="utf-8"))


def norm(s: str) -> str:
    tr = str.maketrans("ąćęłńóśźżĄĆĘŁŃÓŚŹŻ", "acelnoszzACELNOSZZ")
    return re.sub(r"[^a-z0-9]+", "", s.translate(tr).lower())


def main() -> None:
    makes, models, gens, engines = (load(N, n) for n in ("makes", "models", "generations", "engines"))
    gains = load(N, "gains")

    have = {norm(m["name"]) for m in makes} | {norm(m["slug"]) for m in makes}
    l_makes = load(L, "makes")
    l_models, l_gens, l_engs = (load(L, n) for n in ("models", "generations", "engines"))
    l_gains = load(L, "gains")

    # tylko marki, ktorych V-tech nie ma i ktore maja jakiekolwiek dane
    eng_by_make = {}
    for e in l_engs:
        eng_by_make[e["make_slug"]] = eng_by_make.get(e["make_slug"], 0) + 1

    dodac = [m for m in l_makes
             if norm(m["name"]) not in have and norm(m["slug"]) not in have
             and eng_by_make.get(m["slug"], 0) > 0]
    slugs = {m["slug"] for m in dodac}
    print("marki dokladane ze starego katalogu:",
          ", ".join(f'{m["name"]} ({eng_by_make[m["slug"]]})' for m in dodac) or "brak")

    base = len(makes)
    for i, m in enumerate(dodac):
        makes.append({**m, "sort": base + i})
    models += [m for m in l_models if m["make_slug"] in slugs]
    gens   += [g for g in l_gens if g["make_slug"] in slugs]

    keep_eng = [e for e in l_engs if e["make_slug"] in slugs]
    engines += keep_eng
    keep_keys = {e["legacy_key"] for e in keep_eng}
    stock = {e["legacy_key"]: e for e in keep_eng}

    # stare przyrosty: wartosci bezwzgledne -> dokladamy delty
    dodane = 0
    for g in l_gains:
        k = g.get("engine_legacy_key")
        if k not in keep_keys:
            continue
        e = stock[k]
        rec = {"engine_legacy_key": k, "service_code": g["service_code"],
               "label": {"chip": "Chip Tuning", "powerbox": "PowerBox"}.get(g["service_code"], g["service_code"]),
               "tuned_hp": g.get("tuned_hp"), "tuned_nm": g.get("tuned_nm")}
        if g.get("tuned_hp") and e.get("stock_hp"):
            rec["gain_hp"] = max(0, g["tuned_hp"] - e["stock_hp"])
        if g.get("tuned_nm") and e.get("stock_nm"):
            rec["gain_nm"] = max(0, g["tuned_nm"] - e["stock_nm"])
        gains.append(rec)
        dodane += 1

    for name, rows in (("makes", makes), ("models", models),
                       ("generations", gens), ("engines", engines), ("gains", gains)):
        (N / f"{name}.json").write_text(json.dumps(rows, ensure_ascii=False), encoding="utf-8")

    import time
    man = json.loads((N / "MANIFEST.json").read_text(encoding="utf-8")) if (N / "MANIFEST.json").exists() else {}
    man.update({
        "source": "sklep.vtech.pl + katalog Vitesse (marki spoza konfiguratora)",
        "merged_at": time.strftime("%Y-%m-%dT%H:%M:%S"),
        "counts": {"makes": len(makes), "models": len(models),
                   "generations": len(gens), "engines": len(engines), "gains": len(gains)},
        "z_katalogu_vitesse": sorted(m["name"] for m in dodac),
    })
    (N / "MANIFEST.json").write_text(json.dumps(man, ensure_ascii=False, indent=1), encoding="utf-8")

    print(f"\npo scaleniu: marki {len(makes)}  modele {len(models)}  "
          f"generacje {len(gens)}  silniki {len(engines)}  warianty uslug {len(gains)}")
    print(f"dolozonych wariantow ze starego katalogu: {dodane}")


if __name__ == "__main__":
    main()
