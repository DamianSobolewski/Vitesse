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
