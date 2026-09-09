# Materiały źródłowe marki

Oryginały od klienta — **nie edytujemy ich w tym katalogu**. Wszystko, co trafia na stronę,
jest z nich wyprowadzane.

| Plik | Co to jest |
|---|---|
| `ksiega-znaku-vitesse-v2.pdf` | księga znaku, projekt Create Hot Look Studio (chl.pl) |
| `vitesse_dark_RGB.png` | znak na ciemne tło (litery srebrne) |
| `vitesse_light_RGB.png` | znak na jasne tło (litery grafitowe) |
| `vitesse_mono_black.png`, `vitesse_mono_white.png` | wersje monochromatyczne |

## Co z tego weszło do serwisu

**Znak** — `assets/img/logo-vitesse*.svg`. Krzywe pochodzą z wektorów zaszytych w PDF-ie,
nie z obrysowania PNG-a i nie z przerysowania: proporcje i podziały wewnątrz liter są
dokładnie takie jak w księdze. Odtworzenie:

```sh
pdftocairo -svg -f 1 -l 1 design/marka/ksiega-znaku-vitesse-v2.pdf strona1.svg
# ze strony tytułowej biorą się dwie ścieżki: litery (#B7B8BA) i akcenty (#F46A00),
# przesunięte do układu 0 0 1405.31 117
```

PNG-i zostają jako materiał referencyjny i do zastosowań, gdzie wektor się nie nada
(np. załączniki, systemy zewnętrzne).

**Barwy** — `assets/css/tokens.css`: pomarańcz `#F46A00`, srebro `#B7B8BA`, grafit `#494A4E`.
Ciemne tła serwisu (`#0F1116` i dalej) to osobna, głębsza skala niż grafit ze znaku —
grafit jako tło strony dawał za mały kontrast dla tekstu.

**Krój** — IBM Plex Sans, hostowany lokalnie w `assets/fonts/` (bez zapytań do Google Fonts).
Odmiany: 400 tekst, 600 podtytuły i nawigacja, 700 nagłówki. Dane techniczne idą
IBM Plex Mono 500 — ta sama rodzina IBM Plex.

## Czego nie wolno

Z rozdziału „niedozwolone modyfikacje": nie rozciągać, nie zmieniać proporcji ani kolorów,
nie obracać, nie przestawiać elementów, nie dodawać cieni i obrysów, nie kłaść na tle
psującym czytelność. Pole ochronne dookoła znaku ma wysokość samych liter.
Minimum: **160 px szerokości na ekranie**, 35–40 mm w druku.
