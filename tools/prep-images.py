#!/usr/bin/env python3
"""Skaluje i konwertuje zdjecia ze starego serwisu do WebP, zwraca base64
do osadzenia w samodzielnych makietach."""
import base64, json, pathlib
from PIL import Image

SRC = pathlib.Path("tools/scrape/mirror/vitesse.auto.pl/images")
OUT = pathlib.Path("/tmp/claude-1000/-home-damian-Workspace-Vitesse/"
                   "bdf3180e-44cb-47e5-a8c4-6c4d5405a949/scratchpad/design")
OUT.mkdir(parents=True, exist_ok=True)

# (plik zrodlowy, klucz, docelowa szerokosc, jakosc)
JOBS = [
    ("slide02.jpg",            "hero_night",   1600, 68),
    ("osobowe_hamownia.jpg",   "dyno_car",      900, 72),
    ("ciezarowe_hamownia.jpg", "dyno_truck",    900, 72),
    ("chip_tuning_graph.jpg",  "ecu_map",       900, 70),
    ("powerboxv2_1.jpg",       "powerbox",      700, 72),
]

out, total = {}, 0
for src, key, width, q in JOBS:
    p = SRC / src
    if not p.exists():
        print(f"  BRAK: {src}")
        continue
    im = Image.open(p).convert("RGB")
    if im.width > width:
        im = im.resize((width, round(im.height * width / im.width)), Image.LANCZOS)
    tmp = OUT / f"{key}.webp"
    im.save(tmp, "WEBP", quality=q, method=6)
    data = tmp.read_bytes()
    out[key] = "data:image/webp;base64," + base64.b64encode(data).decode()
    total += len(data)
    print(f"  {key:<12} {im.width}x{im.height}  {len(data)/1024:6.1f} KB")

(OUT / "images.json").write_text(json.dumps(out))
print(f"\nrazem {total/1024:.0f} KB surowo, ~{total*1.34/1024:.0f} KB w base64")
