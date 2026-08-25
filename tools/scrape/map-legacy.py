#!/usr/bin/env python3
"""Mapuje stare klucze ?auto= (katalog ze starej strony Vitesse) na nowe sciezki
zbudowane na danych V-techa. Bez tego podmiana katalogu zerwalaby przekierowania 301.

Dopasowanie idzie poziomami. Silniki lacza sie po (pojemnosc, rodzina, kW),
bo V-tech dopisuje do nazwy moc w KM i sufiks numeryczny, ktorych stara strona nie miala.
"""
import json, pathlib, re, sys

OLD = pathlib.Path("content/catalog/legacy")
NEW = pathlib.Path("content/catalog")
OUT = pathlib.Path("content/redirects")


def load(d, name):
    return json.loads((d / f"{name}.json").read_text(encoding="utf-8"))


def norm(s: str) -> str:
    tr = str.maketrans("ąćęłńóśźżĄĆĘŁŃÓŚŹŻ", "acelnoszzACELNOSZZ")
    return re.sub(r"[^a-z0-9]+", "", s.translate(tr).lower())


def engine_sig(label: str):
    """(pojemnosc, rodzina, kW) — czesc wspolna obu zrodel."""
    disp = re.search(r"(\d\.\d)", label)
    kw = re.search(r"(\d+)\s*kW", label, re.I)
    fam = re.sub(r"[\d\.\s]|kw|km", "", label.lower())
    fam = re.sub(r"[^a-z]", "", fam)[:8]
    return (disp.group(1) if disp else None, fam or None, int(kw.group(1)) if kw else None)


def main():
    old_makes = load(OLD, "makes");  new_makes = load(NEW, "makes")
    old_models = load(OLD, "models"); new_models = load(NEW, "models")
    old_gens = load(OLD, "generations"); new_gens = load(NEW, "generations")
    old_engs = load(OLD, "engines");  new_engs = load(NEW, "engines")

    mp, stat = {}, {"marka": [0, 0], "model": [0, 0], "generacja": [0, 0], "silnik": [0, 0]}

    # marki
    nm_by = {norm(m["name"]): m["slug"] for m in new_makes}
    nm_by.update({norm(m["slug"]): m["slug"] for m in new_makes})
    make_map = {}
    for m in old_makes:
        stat["marka"][1] += 1
        hit = nm_by.get(norm(m["name"])) or nm_by.get(norm(m["slug"]))
        if hit:
            make_map[m["slug"]] = hit
            mp[m["legacy_key"]] = hit
            stat["marka"][0] += 1

    # modele
    new_models_by = {}
    for m in new_models:
        new_models_by.setdefault(m["make_slug"], {})[norm(m["name"])] = m["slug"]
        new_models_by[m["make_slug"]][norm(m["slug"])] = m["slug"]
    model_map = {}
    for m in old_models:
        stat["model"][1] += 1
        nmk = make_map.get(m["make_slug"])
        if not nmk:
            continue
        hit = new_models_by.get(nmk, {}).get(norm(m["name"])) or new_models_by.get(nmk, {}).get(norm(m["slug"]))
        if hit:
            model_map[(m["make_slug"], m["slug"])] = (nmk, hit)
            if m.get("legacy_key"):
                mp[m["legacy_key"]] = f"{nmk}/{hit}"
            stat["model"][0] += 1

    # generacje
    new_gens_by = {}
    for g in new_gens:
        new_gens_by.setdefault((g["make_slug"], g["model_slug"]), {})[norm(g["name"])] = g["slug"]
        new_gens_by[(g["make_slug"], g["model_slug"])][norm(g["slug"])] = g["slug"]
    gen_map = {}
    for g in old_gens:
        stat["generacja"][1] += 1
        tgt = model_map.get((g["make_slug"], g["model_slug"]))
        if not tgt:
            continue
        hit = new_gens_by.get(tgt, {}).get(norm(g["name"])) or new_gens_by.get(tgt, {}).get(norm(g["slug"]))
        if hit:
            gen_map[(g["make_slug"], g["model_slug"], g["slug"])] = (*tgt, hit)
            if g.get("legacy_key"):
                mp[g["legacy_key"]] = f"{tgt[0]}/{tgt[1]}/{hit}"
            stat["generacja"][0] += 1

    # silniki — po sygnaturze
    new_engs_by = {}
    for e in new_engs:
        k = (e["make_slug"], e["model_slug"], e["gen_slug"])
        new_engs_by.setdefault(k, []).append(e)
    for e in old_engs:
        stat["silnik"][1] += 1
        tgt = gen_map.get((e["make_slug"], e["model_slug"], e["gen_slug"]))
        if not tgt or not e.get("legacy_key"):
            continue
        sig = engine_sig(e["name"])
        best = None
        for cand in new_engs_by.get(tgt, []):
            cs = engine_sig(cand["name"])
            if sig[0] and cs[0] == sig[0] and sig[2] and cs[2] == sig[2]:
                best = cand; break
            if sig[0] and cs[0] == sig[0] and sig[1] and cs[1] and sig[1][:4] == cs[1][:4]:
                best = best or cand
        if best:
            mp[e["legacy_key"]] = f"{tgt[0]}/{tgt[1]}/{tgt[2]}/{best['slug']}"
            stat["silnik"][0] += 1

    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "legacy-catalog.json").write_text(json.dumps(mp, ensure_ascii=False), encoding="utf-8")

    print("dopasowanie starych kluczy na nowe sciezki:")
    for k, (ok, tot) in stat.items():
        print(f"  {k:<12} {ok:>5} / {tot:<5}  {ok/tot*100 if tot else 0:5.1f}%")
    print(f"\nwpisow w mapie: {len(mp)}")
    print("przyklady:")
    for k in list(mp)[:3] + [k for k in mp if k.count("_") >= 3][:3]:
        print(f"  {k}  ->  /chiptuning/{mp[k]}/")


if __name__ == "__main__":
    main()
