# Źródła i licencje zdjęć

Wszystkie zdjęcia użyte w serwisie muszą mieć tu wpis. Bez wpisu — nie wchodzą na produkcję.

## Znak firmowy

### `logo-vitesse.svg`, `logo-vitesse-light-bg.svg`, `logo-vitesse-mono.svg`
* **Właściciel:** Vitesse — znak firmowy klienta, nie materiał licencjonowany.
* **Źródło pliku:** `księga znaku vitesse v2` (PDF), projekt i realizacja Create Hot Look
  Studio — chl.pl. Krzywe wyjęte wprost z wektorów w PDF-ie, bez przerysowywania:
  proporcje, podziały i odstępy są dokładnie takie jak w księdze.
* **Warianty:** podstawowy na ciemne tło (litery srebrne `#B7B8BA`), na jasne tło
  (litery grafitowe `#494A4E`) oraz monochromatyczny. Pomarańcz `#F46A00` jest w każdym
  wariancie ten sam — księga znaku nie dopuszcza zmiany kolorystyki akcentów.
* **Zasady użycia z księgi znaku:** nie rozciągać, nie obracać, nie zmieniać kolorów ani
  układu elementów, nie dodawać cieni i obrysów. Pole ochronne dookoła znaku ma wysokość
  samych liter. Minimalna szerokość: **160 px na ekranie**, 35–40 mm w druku — poniżej
  zlewają się poziome podziały wewnątrz liter.

## Zdjęcia o potwierdzonej licencji

### `hero.webp`, `hero-sm.webp` — tło sekcji hero
* **Autor:** Graham Pengelly
* **Źródło:** https://unsplash.com/photos/black-car-in-a-dark-room-ifC-l1kPLCs
* **Licencja:** Unsplash License — użycie komercyjne dozwolone, podanie autora nieobowiązkowe
  (zweryfikowane: plik na `images.unsplash.com`, nie na płatnym `plus.unsplash.com`)
* **Pobrano:** 21 sierpnia 2026
* **Obróbka:** wycinek z auta (oryginał 6000×4000) zmniejszony do 50% szerokości i dosunięty
  do prawej krawędzi płótna 3600×2480; reszta płótna wypełniona kolorem tła strony, lewa i górna
  krawędź wycinka wygaszone maską, żeby nie było szwu. Powód: całe auto nie mieści się obok
  nagłówka i wyszukiwarki, które zajmują lewe dwie trzecie sekcji.
  Warianty 1800 px i 900 px, konwersja do WebP.
* **Uwaga:** brak widocznych znaczków producenta i tablicy rejestracyjnej.

### `pas-*.webp` — pasy rozdzielające sekcje

Cztery zdjęcia wstawione jako ciemne pasy pod tekstem na podstronach, które wcześniej były
samym tekstem. Wszystkie pobrane 25 sierpnia 2026 z `images.unsplash.com` (nie z płatnego
`plus.unsplash.com` — sprawdzone na stronie każdego zdjęcia), licencja **Unsplash License**,
użycie komercyjne dozwolone, podanie autora nieobowiązkowe.

| Plik | Autor | Strona zdjęcia | Co przedstawia |
|---|---|---|---|
| `pas-onas.webp`, `pas-onas-sm.webp` | Mehmet Talha Onuk | `unsplash.com/photos/mechanics-working-in-automotive-repair-workshop-8t6tk7LYLrE` | wnętrze warsztatu z podnośnikami |
| `pas-floty.webp`, `pas-floty-sm.webp` | Marcin Jozwiak | `unsplash.com/photos/parked-trucks-kGoPcmpPT7c` | ciągniki siodłowe na placu (Jankowice, PL) |
| `pas-ev.webp`, `pas-ev-sm.webp` | Precious Madubuike | `unsplash.com/photos/electric-car-charging-on-city-street-N2Td7KpIvYc` | przewód ładowania wpięty do auta |
| `pas-chip.webp`, `pas-chip-sm.webp` | Abhishek Desai | `unsplash.com/photos/turned-on-gray-laptop-computer-placed-on-car-bucket-seat-nQbnF8FLJ0g` | laptop diagnostyczny na fotelu |

Każdy wiersz obejmuje oba warianty: 1800 px i mniejszy `-sm` dla wąskich ekranów.

**Obróbka:** kadr 16:6, nasycenie do 28%, przyciemnienie, zmieszanie z kolorem tła strony —
zdjęcie ma być teksturą pod tekstem, a nie fotografią poglądową. Warianty 1800 px i 900 px, WebP.

**Każdy pas jest podpisany „zdjęcie ilustracyjne".** To materiał stockowy, więc nie może
sugerować, że przedstawia halę Vitesse.

**Odrzucone w trakcie doboru** — zapisane, żeby nie wróciły przy kolejnym podejściu:
* `unsplash.com/photos/a-car-is-parked-inside-of-a-garage-QIeJeacWug8` (Chi Xiang) — w kadrze
  wyeksponowany szyld obcej firmy **SWISSVAX**; cudze logo na stronie Vitesse wprowadza w błąd.
* Wszystkie wyniki sygnowane **Getty Images** — to płatne Unsplash+, nie wolna licencja.
* Materiał z Openverse (CC0) — dostępne zdjęcia to fotografia dokumentalna bez związku
  z tematem, nie nadaje się na stronę komercyjną.

### `/hamownia/` — świadomie bez zdjęcia

Nie ma sensownego zdjęcia stockowego przedstawiającego hamownię podwoziową, a to jedyna
podstrona, na której zdjęcie naprawdę coś by wnosiło.

**W `tools/scrape/mirror/vitesse.auto.pl/images/` leży osiem własnych fotografii stanowiska**
(`*_hamownia*.jpg`) — realna hala z widocznymi rolkami, dmuchawą i pojazdami na stanowisku.
Są nieporównanie lepsze niż cokolwiek ze stocku. **Nie wstawiam ich, bo widać na nich tablice
rejestracyjne i oklejenia obcych firm** (MARK-TRANS-SPED, TOPLINE, przewoźnik autobusowy),
a zasada z tego pliku wymaga wcześniej zgód właścicieli pojazdów.

Do rozstrzygnięcia z klientem — zapisane w `PYTANIA-DO-KLIENTA.md`.

## Wykresy demonstracyjne — DO WYMIANY przed produkcją

`content/dyno/seed/*.webp` — 14 wydruków z hamowni **wygenerowanych** przez
`tools/make-dyno-charts.py` z wartości w `content/dyno/seed.json`. Własna grafika, bez
licencji zewnętrznych i bez pojazdów klientów; każdy podpisany drobnym „wydruk demonstracyjny".
Używane wyłącznie przez `bin/seed-dev.sh`. Do zastąpienia realnym archiwum hamowni wraz ze
zgodami właścicieli pojazdów. Cztery zdjęcia ze starego serwisu, które tu wcześniej leżały,
usunięte (widoczne tablice i oklejenia obcych firm).

## Zdjęcia o nieustalonym lub problematycznym pochodzeniu

Biblioteka odziedziczona po starym serwisie (`tools/scrape/mirror/.../images/`) zawiera materiały,
które **wyglądają na zdjęcia prasowe producentów**, a nie własność Vitesse:

| Plik | Prawdopodobne pochodzenie |
|---|---|
| `slide04.jpg`, `slide05.jpg` | materiały marketingowe Scania / Volvo |
| `samochody_ciezarowe_daf_04.jpg` | materiał prasowy DAF |
| `samochody_dostawcze_ford.jpg` | materiał prasowy Ford |
| `autobusy.jpg` | materiał prasowy Scania |
| `slide01.jpg`, `slide02.jpg`, `slide03.jpg`, `slide06.jpg`, `slide07.jpg` | zdjęcia stockowe, licencja nieustalona |

Bezspornie własne są zestawy `*_hamownia*` oraz `scania_volvo*` — fotografie z warsztatu.

**Żadne zdjęcie z tej tabeli nie jest obecnie używane w serwisie.** Poprzednie tło hero pochodziło
z `slide02.jpg` i zostało zastąpione zdjęciem o jasnej licencji. Sprawa pozostałych jest w
`PYTANIA-DO-KLIENTA.md`.

## Zdjęcia dodane we wrześniu 2026 — „duże zdjęcia" i przewagi

Wszystkie pobrane 13 września 2026 przez `tools/fetch-photos.py` z `images.unsplash.com`
(nie z płatnego `plus.unsplash.com`, sprawdzone polem `premium`/`plus` w odpowiedzi API),
licencja **Unsplash License**, użycie komercyjne dozwolone, podanie autora nieobowiązkowe.

| Plik | Autor | Strona zdjęcia | Co przedstawia |
|---|---|---|---|
| `foto-kamper.webp`, `foto-kamper-sm.webp` | Miraxh Tereziu | `unsplash.com/photos/white-camper-van-parked-by-a-wooden-fence-eUkjMvbeLug` | biały kamper przy płocie w górach |
| `foto-dpf.webp`, `foto-dpf-sm.webp` | engin akyurt | `unsplash.com/photos/collection-of-car-exhaust-catalytic-converters-and-parts--ShTRctVXlk` | katalizatory i filtry spalin na ścianie |
| `foto-wallbox.webp`, `foto-wallbox-sm.webp` | go-e | `unsplash.com/photos/woman-plugging-electric-car-charger-into-wall-Lv-vzrhjybE` | wallbox na drewnianej ścianie (na obudowie mały znak producenta go-e) |
| `foto-hamownia.webp`, `foto-hamownia-sm.webp`, `usp-hamownia.webp`, `usp-hamownia-sm.webp` | Mario Amé | `unsplash.com/photos/a-red-ducati-motorcycle-is-on-a-dyno-E5uUCbeBTfU` | motocykl na hamowni (widoczne oznaczenia Ducati i producenta hamowni) |
| `foto-volvo.webp`, `foto-volvo-sm.webp` | ERIK SETH | `unsplash.com/photos/a-white-volvo-car-parked-in-front-of-a-house-COYjMnrXHKM` | Volvo V90 Cross Country bokiem — bez widocznego znaczka, zgodnie z uwagą klienta |
| `foto-ecu.webp`, `foto-ecu-sm.webp` | Brenton Pearce | `unsplash.com/photos/a-close-up-of-a-car-engine-aJlJAwocqwk` | turbosprężarka i kolektor |
| `foto-onas.webp`, `foto-onas-sm.webp` | Mehmet Talha Onuk | `unsplash.com/photos/mechanics-working-in-automotive-repair-workshop-8t6tk7LYLrE` | hala warsztatu (to samo zdjęcie co `pas-onas`, inny kadr) |
| `foto-ev.webp`, `foto-ev-sm.webp` | Precious Madubuike | `unsplash.com/photos/electric-car-charging-on-city-street-N2Td7KpIvYc` | ładowanie auta elektrycznego (to samo co `pas-ev`, kadr 4:3) |
| `usp-unlock.webp`, `usp-unlock-sm.webp` | Mehmet Talha Onuk | `unsplash.com/photos/a-man-sitting-in-a-car-using-a-laptop-computer-dSosKR6g-W8` | laptop diagnostyczny w aucie |
| `usp-leasing.webp`, `usp-leasing-sm.webp` | Bence Balla-Schottner | `unsplash.com/photos/black-key-fob-iTHT1gXJuS8` | kluczyk na desce rozdzielczej |
| `usp-floty.webp`, `usp-floty-sm.webp` | Markus Winkler | `unsplash.com/photos/white-vans-parked-in-mossingen-3vlGNkDep4E` | rząd białych dostawczaków (widoczne znaczki VW) |
| `usp-kamper.webp`, `usp-kamper-sm.webp` | Rafael Peier | `unsplash.com/photos/an-rv-drives-along-a-scenic-mountain-road-at-sunset-muEU34zZlzs` | kamper na górskiej drodze |

Każdy plik ma wariant `-sm` (połowa szerokości). **Obróbka:** kadr 4:3 (`foto-*`) albo 16:9
(`usp-*`), nasycenie do 72%, jasność do 90% — zdjęcie ma zostać zdjęciem, ale nie krzyczeć
na ciemnym tle. Wszystkie podpisane „zdjęcie ilustracyjne" (poza kafelkami przewag, gdzie
podpis nie ma miejsca — tam zdjęcia nie sugerują hali Vitesse).

**Uwaga do wymiany na zdjęcia własne:** kamper, hamownia i warsztat to miejsca, gdzie własne
zdjęcie klienta sprzedaje lepiej niż stock. Wystarczy podmienić plik o tej samej nazwie.
