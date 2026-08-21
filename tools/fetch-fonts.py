#!/usr/bin/env python3
"""Pobiera woff2 z Google Fonts do assets/fonts/ (self-hosting, RODO)
i generuje CSS z base64 na potrzeby samodzielnych makiet."""
import re, sys, base64, pathlib, urllib.request

UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/120.0 Safari/537.36")

FAMILIES = {
    "Archivo":       "family=Archivo:ital,wght@0,600;0,700;1,800",
    "IBM+Plex+Sans": "family=IBM+Plex+Sans:wght@400;600",
    "IBM+Plex+Mono": "family=IBM+Plex+Mono:wght@500",
    "Outfit":        "family=Outfit:wght@400;600",
}
KEEP = {"latin", "latin-ext"}          # o kreskowane jest w latin, reszta polskich w latin-ext

def get(url, binary=False):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=45) as r:
        return r.read() if binary else r.read().decode()

out_dir = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else "assets/fonts")
out_dir.mkdir(parents=True, exist_ok=True)

blocks, embedded = [], []
for fam, q in FAMILIES.items():
    css = get(f"https://fonts.googleapis.com/css2?{q}&display=swap")
    subset = None
    for chunk in re.split(r"(/\*\s*[a-z0-9-]+\s*\*/)", css):
        m = re.fullmatch(r"/\*\s*([a-z0-9-]+)\s*\*/", chunk.strip())
        if m:
            subset = m.group(1)
            continue
        if subset not in KEEP or "@font-face" not in chunk:
            continue
        for face in re.findall(r"@font-face\s*\{[^}]+\}", chunk):
            url = re.search(r"url\((https://[^)]+\.woff2)\)", face).group(1)
            weight = re.search(r"font-weight:\s*(\d+)", face).group(1)
            style = re.search(r"font-style:\s*(\w+)", face).group(1)
            family = re.search(r"font-family:\s*'([^']+)'", face).group(1)

            data = get(url, binary=True)
            name = f"{family.replace(' ', '')}-{weight}{'i' if style == 'italic' else ''}-{subset}.woff2"
            (out_dir / name).write_bytes(data)

            b64 = base64.b64encode(data).decode()
            rng = re.search(r"unicode-range:\s*([^;]+);", face).group(1)
            blocks.append(
                f"@font-face{{font-family:'{family}';font-style:{style};"
                f"font-weight:{weight};font-display:swap;"
                f"src:url(data:font/woff2;base64,{b64}) format('woff2');"
                f"unicode-range:{rng};}}"
            )
            embedded.append((name, len(data)))

pathlib.Path("/tmp/claude-1000/-home-damian-Workspace-Vitesse/"
             "bdf3180e-44cb-47e5-a8c4-6c4d5405a949/scratchpad/design/fonts.css"
             ).write_text("\n".join(blocks))

total = sum(s for _, s in embedded)
for n, s in sorted(embedded):
    print(f"  {n:<44} {s/1024:6.1f} KB")
print(f"\n{len(embedded)} plikow, {total/1024:.0f} KB surowo, ~{total*1.34/1024:.0f} KB w base64")
