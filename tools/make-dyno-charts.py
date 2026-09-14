#!/usr/bin/env python3
"""Renderuje wydruki z hamowni dla danych demonstracyjnych (content/dyno/seed.json).

Wygląd: papierowy wydruk z programu hamowni — białe tło, siatka, krzywa mocy
(ciągła) i momentu (przerywana), stan seryjny szary, po modyfikacji pomarańcz,
nagłówek z pojazdem i datą, ramka z wartościami szczytowymi. Kadr 3:2, tak jak
kafelek w bazie wykresów.

To NIE są realne pomiary — przebiegi są modelowane z wartości szczytowych, żeby
strona demonstracyjna wyglądała jak pełna baza. Uruchomienie:

    python3 tools/make-dyno-charts.py        # zapisuje content/dyno/seed/<key>.webp
"""
import json, math, pathlib
from PIL import Image, ImageDraw, ImageFont

ROOT = pathlib.Path(__file__).resolve().parent.parent
SEED = json.loads((ROOT / 'content/dyno/seed.json').read_text())
OUT  = ROOT / 'content/dyno/seed'; OUT.mkdir(parents=True, exist_ok=True)

W, H = 1500, 1000
FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'
FONT_B = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'
MONO = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf'
f = lambda p, s: ImageFont.truetype(p, s)

ORANGE, GREY, INK, GRID, PAPER = (222, 96, 0), (120, 126, 136), (28, 30, 36), (222, 224, 228), (255, 255, 255)

def torque_shape(x, fuel):
    """x w 0–1 zakresu obrotów. Diesel: półka od ~0,2 do ~0,55; benzyna: łagodny garb."""
    if fuel == 'diesel':
        rise = 1 / (1 + math.exp(-(x - .17) * 26))
        fall = 1 - .55 * max(0., x - .52) ** 1.3 / (.48 ** 1.3)
        return rise * fall
    rise = 1 / (1 + math.exp(-(x - .22) * 12))
    fall = 1 - .38 * max(0., x - .55) ** 1.4 / (.45 ** 1.4)
    return rise * fall

def curves(e, lo, hi, peak_nm, peak_hp, n=160):
    pts_t, pts_p = [], []
    raw = []
    for i in range(n + 1):
        x = i / n
        rpm = lo + (hi - lo) * x
        t = torque_shape(x, e['fuel'])
        raw.append((rpm, t))
    tmax = max(t for _, t in raw)
    for rpm, t in raw:
        nm = peak_nm * t / tmax
        pts_t.append((rpm, nm))
        pts_p.append((rpm, nm * rpm / 7022))       # KM z Nm i obrotów
    pmax = max(p for _, p in pts_p)
    pts_p = [(r, p * peak_hp / pmax) for r, p in pts_p]
    # po skalowaniu mocy moment lekko podnosimy w górnym zakresie, żeby obie
    # krzywe wyglądały spójnie (wydruk, nie fizyka)
    return pts_t, pts_p

def draw(e):
    im = Image.new('RGB', (W, H), PAPER); d = ImageDraw.Draw(im)
    lo, hi = e['rpm']
    stock_only = e['tuned_hp'] is None

    # nagłówek
    d.rectangle((0, 0, W, 92), fill=(245, 246, 248))
    d.line((0, 92, W, 92), fill=GRID, width=2)
    d.text((40, 22), 'VITESSE V-TECH ŁÓDŹ', font=f(FONT_B, 26), fill=ORANGE)
    d.text((40, 56), 'Hamownia podwoziowa 4×4 · pomiar mocy i momentu na kołach · ul. Kolumny 267C', font=f(FONT, 18), fill=GREY)
    d.text((W - 40, 24), e['date'], font=f(MONO, 22), fill=INK, anchor='ra')
    d.text((W - 40, 56), 'wydruk nr ' + str(abs(hash(e['key'])) % 9000 + 1000), font=f(MONO, 18), fill=GREY, anchor='ra')
    d.text((40, 112), e['title'], font=f(FONT_B, 34), fill=INK)

    # pole wykresu
    L, T, R, B = 110, 190, W - 130, H - 120
    nm_max = math.ceil((e['tuned_nm'] or e['stock_nm']) * 1.18 / 50) * 50
    hp_max = math.ceil((e['tuned_hp'] or e['stock_hp']) * 1.18 / 25) * 25
    d.rectangle((L, T, R, B), outline=GRID, width=2)
    for i in range(1, 8):
        y = T + (B - T) * i / 8
        d.line((L, y, R, y), fill=GRID, width=1)
        d.text((L - 14, y), str(int(hp_max * (1 - i / 8))), font=f(MONO, 17), fill=GREY, anchor='rm')
        d.text((R + 14, y), str(int(nm_max * (1 - i / 8))), font=f(MONO, 17), fill=GREY, anchor='lm')
    for i in range(1, 10):
        x = L + (R - L) * i / 10
        d.line((x, T, x, B), fill=GRID, width=1)
        d.text((x, B + 12), str(int(lo + (hi - lo) * i / 10)), font=f(MONO, 17), fill=GREY, anchor='ma')
    d.text((L - 14, T - 26), 'KM', font=f(MONO, 17), fill=GREY, anchor='ra')
    d.text((R + 14, T - 26), 'Nm', font=f(MONO, 17), fill=GREY, anchor='la')
    d.text(((L + R) / 2, B + 44), 'obr./min', font=f(MONO, 17), fill=GREY, anchor='ma')

    def px(rpm, val, vmax):
        return (L + (R - L) * (rpm - lo) / (hi - lo), B - (B - T) * val / vmax)

    def plot(pts, vmax, color, width, dashed=False):
        p = [px(r, v, vmax) for r, v in pts]
        if not dashed:
            d.line(p, fill=color, width=width, joint='curve'); return
        on = True; acc = 0
        for a, b in zip(p, p[1:]):
            if on: d.line((a, b), fill=color, width=width)
            acc += math.dist(a, b)
            if acc > (14 if on else 9): on = not on; acc = 0

    st_t, st_p = curves(e, lo, hi, e['stock_nm'], e['stock_hp'])
    plot(st_t, nm_max, GREY, 4, dashed=True)
    plot(st_p, hp_max, GREY, 5)
    if not stock_only:
        tu_t, tu_p = curves(e, lo, hi, e['tuned_nm'], e['tuned_hp'])
        plot(tu_t, nm_max, ORANGE, 4, dashed=True)
        plot(tu_p, hp_max, ORANGE, 6)

    # legenda i wartości
    lx, ly = L + 26, T + 22
    d.rectangle((lx - 12, ly - 12, lx + 470, ly + (150 if not stock_only else 96)), fill=(255, 255, 255), outline=GRID, width=2)
    rows = [(GREY, 'SERYJNIE', e['stock_hp'], e['stock_nm'])]
    if not stock_only:
        rows.append((ORANGE, 'PO MODYFIKACJI', e['tuned_hp'], e['tuned_nm']))
    for i, (col, lab, hp, nm) in enumerate(rows):
        y = ly + i * 54
        d.line((lx, y + 12, lx + 42, y + 12), fill=col, width=5)
        d.text((lx + 56, y), lab, font=f(MONO, 17), fill=col)
        d.text((lx + 56, y + 22), f'{hp} KM   {nm} Nm', font=f(FONT_B, 24), fill=INK)
    if not stock_only:
        y = ly + 108
        d.text((lx, y + 6), f'przyrost  +{e["tuned_hp"] - e["stock_hp"]} KM  /  +{e["tuned_nm"] - e["stock_nm"]} Nm',
               font=f(FONT_B, 20), fill=ORANGE)
    else:
        d.text((lx, ly + 62), 'pomiar kontrolny, bez modyfikacji', font=f(FONT, 18), fill=GREY)
    d.text((lx, ly + (168 if not stock_only else 114)), 'ciągła: moc · przerywana: moment', font=f(MONO, 15), fill=GREY)

    d.text((40, H - 46), 'Warunki: temp. 21 °C, ciśnienie 1012 hPa, korekcja DIN 70020. Wartości na kołach przeliczone na silnik.',
           font=f(FONT, 16), fill=GREY)
    d.text((W - 40, H - 46), 'wydruk demonstracyjny', font=f(MONO, 15), fill=(190, 194, 200), anchor='ra')
    return im

for e in SEED['entries']:
    im = draw(e)
    im = im.resize((1200, 800), Image.LANCZOS)
    dst = OUT / f'{e["key"]}.webp'
    im.save(dst, 'WEBP', quality=84, method=6)
    print(f'{dst.name:<26} {dst.stat().st_size/1024:5.0f} KB')
