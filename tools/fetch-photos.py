#!/usr/bin/env python3
"""Pobiera zdjęcia z Unsplash (Unsplash License, images.unsplash.com — nie Unsplash+)
i przygotowuje warianty WebP do assets/img/.

Dwa rodzaje kadrów:
  foto-*  4:3, 1200 px + 600 px  — „duże zdjęcie" w kolumnie obok tekstu
  usp-*   16:9, 900 px + 450 px  — zdjęcie w kafelku przewag na stronie głównej

Każde zdjęcie ma wpis w assets/img/CREDITS.md. Skrypt jest po to, żeby dało się
odtworzyć pliki z tych samych źródeł, a nie po to, żeby uruchamiać go na serwerze.

    python3 tools/fetch-photos.py            # brakujące
    python3 tools/fetch-photos.py --force    # wszystkie od nowa
"""
import pathlib, subprocess, sys
from PIL import Image, ImageEnhance

OUT = pathlib.Path('assets/img')
CACHE = pathlib.Path('tools/scrape/raw-unsplash'); CACHE.mkdir(parents=True, exist_ok=True)

# (klucz, id zdjęcia w Unsplash, rodzaj kadru, [przesunięcie środka kadru w pionie, 0–1])
JOBS = [
    ('kamper',       'eUkjMvbeLug', 'foto', .5),
    ('dpf',          '-ShTRctVXlk', 'foto', .5),
    ('wallbox',      'Lv-vzrhjybE', 'foto', .5),
    ('hamownia',     'E5uUCbeBTfU', 'foto', .55),
    ('volvo',        'COYjMnrXHKM', 'foto', .6),
    ('ecu',          'aJlJAwocqwk', 'foto', .5),
    ('onas',         '8t6tk7LYLrE', 'foto', .5),
    ('ev',           'N2Td7KpIvYc', 'foto', .5),
    ('usp-unlock',   'dSosKR6g-W8', 'usp',  .5),
    ('usp-leasing',  'iTHT1gXJuS8', 'usp',  .5),
    ('usp-floty',    '3vlGNkDep4E', 'usp',  .5),
    ('usp-kamper',   'muEU34zZlzs', 'usp',  .55),
    ('usp-hamownia', 'E5uUCbeBTfU', 'usp',  .55),
]
KINDS = {'foto': ((4, 3), 1200, 600), 'usp': ((16, 9), 900, 450)}
force = '--force' in sys.argv

def raw_url(pid):
    return f'https://unsplash.com/photos/{pid}/download?force=true&w=2400'

for key, pid, kind, fy in JOBS:
    (ar, big, small), src = KINDS[kind], CACHE / f'{pid}.jpg'
    if not src.exists():
        subprocess.run(['curl', '-sL', '-m', '120', '-o', str(src), raw_url(pid)], check=True)
    im = Image.open(src).convert('RGB')
    w, h = im.size
    tw, th = (round(h * ar[0] / ar[1]), h) if w / h > ar[0] / ar[1] else (w, round(w * ar[1] / ar[0]))
    tw, th = min(tw, w), min(th, h)
    x = (w - tw) // 2
    y = max(0, min(h - th, round((h - th) * fy)))
    im = im.crop((x, y, x + tw, y + th))
    # Stonowanie do palety serwisu: zdjęcie ma zostać zdjęciem, ale nie krzyczeć
    # na ciemnym tle. Pasy pas-* są wygaszone mocniej (28%), tu zostaje 72%.
    im = ImageEnhance.Color(im).enhance(.72)
    im = ImageEnhance.Brightness(im).enhance(.9)
    for width, suffix in ((big, ''), (small, '-sm')):
        dst = OUT / f'{"foto" if kind == "foto" else ""}{"-" if kind == "foto" else ""}{key}{suffix}.webp'
        if dst.exists() and not force:
            continue
        r = im.resize((width, round(im.height * width / im.width)), Image.LANCZOS)
        r.save(dst, 'WEBP', quality=78, method=6)
        print(f'{dst.name:<26} {r.width}x{r.height}  {dst.stat().st_size/1024:5.0f} KB')
