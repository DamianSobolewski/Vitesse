# Źródła i licencje zdjęć

Wszystkie zdjęcia użyte w serwisie muszą mieć tu wpis. Bez wpisu — nie wchodzą na produkcję.

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

## Zdjęcia demonstracyjne — DO WYMIANY przed produkcją

`content/dyno/seed/*.webp` — cztery zdjęcia z hamowni przeniesione ze starego serwisu,
używane wyłącznie przez `bin/seed-dev.sh` jako dane demonstracyjne środowiska deweloperskiego.
Do zastąpienia realnym archiwum klienta wraz ze zgodami właścicieli pojazdów na publikację.

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
