#!/usr/bin/env python3
"""Scraper katalogu osiagow vitesse.auto.pl.

Przechodzi 4 poziomy: marka -> model -> generacja -> tabela silnikow.
Wznawialny (cache HTML na dysku), grzeczny (3 rownolegle + odstep).
Wynik: content/catalog/*.json zgodnie ze schematem z vts-schema.php.
"""
import re, os, sys, json, time, html, hashlib, pathlib, threading
import urllib.parse, urllib.request
from concurrent.futures import ThreadPoolExecutor

BASE = "https://vitesse.auto.pl/chiptuning_lodz.php"
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36"
CACHE = pathlib.Path("tools/scrape/raw"); CACHE.mkdir(parents=True, exist_ok=True)
OUT = pathlib.Path("content/catalog"); OUT.mkdir(parents=True, exist_ok=True)

FUEL = {"benzynowe": "benzyna", "diesel": "diesel", "hybrydowe": "hybryda",
        "elektryczne": "elektryk", "gaz": "lpg"}
TRUCKS = {"daf", "iveco", "man", "scania", "renault-trucks", "volvo-trucks", "mercedes-trucks"}

_lock = threading.Lock()
_count = [0]


def slugify(s: str) -> str:
    tr = str.maketrans("ąćęłńóśźżĄĆĘŁŃÓŚŹŻ", "acelnoszzACELNOSZZ")
    s = s.translate(tr).lower()
    s = re.sub(r"[^a-z0-9]+", "-", s)
    return re.sub(r"-{2,}", "-", s).strip("-") or "x"


def fetch(key: str) -> str:
    """Pobiera strone dla wartosci ?auto=<key>, z cache na dysku."""
    name = hashlib.sha1(key.encode()).hexdigest()[:20] + ".html"
    f = CACHE / name
    if f.exists() and f.stat().st_size > 500:
        return f.read_text(encoding="utf-8", errors="replace")

    url = BASE + ("?auto=" + urllib.parse.quote(key) if key else "")
    for attempt in range(3):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(req, timeout=40) as r:
                doc = r.read().decode("utf-8", "replace")
            f.write_text(doc, encoding="utf-8")
            with _lock:
                _count[0] += 1
                if _count[0] % 25 == 0:
                    print(f"  pobrano {_count[0]} stron", flush=True)
            time.sleep(0.35)
            return doc
        except Exception as e:
            if attempt == 2:
                print(f"  BLAD {key}: {e}", flush=True)
                return ""
            time.sleep(2 + attempt * 3)
    return ""


def children(doc: str, parent: str) -> list:
    """Zwraca klucze bezposrednich dzieci danego poziomu."""
    out, seen = [], set()
    pref = parent + "_" if parent else ""
    for m in re.finditer(r'href="chiptuning_lodz\.php\?auto=([^"]+)"', doc):
        key = html.unescape(m.group(1)).replace("\xa0", " ")
        key = urllib.parse.unquote(key)
        if not key.startswith(pref) or key == parent:
            continue
        rest = key[len(pref):]
        if not rest or "_" in rest:      # tylko bezposrednie dzieci
            continue
        if key not in seen:
            seen.add(key); out.append(key)
    return out


def parse_engines(doc: str) -> list:
    """Wyciaga tabele silnikow ze strony generacji."""
    engines = []
    for tbl in re.findall(r'<table class="alt">.*?</table>', doc, re.S):
        head = re.sub(r"<[^>]+>", " ", tbl.split("</thead>")[0] if "</thead>" in tbl else tbl)
        head = html.unescape(head).lower()
        fuel = next((v for k, v in FUEL.items() if k in head), "nieznane")

        body = tbl.split("<tbody>")[-1]
        for row in re.findall(r"<tr>(.*?)</tr>", body, re.S):
            cells = re.findall(r"<td[^>]*>(.*?)</td>", row, re.S)
            if len(cells) < 3:
                continue
            label = html.unescape(re.sub(r"<[^>]+>", " ", cells[0])).replace("\xa0", " ")
            label = re.sub(r"\s+", " ", label).strip()
            if not label:
                continue
            link = re.search(r'href="[^"]*auto=([^"]+)"', cells[0])
            lk = urllib.parse.unquote(html.unescape(link.group(1)).replace("\xa0", " ")) if link else None

            nums = []
            for c in cells[1:]:
                t = html.unescape(re.sub(r"<[^>]+>", " ", c))
                mm = re.search(r"(\d+)", t)
                nums.append(int(mm.group(1)) if mm else None)

            kw = re.search(r"(\d+)\s*kW", label, re.I)
            engines.append({
                "name": label, "legacy_key": lk, "fuel": fuel,
                "stock_kw": int(kw.group(1)) if kw else None,
                "stock_hp": nums[0] if len(nums) > 0 else None,
                "stock_nm": nums[1] if len(nums) > 1 else None,
                "chip_hp": nums[2] if len(nums) > 2 else None,
                "chip_nm": nums[3] if len(nums) > 3 else None,
                "box_hp": nums[4] if len(nums) > 4 else None,
                "box_nm": nums[5] if len(nums) > 5 else None,
            })
    return engines


YEARS = re.compile(r"(\d{4})\s*[-–]\s*(\d{4}|)")


def main():
    only = set(a.lower() for a in sys.argv[1:]) or None

    print("1/4  lista marek", flush=True)
    root = fetch("")
    make_keys = [k for k in children(root, "")]
    if only:
        make_keys = [k for k in make_keys if k.lower() in only]
    print(f"     {len(make_keys)} marek", flush=True)

    makes, models, gens, engines, gains = [], [], [], [], []

    print("2/4  modele", flush=True)
    with ThreadPoolExecutor(3) as ex:
        model_lists = list(ex.map(lambda k: (k, children(fetch(k), k)), make_keys))

    gen_jobs = []
    for sort, (mk, mdl_keys) in enumerate(model_lists):
        mslug = slugify(mk)
        makes.append({"slug": mslug, "name": mk.replace("_", " "), "legacy_key": mk,
                      "sort": sort, "is_truck": mslug in TRUCKS})
        for i, md in enumerate(mdl_keys):
            models.append({"make_slug": mslug, "slug": slugify(md[len(mk) + 1:]),
                           "name": md[len(mk) + 1:], "legacy_key": md, "sort": i,
                           "vehicle_class": "ciezarowe" if mslug in TRUCKS else "osobowe"})
            gen_jobs.append((mslug, slugify(md[len(mk) + 1:]), md))
    print(f"     {len(models)} modeli", flush=True)

    print("3/4  generacje", flush=True)
    with ThreadPoolExecutor(3) as ex:
        gen_lists = list(ex.map(lambda j: (j, children(fetch(j[2]), j[2])), gen_jobs))

    eng_jobs = []
    for (mkslug, mdslug, mdkey), gkeys in gen_lists:
        for i, gk in enumerate(gkeys):
            gname = gk[len(mdkey) + 1:]
            gslug = slugify(gname)
            ym = YEARS.search(gname)
            gens.append({"make_slug": mkslug, "model_slug": mdslug, "slug": gslug,
                         "name": gname, "legacy_key": gk, "sort": i,
                         "year_from": int(ym.group(1)) if ym else None,
                         "year_to": int(ym.group(2)) if ym and ym.group(2) else None})
            eng_jobs.append((mkslug, mdslug, gslug, gk))
    print(f"     {len(gens)} generacji", flush=True)

    print("4/4  silniki", flush=True)
    with ThreadPoolExecutor(3) as ex:
        eng_lists = list(ex.map(lambda j: (j, parse_engines(fetch(j[3]))), eng_jobs))

    for (mkslug, mdslug, gslug, gkey), rows in eng_lists:
        for i, e in enumerate(rows):
            if not e["stock_hp"]:
                continue
            eslug = slugify(e["name"])
            lk = e["legacy_key"] or f"{gkey}_{e['name']}"
            engines.append({"make_slug": mkslug, "model_slug": mdslug, "gen_slug": gslug,
                            "slug": eslug, "name": e["name"], "fuel": e["fuel"],
                            "stock_kw": e["stock_kw"], "stock_hp": e["stock_hp"],
                            "stock_nm": e["stock_nm"], "legacy_key": lk, "sort": i})
            if e["chip_hp"]:
                gains.append({"engine_legacy_key": lk, "service_code": "chip",
                              "tuned_hp": e["chip_hp"], "tuned_nm": e["chip_nm"]})
            if e["box_hp"]:
                gains.append({"engine_legacy_key": lk, "service_code": "powerbox",
                              "tuned_hp": e["box_hp"], "tuned_nm": e["box_nm"]})

    data = {"makes": makes, "models": models, "generations": gens,
            "engines": engines, "gains": gains}
    for k, v in data.items():
        (OUT / f"{k}.json").write_text(json.dumps(v, ensure_ascii=False), encoding="utf-8")

    manifest = {"scraped_at": time.strftime("%Y-%m-%dT%H:%M:%S"),
                "counts": {k: len(v) for k, v in data.items()},
                "pages_fetched": _count[0]}
    (OUT / "MANIFEST.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=1), encoding="utf-8")
    print("\nGOTOWE:", json.dumps(manifest["counts"], ensure_ascii=False), flush=True)


if __name__ == "__main__":
    main()
