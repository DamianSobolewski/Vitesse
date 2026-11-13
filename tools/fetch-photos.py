#!/usr/bin/env python3
"""Przygotowuje warianty WebP do assets/img/ ze zdjęć z Unsplash (Unsplash License,
images.unsplash.com — nie Unsplash+) oraz ze zdjęć dostarczonych przez klienta.

Dwa rodzaje kadrów:
  foto-*  4:3, 1200 px + 600 px  — „duże zdjęcie" w połowie układu dwudzielnego
  usp-*   16:9, 900 px + 450 px  — zdjęcie w kafelku przewag na stronie głównej
  pas-*   8:3, 1800 px + 900 px  — tło nagłówka podstrony
  slajd-* 3:2, 1800 px + 900 px  — tło slajdu hero na stronie głównej

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
    python3 tools/fetch-photos.py unlock usp-kamper   # tylko wskazane klucze, od nowa

Źródła: id z Unsplash, 'client:<plik>' (tools/scrape/raw-client/), 'mirror:<plik>'
(zdjęcia ze starego serwisu, tools/scrape/mirror/vitesse.auto.pl/images/) albo
'asset:<plik>' (gotowy plik z assets/img/). Opcjonalne pola zadania: przesunięcie kadru
w poziomie (0–1), jasność, tryb 'right' (zdjęcie na całą wysokość przy prawej krawędzi,
reszta w kolorze tła hero).
"""
import pathlib, subprocess, sys
from PIL import Image, ImageEnhance, ImageOps

OUT    = pathlib.Path('assets/img')
CACHE  = pathlib.Path('tools/scrape/raw-unsplash'); CACHE.mkdir(parents=True, exist_ok=True)
CLIENT = pathlib.Path('tools/scrape/raw-client')
MIRROR = pathlib.Path('tools/scrape/mirror/vitesse.auto.pl/images')
HERO_BG = (15, 17, 22)    # --vts-bg z tokens.css

SAT, BRI, QUALITY = 1.0, 1.0, 80

# (klucz, źródło, rodzaj kadru, przesunięcie środka kadru w pionie 0–1)
JOBS = [
    ('kamper',       'client:kamper_2.jpg',        'foto', .5),    # #230, kamper na hamowni
    ('volvo',        'client:envato-car-saleswoman.jpg', 'foto', .55, .68),   # #243
    ('dawka',        'client:envato-starting-engine.jpg', 'foto', .85),        # #240
    ('ev',           'N2Td7KpIvYc', 'foto', .5),
    ('leasing',      'iTHT1gXJuS8', 'foto', .5),
    ('floty',        '3vlGNkDep4E', 'foto', .5),
    ('usp-floty',    '3vlGNkDep4E', 'usp',  .5),
    # zdjęcia od klienta (X 2026)
    ('temperatura',  'client:Temperatura pracy silnika.jpg',      'foto', .5, .62),   # runda 13.11.2026: nowe zdjęcie wskaźnika
    ('dolot',        'client:Czyszczenie układów dolotowych.jpg', 'foto', .45),
    ('skrzynia',     'client:envato-gear-shift.jpg',              'foto', .5, .35),   # #241
    ('klapy',        'client:Klapy wirowe.jpg',                   'foto', .5),
    ('sonda',        'client:Druga sonda Lambda i pompa powietrza dodatkowego (Subaru, SAI).jpg', 'foto', .5),
    ('sai',          'client:Pompa powietrza dodatkowego SAI.jpeg', 'foto', .5),
    # runda z 05.11.2026 - zdjęcia od klienta ('nowe zdjecia') i ze starego serwisu
    ('unlock',       'client:ECU_prog.jpg',        'foto', .5),
    ('hamownia',     'client:hamownia.jpg',        'foto', .5),
    ('dyno',         'client:dyno.jpg',            'foto', .55),
    ('startstop',    'client:start_stop.jpg',      'foto', .5),
    ('olej',         'client:oil_pressure.jpg',    'foto', .5),
    ('wallbox',      'client:accelev-two.jpg',     'foto', .5),
    ('onas',         'client:firma_5.jpg',         'foto', .5),
    ('powerchip',    'client:PowerChip.jpg',       'foto', .5),
    ('dpf',          'mirror:slide03.jpg',         'foto', .5, .5),    # „Wyłącz SCR"
    ('ecu',          'mirror:slide01.jpg',         'foto', .5, .22),   # „Zwiększ moc silnika"
    ('usp-unlock',   'client:ECU.jpg',             'usp',  .5),
    ('usp-leasing',  'client:gwarancja.jpg',       'usp',  .5),
    ('usp-kamper',   'client:kamper.jpg',          'usp',  .5, .95),
    ('usp-hamownia', 'client:hamownia_1WD.jpg',    'usp',  .55),
    ('pas-chip',     'client:chip_tuning.jpg',     'pas',  .4),
    ('pas-onas',     'client:firma_6.jpg',         'pas',  .5),
    ('slajd-ecu',        'client:ECU.jpg',         'slajd', .5),
    ('slajd-gwarancja',  'client:gwarancja.jpg',    'slajd', .5),
    ('slajd-dyno',       'client:dyno.jpg',         'slajd', .5, .5, 1.0, 'right'),
    ('slajd-flota',      'asset:pas-floty.webp',    'pas',   .5, .5, 1.7),   # to samo zdjęcie, jaśniejsze
]
KINDS = {'foto': ((4, 3), 1200, 600), 'usp': ((16, 9), 900, 450),
         'pas': ((8, 3), 1800, 900), 'slajd': ((3, 2), 1800, 900)}
only  = [a for a in sys.argv[1:] if not a.startswith('--')]
force = '--force' in sys.argv or bool(only)

def raw_url(pid):
    return f'https://unsplash.com/photos/{pid}/download?force=true&w=2400'

def source(pid: str) -> pathlib.Path:
    for prefix, folder in (('mirror:', MIRROR), ('asset:', OUT)):
        if pid.startswith(prefix):
            src = folder / pid[len(prefix):]
            if not src.exists():
                sys.exit(f'brak pliku: {src}')
            return src
    if pid.startswith('client:'):
        src = CLIENT / pid[len('client:'):]
        if not src.exists():
            sys.exit(f'brak pliku klienta: {src}')
        return src
    src = CACHE / f'{pid}.jpg'
    if not src.exists():
        subprocess.run(['curl', '-sL', '-m', '120', '-o', str(src), raw_url(pid)], check=True)
    return src

for key, pid, kind, fy, *extra in JOBS:
    if only and key not in only:
        continue
    fx, bri, mode = (list(extra) + [.5, 1.0, ''][len(extra):])[:3]
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
    if mode == 'right':
        # portret nie wypełni szerokiego kadru - stoi przy prawej krawędzi, a lewa strona
        # (pod tekstem slajdu) jest kolorem tła hero, z miękkim przejściem na styku
        fw = round(h * ar[0] / ar[1])
        fh = h
        canvas = Image.new('RGB', (fw, fh), HERO_BG)
        pw = round(w * min(1, fh / h))
        fade = max(1, pw // 6)
        mask = Image.linear_gradient('L').rotate(90).resize((fade, h))   # 0 -> 255 w poziomie
        alpha = Image.new('L', (pw, h), 255); alpha.paste(mask, (0, 0))
        canvas.paste(im, (fw - pw, 0), alpha)
        im = canvas
    else:
        x = max(0, min(w - tw, round((w - tw) * fx)))
        y = max(0, min(h - th, round((h - th) * fy)))
        im = im.crop((x, y, x + tw, y + th))
    if SAT != 1.0:
        im = ImageEnhance.Color(im).enhance(SAT)
    if BRI != 1.0:
        im = ImageEnhance.Brightness(im).enhance(BRI)
    if bri != 1.0:
        im = ImageEnhance.Brightness(im).enhance(bri)
    for width, dst in targets:
        r = im.resize((width, round(im.height * width / im.width)), Image.LANCZOS)
        r.save(dst, 'WEBP', quality=QUALITY, method=6)
        print(f'{dst.name:<26} {r.width}x{r.height}  {dst.stat().st_size/1024:5.0f} KB')
