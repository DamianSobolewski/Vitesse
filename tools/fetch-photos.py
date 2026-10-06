#!/usr/bin/env python3
"""Przygotowuje warianty WebP do assets/img/ ze zdjęć z Unsplash (Unsplash License,
images.unsplash.com — nie Unsplash+) oraz ze zdjęć dostarczonych przez klienta.

Dwa rodzaje kadrów:
  foto-*  4:3, 1200 px + 600 px  — „duże zdjęcie" w połowie układu dwudzielnego
  usp-*   16:9, 900 px + 450 px  — zdjęcie w kafelku przewag na stronie głównej

Źródło zadania to id zdjęcia w Unsplash (pobierane do tools/scrape/raw-unsplash/)
albo 'client:<nazwa pliku>' z tools/scrape/raw-client/ — tam leżą oryginały od
klienta, poza repozytorium (.gitignore). Każde zdjęcie ma wpis w
assets/img/CREDITS.md. Skrypt jest po to, żeby dało się odtworzyć pliki z tych
samych źródeł, a nie po to, żeby uruchamiać go na serwerze.

Od X 2026 bez stonowania: wcześniej nasycenie 72 % i jasność 90 % wtapiały
zdjęcia w ciemne tło, a klient chciał zdjęć wyraźnych. Z jasnymi sekcjami
pełne kolory siedzą dobrze.

    python3 tools/fetch-photos.py            # brakujące
    python3 tools/fetch-photos.py --force    # wszystkie od nowa
"""
import pathlib, subprocess, sys
from PIL import Image, ImageEnhance, ImageOps

OUT    = pathlib.Path('assets/img')
CACHE  = pathlib.Path('tools/scrape/raw-unsplash'); CACHE.mkdir(parents=True, exist_ok=True)
CLIENT = pathlib.Path('tools/scrape/raw-client')

SAT, BRI, QUALITY = 1.0, 1.0, 80

# (klucz, źródło, rodzaj kadru, przesunięcie środka kadru w pionie 0–1)
JOBS = [
    ('kamper',       'eUkjMvbeLug', 'foto', .5),
    ('dpf',          '-ShTRctVXlk', 'foto', .5),
    ('wallbox',      'Lv-vzrhjybE', 'foto', .5),
    ('hamownia',     'E5uUCbeBTfU', 'foto', .55),
    ('volvo',        'COYjMnrXHKM', 'foto', .6),
    ('ecu',          'aJlJAwocqwk', 'foto', .5),
    ('onas',         '8t6tk7LYLrE', 'foto', .5),
    ('ev',           'N2Td7KpIvYc', 'foto', .5),
    ('leasing',      'iTHT1gXJuS8', 'foto', .5),
    ('floty',        '3vlGNkDep4E', 'foto', .5),
    ('unlock',       'dSosKR6g-W8', 'foto', .5),
    ('usp-unlock',   'dSosKR6g-W8', 'usp',  .5),
    ('usp-leasing',  'iTHT1gXJuS8', 'usp',  .5),
    ('usp-floty',    '3vlGNkDep4E', 'usp',  .5),
    ('usp-kamper',   'muEU34zZlzs', 'usp',  .55),
    ('usp-hamownia', 'E5uUCbeBTfU', 'usp',  .55),
    # zdjęcia od klienta (X 2026)
    ('startstop',    'client:Start@Stop.jpg',                     'foto', .5),
    ('olej',         'client:ciśnienie oleju-2.webp',             'foto', .5),
    ('temperatura',  'client:Temperatura pracy silnika.jpg',      'foto', .5),
    ('dolot',        'client:Czyszczenie układów dolotowych.jpg', 'foto', .45),
    ('skrzynia',     'client:Programowanie skrzyń biegów.jpg',    'foto', .55),
    ('klapy',        'client:Klapy wirowe.jpg',                   'foto', .5),
    ('sonda',        'client:Druga sonda Lambda i pompa powietrza dodatkowego (Subaru, SAI).jpg', 'foto', .5),
    ('sai',          'client:Pompa powietrza dodatkowego SAI.jpeg', 'foto', .5),
]
KINDS = {'foto': ((4, 3), 1200, 600), 'usp': ((16, 9), 900, 450)}
force = '--force' in sys.argv

def raw_url(pid):
    return f'https://unsplash.com/photos/{pid}/download?force=true&w=2400'

def source(pid: str) -> pathlib.Path:
    if pid.startswith('client:'):
        src = CLIENT / pid[len('client:'):]
        if not src.exists():
            sys.exit(f'brak pliku klienta: {src}')
        return src
    src = CACHE / f'{pid}.jpg'
    if not src.exists():
        subprocess.run(['curl', '-sL', '-m', '120', '-o', str(src), raw_url(pid)], check=True)
    return src

for key, pid, kind, fy in JOBS:
    ar, big, small = KINDS[kind]
    name = f'foto-{key}' if kind == 'foto' else key
    targets = [(big, OUT / f'{name}.webp'), (small, OUT / f'{name}-sm.webp')]
    if not force and all(dst.exists() for _, dst in targets):
        continue

    im = Image.open(source(pid))
    if im.format == 'JPEG':
        im.draft('RGB', (2400, 2400))     # 7000 px dekoduje się w połowie skali — bez straty przy 1200 px
    im = ImageOps.exif_transpose(im).convert('RGB')   # zdjęcia z telefonu niosą orientację w EXIF
    w, h = im.size
    tw, th = (round(h * ar[0] / ar[1]), h) if w / h > ar[0] / ar[1] else (w, round(w * ar[1] / ar[0]))
    tw, th = min(tw, w), min(th, h)
    x = (w - tw) // 2
    y = max(0, min(h - th, round((h - th) * fy)))
    im = im.crop((x, y, x + tw, y + th))
    if SAT != 1.0:
        im = ImageEnhance.Color(im).enhance(SAT)
    if BRI != 1.0:
        im = ImageEnhance.Brightness(im).enhance(BRI)
    for width, dst in targets:
        r = im.resize((width, round(im.height * width / im.width)), Image.LANCZOS)
        r.save(dst, 'WEBP', quality=QUALITY, method=6)
        print(f'{dst.name:<26} {r.width}x{r.height}  {dst.stat().st_size/1024:5.0f} KB')
