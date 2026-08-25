#!/usr/bin/env python3
"""Pobiera wyniki (przyrosty mocy i momentu) dla kazdej kombinacji z drzewa V-techa.

Wtyczka VT Konfigurator zbiera wszystkie karty do dwoch workow i zostawia
ostatnia, przez co przy trzech poziomach PowerChip pokazuje tylko najdrozszy.
Tutaj zapisujemy KAZDY produkt osobno.

Wznawialny: odpowiedzi ida do cache na dysku, ponowne uruchomienie pomija gotowe.
"""
import gzip, io, json, pathlib, re, sys, time, hashlib, threading, urllib.parse, urllib.request
from concurrent.futures import ThreadPoolExecutor

UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36"
BASE = "https://sklep.vtech.pl/powerchip/"
CACHE = pathlib.Path("tools/scrape/raw-vtech"); CACHE.mkdir(parents=True, exist_ok=True)

# Strona wyniku wazy 4,66 MB, bo V-tech osadza w niej cale drzewo pojazdow.
# Prosimy o kompresje i zapisujemy w cache tylko fragment z kartami produktow —
# inaczej caly przebieg to 22 GB transferu i tyle samo miejsca na dysku.
FRAGMENT_MARK = "vt-fitment-product-name"


def fragment(html: str) -> str:
    """Wycina z pelnej strony tylko obszar z kartami produktow."""
    first = html.find(FRAGMENT_MARK)
    if first < 0:
        return "<!-- brak kart produktow -->"
    last = html.rfind(FRAGMENT_MARK)
    return html[max(0, first - 300):last + 4000]
OUT = pathlib.Path("content/catalog")

# kolejnosc ma znaczenie: "+ AI" musi byc sprawdzone przed "Premium"
PRODUCTS = [
    ("powerchip-premium-ai", ("powerchip premium + ai", "powerchip premium+ai")),
    ("powerchip-premium",    ("powerchip premium",)),
    ("powerchip-one",        ("powerchip one",)),
    ("chip",                 ("chip tuning",)),
]

_lock = threading.Lock()
_done = [0]
_total = 0


def classify(name: str) -> str | None:
    low = re.sub(r"\s+", " ", name).strip().lower()
    for code, needles in PRODUCTS:
        if any(n in low for n in needles):
            return code
    return None


def parse(html: str) -> list[dict]:
    """Wyciaga wszystkie karty produktow z ich przyrostami."""
    out = []
    # kazda karta zaczyna sie nazwa produktu; tniemy dokument na tych granicach
    marks = [(m.start(), m.group(1).strip())
             for m in re.finditer(r'vt-fitment-product-name[^>]*>\s*([^<]{1,80})', html)]
    for idx, (pos, name) in enumerate(marks):
        code = classify(name)
        if not code:
            continue
        end = marks[idx + 1][0] if idx + 1 < len(marks) else len(html)
        chunk = html[pos:end]
        hp = re.search(r'vt-fitment-gain-value[^"]*\bhp\b[^"]*"[^>]*data-count-to="(\d+)"', chunk)
        nm = re.search(r'vt-fitment-gain-value[^"]*\bnm\b[^"]*"[^>]*data-count-to="(\d+)"', chunk)
        if not hp and not nm:
            continue
        rec = {"service_code": code, "label": name,
               "gain_hp": int(hp.group(1)) if hp else 0,
               "gain_nm": int(nm.group(1)) if nm else 0}
        chart = re.search(r'data-vt-modal-src="([^"]+)"', chunk)
        if chart:
            rec["chart_url"] = chart.group(1)
        out.append(rec)
    return out


def fetch(combo: dict) -> tuple[dict, list[dict] | None]:
    key = combo["key"]
    f = CACHE / (hashlib.sha1(key.encode()).hexdigest()[:20] + ".html")

    if f.exists() and f.stat().st_size > 200:
        html = f.read_text(encoding="utf-8", errors="replace")
    else:
        url = BASE + "/".join(urllib.parse.quote(combo[k], safe="") for k in
                              ("brand", "model", "gen", "engine")) + \
              "/?vehicle_year=" + urllib.parse.quote(str(combo["year"]))
        for attempt in range(3):
            try:
                req = urllib.request.Request(url, headers={
                    "User-Agent": UA,
                    "Accept-Encoding": "gzip",
                })
                with urllib.request.urlopen(req, timeout=60) as r:
                    raw = r.read()
                    if r.headers.get("Content-Encoding") == "gzip":
                        raw = gzip.decompress(raw)
                html = fragment(raw.decode("utf-8", "replace"))
                f.write_text(html, encoding="utf-8")
                time.sleep(0.3)
                break
            except Exception:
                if attempt == 2:
                    return combo, None
                time.sleep(2 + attempt * 3)

    with _lock:
        _done[0] += 1
        if _done[0] % 200 == 0:
            print(f"  {_done[0]}/{_total}", flush=True)

    return combo, parse(html)


def main() -> None:
    global _total
    combos = json.loads((OUT / "_combos.json").read_text(encoding="utf-8"))
    limit = int(sys.argv[1]) if len(sys.argv) > 1 else 0
    if limit:
        combos = combos[:limit]
    _total = len(combos)
    print(f"kombinacji: {_total}")

    with ThreadPoolExecutor(5) as ex:
        results = list(ex.map(fetch, combos))

    gains, missing, by_code = [], [], {}
    for combo, rows in results:
        if rows is None:
            missing.append(combo["key"]); continue
        if not rows:
            missing.append(combo["key"]); continue
        for r in rows:
            by_code[r["service_code"]] = by_code.get(r["service_code"], 0) + 1
            gains.append({"engine_legacy_key": combo["key"], **r})

    (OUT / "gains.json").write_text(json.dumps(gains, ensure_ascii=False), encoding="utf-8")
    (OUT / "_gaps.json").write_text(json.dumps(missing, ensure_ascii=False, indent=1), encoding="utf-8")

    man = {"source": "sklep.vtech.pl", "scraped_at": time.strftime("%Y-%m-%dT%H:%M:%S"),
           "counts": {"gains": len(gains), "bez_wynikow": len(missing)},
           "per_product": by_code}
    (OUT / "MANIFEST.json").write_text(json.dumps(man, ensure_ascii=False, indent=1), encoding="utf-8")

    print(f"\nwariantow uslug: {len(gains)}   kombinacji bez wynikow: {len(missing)}")
    for k, v in sorted(by_code.items(), key=lambda x: -x[1]):
        print(f"  {k:<24} {v}")


if __name__ == "__main__":
    main()
