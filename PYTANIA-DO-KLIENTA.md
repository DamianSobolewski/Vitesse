# Vitesse — pytania blokujące start prac

Krótka lista. Pierwsze cztery pozycje blokują konkretne etapy, reszta jest do uzupełnienia w trakcie.

---

## 1. Skąd pochodzi baza mocy? — **blokuje cały katalog**

Na `vitesse.auto.pl` jest katalog osiągów: ~70 marek, modele, generacje, warianty silnikowe
z przyrostami mocy i momentu.

**Pytanie: czy te dane są własnością Vitesse, czy pochodzą z katalogu licencjonowanego
przez dostawcę oprogramowania tuningowego?**

Ma to znaczenie, bo jeśli baza jest licencjonowana, ponowna publikacja na nowym serwisie
może naruszać warunki licencji oraz prawo do bazy danych. Wtedy katalog trzeba zaprojektować
inaczej — a to najdłuższy etap całego projektu.

Potrzebna też **pisemna zgoda na pobranie zawartości obecnego serwisu** do migracji.

## 2. Dostępy — **blokuje przekierowania i pomiar**

- Google Search Console dla `vitesse.auto.pl`
- Google Analytics (jeśli jest podpięte)
- Dostęp do obecnego hostingu (FTP/SSH) — opcjonalnie, ale bardzo przyspiesza

Bez GSC nie wiemy, **które adresy faktycznie generują ruch**, a przy takiej liczbie podstron
nie da się sensownie ustawić priorytetów przekierowań.

## 3. Treści o wyłączaniu DPF / EGR / SCR — **blokuje część podstron**

*To nie jest opinia prawna — sygnalizuję ryzyko, decyzja należy do Państwa prawnika.*

Usunięcie urządzeń kontroli emisji w pojeździe dopuszczonym do ruchu po drogach publicznych
koliduje z wymogiem zgodności z homologacją. W Polsce prowadzono postępowania wobec warsztatów
reklamujących takie usługi wprost.

Nowy serwis ma te podstrony przygotowane, ale **domyślnie wyłączone**. Prosimy o stanowisko:
publikujemy, publikujemy w zawężonej formie (pojazdy poza ruchem publicznym: sport, tor,
maszyny, eksport), czy pomijamy. To samo dotyczy podstrony o tuningu aut na gwarancji i w leasingu.

## 4. Wycofanie serwisu ciężarowego — potwierdzenie świadomej decyzji

Zgodnie ze specyfikacją z serwisu znikają naprawy mechaniczne pojazdów ciężarowych
(Scania, Volvo, skrzynie, mosty, pneumatyka). **Chip tuning ciężarówek i autobusów zostaje.**

Obecny tytuł strony brzmi „*…hamownia, serwis Scania i Volvo*”, więc firma jest dziś
wyszukiwana także na te frazy. Usunięcie ich oznacza **utratę tego ruchu**.

Prosimy o potwierdzenie, że to decyzja świadoma. Spadek ruchu ogólnego rzędu 20–40%
przez 4–12 tygodni po przełączeniu jest w takiej migracji normalny.

---

## 5. Dane do stopki i wizytówki Google

- Pełna nazwa rejestrowa, **NIP**, **REGON** (brak na obecnej stronie)
- Potwierdzenie adresu: ul. Kolumny 267C, 93-631 Łódź
- **Przypisanie numerów do działów.** Na stronie są trzy: 511 205 980, 515 660 210, 42 203 22 31.
  Który jest do tuningu, który do flot, który do biura?
- Adres e-mail (lub dwa: detal / floty), na który mają trafiać zapytania z formularzy

## 6. Cennik

Katalog na starej stronie nie pokazuje cen — są na osobnej podstronie.
Czy możemy dostać **cennik lub reguły cenowe** (np. widełki per klasa pojazdu)?
Wyszukiwarka w nagłówku ma pokazywać orientacyjną wycenę i bez tego traci sens.

## 7. Wykresy z hamowni

- Ile jest archiwalnych wykresów i w jakiej formie (pliki, wydruki, zdjęcia)?
- Czy klienci wyrazili **zgodę na publikację**? Widoczne tablice rejestracyjne czynią
  pojazd i właściciela identyfikowalnymi, więc zgoda jest wymagana.
- Kto ma wgrywać nowe wykresy — potwierdzamy, że Krzysiek?
  Publikuje od razu, czy wpisy mają czekać na akceptację?

## 8. Zdjęcia producentów — sprawa do wyjaśnienia

Przeglądając materiały ze starej strony natrafiliśmy na zdjęcia, które wyglądają na
**materiały prasowe producentów**, a nie na Państwa własność:

| Plik na starej stronie | Prawdopodobne źródło |
|---|---|
| dwie ciężarówki na czarnym tle | materiał marketingowy Volvo / Scania |
| Scanie na górskiej drodze | materiał prasowy Scania |
| DAF XF na estakadzie | materiał prasowy DAF |
| Ford Transit o zmierzchu | materiał prasowy Ford |
| autobus Scania Touring | materiał prasowy Scania |

Komercyjne użycie takich zdjęć bez licencji bywa podstawą do roszczeń — a wraz z nową stroną
ryzyko przechodzi na Państwa. **Żadnego z nich nie użyliśmy w nowym serwisie.** Tło strony
głównej zastąpiliśmy zdjęciem o jednoznacznej, darmowej licencji (Unsplash), a źródło każdego
pliku zapisujemy w projekcie.

Prosimy o informację:
- Czy do któregoś z tych zdjęć mają Państwo licencję albo zgodę producenta?
- Czy możemy w ich miejsce użyć **własnych zdjęć z warsztatu**? To i tak lepiej sprzedaje —
  klient chce zobaczyć Wasz warsztat, nie folder reklamowy Scanii.

## 9. Materiały

- Logo w wektorze (SVG / AI / EPS)
- Zdjęcia: warsztat, hamownia, zespół, przykładowe realizacje
- Certyfikaty i materiały prasowe, jeśli mają zostać

## 10. Hosting docelowy

Gdzie ma stanąć nowy serwis? Potrzebny dostęp SSH i do panelu DNS.
Osobne pytanie: **czy stary serwer może działać jeszcze ~30 dni po przełączeniu domeny?**
To siatka bezpieczeństwa na wypadek, gdyby coś w przekierowaniach wymagało poprawki.

## 11. Later — do decyzji, nie blokuje

- Dekoder VIN: czy jest budżet na płatne API (europejskie bazy są odpłatne)?
- Asystent AI w wyszukiwarce: budżet miesięczny i zgoda na dopisanie dostawcy
  do polityki prywatności jako podmiotu przetwarzającego.
- Kiedy realnie planowane jest uruchomienie linii serwisowej aut osobowych (Jaguar / Land Rover)?
  Architektura jest już pod to przygotowana i ukryta.
