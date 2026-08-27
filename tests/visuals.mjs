/* Strażnik warstwy wizualnej.
 *
 * 1. Sierota w siatce — ostatni rząd z jednym wąskim kafelkiem. auto-fit dobiera
 *    kolumny szerokością i nie wie, ile jest elementów, więc 4 kafelki potrafiły
 *    ułożyć się 3+1. Liczymy z realnych pozycji elementów, nie z deklaracji CSS.
 *    Kafelek rozciągnięty na całą szerokość NIE jest sierotą — to świadomy układ.
 * 2. Podstrony bez grafiki — przed tą rundą cztery nie miały ani obrazu, ani SVG.
 * 3. Licencje — każdy plik z assets/img/ użyty w serwisie musi mieć wpis w CREDITS.md.
 */
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
import { execSync } from 'node:child_process';

const BASE = process.env.VTS_BASE || 'http://localhost:8090';
const OUT  = '/tmp/claude-1000/-home-damian-Workspace-Vitesse/bdf3180e-44cb-47e5-a8c4-6c4d5405a949/scratchpad';
const SZEROKOSCI = [1440, 1280, 1100, 950, 700, 390];
const STRONY = ['/', '/podnoszenie-mocy/', '/podnoszenie-mocy/chip-tuning/',
  '/podnoszenie-mocy/powerboxy/', '/podnoszenie-mocy/odblokowywanie-sterownikow/',
  '/podnoszenie-mocy/oferta-dla-flot/', '/podnoszenie-mocy/dodatkowe-uslugi-ecu/',
  '/ev-hybryda/', '/hamownia/', '/o-nas/', '/kontakt/', '/wykresy-i-osiagi/', '/blog/'];

const b = await chromium.launch();
let bledy = 0;
const zle = (m) => { bledy++; console.log('  BLAD  ' + m); };
const ok  = (m) => console.log('  ok    ' + m);

/* --- 1. sierory w siatkach ------------------------------------------------ */
for (const w of SZEROKOSCI) {
  const p = await b.newPage({ viewport: { width: w, height: 900 } });
  const znalezione = [];
  for (const s of STRONY) {
    await p.goto(BASE + s, { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(120);
    const r = await p.evaluate(() => [...document.querySelectorAll('.vts-grid')].map((g) => {
      const szer = g.getBoundingClientRect().width;
      const rzedy = new Map();
      for (const c of g.children) {
        const t = Math.round(c.getBoundingClientRect().top);
        if (!rzedy.has(t)) rzedy.set(t, []);
        rzedy.get(t).push(c);
      }
      const klucze = [...rzedy.keys()].sort((a, c) => a - c);
      const uklad  = klucze.map((k) => rzedy.get(k).length);
      const kolumn = Math.max(...uklad);
      const ostatni = rzedy.get(klucze[klucze.length - 1]);
      // sierota = jeden kafelek w ostatnim rzedzie, ktory NIE zajmuje calej szerokosci
      const sierota = kolumn > 1 && uklad.length > 1 && ostatni.length === 1
        && ostatni[0].getBoundingClientRect().width < szer * 0.9;
      return { n: g.children.length, uklad: uklad.join('+'), sierota };
    }));
    r.forEach((x) => { if (x.sierota) znalezione.push(`${s} ${x.n}=${x.uklad}`); });
  }
  znalezione.length ? zle(`${w}px — sieroty: ${znalezione.join(' | ')}`)
                    : ok(`${w}px — zaden rzad nie zostawia samotnego kafelka`);
  await p.close();
}

/* --- 1b. jedna lewa krawedz ----------------------------------------------
 * Kontener narrow byl wysrodkowany, wiec jego lewa krawedz wypadala 230 px
 * dalej niz sasiedniej sekcji — na jednej podstronie potrafily byc trzy linie
 * startu i tresc skakala w bok przy przewijaniu. Liczymy z realnych pozycji.
 */
for (const w of [1440, 1280, 1100]) {
  const p = await b.newPage({ viewport: { width: w, height: 900 } });
  const zle_ = [];
  const pudelka = [];
  for (const s of STRONY) {
    await p.goto(BASE + s, { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(120);
    const lewe = await p.evaluate(() => {
      const el = [...document.querySelectorAll(
        '.vts-section .vts-wrap > *, .vts-hero__in, .site-main > .page-content > article')]
        .filter((e) => {
          if (e.getBoundingClientRect().width <= 0) return false;
          // kolumny ukladow dwudzielnych maja wlasna szerokosc z zalozenia
          return !e.closest('.vts-split');
        });
      return [...new Set(el.map((e) => Math.round(e.getBoundingClientRect().left)))].sort((a, c) => a - c);
    });
    if (lewe.length > 1) zle_.push(`${s} (${lewe.join(', ')})`);

    // Pas hero ma isc od krawedzi do krawedzi na KAZDEJ podstronie. Reguly
    // pisane pod liste bloga potrafia zamknac go w wysrodkowanym kontenerze
    // i uciac zdjecie — a test krawedzi tego nie zlapie, bo wszystko przesuwa
    // sie wtedy rowno i nadal jest jedna linia.
    const heroPelne = await p.evaluate(() => {
      const h = document.querySelector('.vts-hero');
      return h ? Math.round(h.getBoundingClientRect().width) === window.innerWidth : true;
    });
    if (!heroPelne) pudelka.push(s);
  }
  zle_.length ? zle(`${w}px — rozne lewe krawedzie: ${zle_.join(' | ')}`)
              : ok(`${w}px — wszystkie sekcje startuja z tej samej linii`);
  pudelka.length ? zle(`${w}px — hero w pudelku zamiast na pelnej szerokosci: ${pudelka.join(', ')}`)
                 : ok(`${w}px — pas hero na pelnej szerokosci okna`);
  await p.close();
}

/* --- 1c. jedna skala rozmiarow tekstu ------------------------------------
 * W arkuszach bylo 21 roznych sztywnych wartosci (8 … 26 px), bo skala nie
 * miala stopni na drobne etykiety i kazdy dopisywal swoja. Test sprawdza
 * WYRENDEROWANE rozmiary, nie deklaracje — wiec zlapie tez to, co wejdzie
 * z zewnatrz, np. ze stylow wtyczki formularza.
 */
{
  const p = await b.newPage({ viewport: { width: 1440, height: 900 } });
  const obce = new Map();
  for (const s of STRONY) {
    await p.goto(BASE + s, { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(140);
    (await p.evaluate(() => {
      // Stopnie to clamp(), wiec parseFloat na surowej wartosci zmiennej daje
      // NaN i test porownywalby wszystko z niczym. Rozwiazujemy je elementem
      // probnym — dopiero przegladarka policzy clamp dla tej szerokosci.
      const probka = document.createElement('span');
      probka.style.cssText = 'position:absolute;visibility:hidden';
      document.body.appendChild(probka);
      const skala = ['--vts-step--3', '--vts-step--2', '--vts-step--1', '--vts-step-0',
                     '--vts-step-1', '--vts-step-2', '--vts-step-3', '--vts-step-4']
        .map((n) => {
          probka.style.fontSize = 'var(' + n + ')';
          return parseFloat(getComputedStyle(probka).fontSize);
        });
      probka.remove();
      const poza = [];
      document.querySelectorAll('main *, header *, footer *').forEach((el) => {
        const ma = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim());
        if (!ma) return;
        const cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') return;
        const fs = parseFloat(cs.fontSize);
        if (!skala.some((v) => Math.abs(v - fs) < 0.6)) {
          poza.push(fs + 'px @ ' + (el.className || el.tagName).toString().slice(0, 30));
        }
      });
      return poza;
    })).forEach((x) => obce.set(x.split(' @ ')[0], x));
  }
  obce.size === 0 ? ok('wszystkie rozmiary tekstu ze skali (8 stopni)')
                  : zle(`rozmiary spoza skali: ${[...obce.values()].join(' | ')}`);
  await p.close();
}

/* --- 2. cztery kafelki na laptopie ukladaja sie 2x2 ----------------------- */
{
  const p = await b.newPage({ viewport: { width: 1100, height: 900 } });
  await p.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(150);
  const u = await p.evaluate(() => {
    // Szukamy siatki z DOKLADNIE czterema kafelkami — to jej dotyczy regula.
    // Wczesniej test bral pierwsza siatke na stronie i przestal cokolwiek
    // sprawdzac, gdy na gorze pojawila sie sekcja z piecioma wynikami.
    const g = [...document.querySelectorAll('.vts-grid')].find((x) => x.children.length === 4);
    if (!g) return { rzedow: 0, n: 0 };
    const rzedy = new Set([...g.children].map((c) => Math.round(c.getBoundingClientRect().top)));
    return { rzedow: rzedy.size, n: g.children.length };
  });
  u.n === 4 && u.rzedow === 2 ? ok('1100px — cztery kafelki w ukladzie 2x2')
                              : zle(`1100px — cztery kafelki w ${u.rzedow} rzedach`);
  await p.close();
}

/* --- 2b. zegary: podswietlenie i sekwencja rozruchu ----------------------
 * Wskazowka ma zrobic pelny wychyl i wrocic na wartosc — tak jak kokpit przy
 * przekreceniu kluczyka. Mierzymy realny przebieg katu, nie deklaracje CSS.
 */
{
  const p = await b.newPage({ viewport: { width: 1440, height: 900 } });
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.evaluate(() => document.querySelector('.vts-gauges').scrollIntoView());

  const kat = () => p.evaluate(() => {
    const t = getComputedStyle(document.querySelector('.vts-gauge__needle')).transform;
    const m = t.match(/matrix\(([^,]+),\s*([^,]+)/);
    if (!m) return 0;
    const st = Math.atan2(+m[2], +m[1]) * 180 / Math.PI;
    return st < 0 ? st + 360 : st;
  });
  const seria = [];
  for (let i = 0; i < 16; i++) { seria.push(await kat()); await p.waitForTimeout(110); }

  const szczyt = Math.max(...seria);
  const koniec = seria[seria.length - 1];
  const cel = await p.evaluate(() =>
    parseFloat(document.querySelector('.vts-gauge').style.getPropertyValue('--vts-kat')));

  szczyt > 200
    ? ok(`zegar robi pelny wychyl przy wejsciu w kadr (${Math.round(szczyt)} stopni)`)
    : zle(`brak wychylu — maksimum ${Math.round(szczyt)} stopni`);
  Math.abs(koniec - cel) < 6
    ? ok(`wskazowka siada na wartosci (${Math.round(koniec)} wobec ${cel})`)
    : zle(`wskazowka konczy na ${Math.round(koniec)}, a wartosc to ${cel}`);

  const stan = await p.evaluate(() => [...document.querySelectorAll('.vts-gauge')].map((g) => ({
    luna: +getComputedStyle(g.querySelector('.vts-gauge__glow')).opacity,
    zapalone: g.querySelectorAll('.vts-gauge__ticks line.is-lit').length,
  })));
  stan.every((x) => x.luna > 0.9) ? ok('podswietlenie tarcz zapalone na wszystkich zegarach')
                                  : zle(`podswietlenie: ${JSON.stringify(stan.map((x) => x.luna))}`);
  stan.every((x) => x.zapalone > 0) ? ok('podzialka zapalona do wartosci')
                                    : zle('podzialka nie zapalila sie na wszystkich zegarach');
  await p.close();
}

/* --- 2c. akordeon z wylacznoscia -----------------------------------------
 * Otwarcie kolejnej pozycji ma zwinac poprzednia. Robi to natywny atrybut name
 * na <details>, wiec sprawdzamy to takze przy wylaczonym JavaScripcie — gdyby
 * ktos kiedys zgubil atrybut, testu nie uratuje skrypt.
 */
for (const [jsOn, opis] of [[true, 'z JS'], [false, 'bez JS']]) {
  const c = await b.newContext({ javaScriptEnabled: jsOn, viewport: { width: 1440, height: 900 } });
  const p = await c.newPage();
  const zle_ = [];
  for (const s of ['/', '/faq/']) {
    await p.goto(BASE + s, { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(200);
    if (await p.locator('details').count() < 2) continue;
    await p.locator('summary').nth(0).click();
    await p.waitForTimeout(180);
    await p.locator('summary').nth(1).click();
    await p.waitForTimeout(180);
    const otwarte = await p.locator('details[open]').count();
    if (otwarte !== 1) zle_.push(`${s}: otwartych ${otwarte}`);
  }
  zle_.length ? zle(`akordeon ${opis} nie zwija poprzedniej: ${zle_.join(' | ')}`)
              : ok(`akordeon ${opis} — otwarta zawsze jedna pozycja`);
  await c.close();
}

/* --- 2d. sekcja wynikow --------------------------------------------------
 * Kafelki czytaja dane z bazy przy renderze. Test sprawdza trzy rzeczy, ktore
 * moga sie zepsuc po cichu: martwy odnosnik do katalogu, rozjazd liczb z baza
 * i podmiane przykladow na wartosci ze skraju rozkladu.
 */
{
  const p = await b.newPage({ viewport: { width: 1440, height: 900 } });
  await p.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(200);

  const karty = await p.evaluate(() => [...document.querySelectorAll('.vts-wynik')].map((w) => {
    const t = w.querySelector('.vts-wynik__liczby').innerText.replace(/\s+/g, ' ');
    const moc = t.match(/(\d+)\s*→\s*(\d+)/);
    return {
      href: new URL(w.href).pathname,
      auto: w.querySelector('.vts-wynik__auto b').innerText.trim(),
      fabr: moc ? +moc[1] : null,
      po: moc ? +moc[2] : null,
      poglad: !!w.querySelector('.vts-chart__stopka'),
      pogladTekst: w.querySelector('.vts-chart__stopka')?.textContent || '',
    };
  }));

  karty.length === 3 ? ok(`sekcja wynikow: ${karty.length} przyklady w jednym rzedzie`)
                     : zle(`sekcja wynikow ma ${karty.length} przykladow zamiast 3`);

  // 1. kazdy odnosnik musi prowadzic do zyjacej wersji w katalogu
  const martwe = [];
  for (const k of karty) {
    const r = await fetch(BASE + k.href, { redirect: 'manual' });
    if (r.status !== 200) martwe.push(`${k.auto} → ${k.href} (${r.status})`);
  }
  martwe.length ? zle(`martwe odnosniki do katalogu: ${martwe.join(' | ')}`)
                : ok('kazdy wynik prowadzi do istniejacej wersji w katalogu');

  // 2. przyrosty w rozsadnym przedziale — w bazie sa wartosci skrajne
  //    (41 wariantow powyzej 60%), ktore nie maja czego szukac na stronie glownej
  const poza = karty.filter((k) => {
    const proc = 100 * (k.po - k.fabr) / k.fabr;
    return !(proc >= 10 && proc <= 35);
  }).map((k) => `${k.auto} ${Math.round(100 * (k.po - k.fabr) / k.fabr)}%`);
  poza.length ? zle(`przyrosty poza przedzialem 10-35%: ${poza.join(', ')}`)
              : ok('przyrosty reprezentatywne (10-35% mocy fabrycznej)');

  // 3. podpis o pogladowym przebiegu musi byc na kazdym wykresie
  const bezPodpisu = karty.filter((k) => !k.poglad || !/pogl/i.test(k.pogladTekst));
  bezPodpisu.length === 0
    ? ok('kazdy wykres podpisany jako przebieg pogladowy')
    : zle(`${bezPodpisu.length} wykresow bez podpisu o pogladowym przebiegu`);

  // 4. liczby zgodne z baza — inaczej sekcja rozjedzie sie po imporcie katalogu
  let zBazy = null;
  try {
    const surowe = execSync(
      "docker compose run --rm -T wpcli db query \"SELECT e.stock_hp, z.gain_hp "
      + "FROM wp_vts_gain z JOIN wp_vts_engine e ON e.id=z.engine_id "
      + "WHERE z.service_code='chip' AND z.visibility=1 AND e.id IN (10889,10700,8229) "
      + "ORDER BY FIELD(e.id,10889,10700,8229);\" --skip-column-names",
      { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
    zBazy = surowe.trim().split('\n').map((l) => l.trim().split(/\s+/).map(Number));
  } catch { /* brak dostepu do bazy — pomijamy, ale mowimy o tym */ }

  if (!zBazy || zBazy.length !== 3) {
    console.log('  (uwaga: nie udalo sie odpytac bazy — pomijam porownanie liczb)');
  } else {
    const rozjazd = karty.map((k, i) => {
      const [fabr, gain] = zBazy[i];
      return (k.fabr === fabr && k.po === fabr + gain) ? null
        : `${k.auto}: strona ${k.fabr}→${k.po}, baza ${fabr}→${fabr + gain}`;
    }).filter(Boolean);
    rozjazd.length ? zle(`liczby rozjechane z baza: ${rozjazd.join(' | ')}`)
                   : ok('liczby w kafelkach zgodne z baza');
  }
  await p.close();
}

/* --- 2e. pasek liczb ------------------------------------------------------ */
{
  const p = await b.newPage({ viewport: { width: 1440, height: 900 } });
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.evaluate(() => document.querySelector('.vts-liczby').scrollIntoView());
  await p.waitForTimeout(2200);
  const l = await p.evaluate(() => [...document.querySelectorAll('.vts-liczba b')]
    .map((x) => x.textContent.trim()));
  const silnikow = +(l[0] || '').replace(/\D/g, '');
  const marek = +(l[1] || '').replace(/\D/g, '');
  silnikow > 4000 && marek > 50 && l[2] === '2008'
    ? ok(`pasek liczb konczy na wartosciach z bazy (${l.join(' · ')})`)
    : zle(`pasek liczb: ${l.join(' · ')}`);
  await p.close();
}

/* --- 2f. blog ------------------------------------------------------------- */
{
  const p = await b.newPage({ viewport: { width: 1440, height: 900 } });
  await p.goto(BASE + '/blog/', { waitUntil: 'domcontentloaded' });
  const linki = await p.evaluate(() => [...document.querySelectorAll('a')]
    .map((a) => new URL(a.href).pathname)
    .filter((h) => /^\/[a-z0-9-]+\/$/.test(h) && h !== '/blog/'));
  const wpisy = [...new Set(linki)];

  const braki = [];
  let sprawdzonych = 0;
  for (const h of wpisy) {
    await p.goto(BASE + h, { waitUntil: 'domcontentloaded' });
    const w = await p.evaluate(() => ({
      post: document.body.className.includes('single-post'),
      h1: (document.querySelector('h1') || {}).textContent || '',
      znakow: (document.querySelector('main') || document.body).innerText.length,
      opis: document.querySelector('meta[name="description"]')?.content || '',
      okruszki: [...document.querySelectorAll('.vts-crumbs a')].map((a) => a.textContent),
      surowySkrot: (document.body.innerText || '').includes('[vts_'),
    }));
    if (!w.post) continue;
    sprawdzonych++;
    if (w.h1.length < 8) braki.push(`${h}: brak tytulu`);
    if (w.znakow < 1200) braki.push(`${h}: tylko ${w.znakow} znakow tresci`);
    if (w.opis.length < 40) braki.push(`${h}: brak opisu SEO`);
    if (!w.okruszki.includes('Blog')) braki.push(`${h}: okruszki nie wracaja do bloga`);
    if (w.surowySkrot) braki.push(`${h}: nierozwiniety skrot w tresci`);
  }

  sprawdzonych >= 4 && braki.length === 0
    ? ok(`blog: ${sprawdzonych} wpisy z trescia, tytulem, opisem SEO i okruszkami`)
    : zle(`blog — sprawdzonych ${sprawdzonych}, problemy: ${braki.join(' | ') || 'za malo wpisow'}`);
  await p.close();
}

/* --- 3. kazda podstrona ma cos wizualnego --------------------------------- */
{
  const p = await b.newPage({ viewport: { width: 1440, height: 900 } });
  const puste = [];
  for (const s of STRONY) {
    await p.goto(BASE + s, { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(120);
    const n = await p.evaluate(() => {
      const m = document.querySelector('main') || document.body;
      return m.querySelectorAll('svg, img').length;
    });
    if (!n) puste.push(s);
  }
  puste.length ? zle(`bez zadnej grafiki: ${puste.join(', ')}`)
               : ok('kazda podstrona ma co najmniej jeden element graficzny');
  await p.close();
}

/* --- 4. licencje zdjec ---------------------------------------------------- */
{
  const p = await b.newPage({ viewport: { width: 1440, height: 900 } });
  const uzyte = new Set();
  for (const s of STRONY) {
    await p.goto(BASE + s, { waitUntil: 'networkidle' });
    (await p.evaluate(() => {
      const out = [];
      document.querySelectorAll('img').forEach((i) => out.push(i.currentSrc || i.src));
      document.querySelectorAll('*').forEach((e) => {
        const bg = getComputedStyle(e).backgroundImage;
        if (bg && bg !== 'none') [...bg.matchAll(/url\(["']?([^"')]+)/g)].forEach((m) => out.push(m[1]));
      });
      return out;
    })).forEach((u) => {
      // zasoby wychodza spod /wp-content/vts-assets/, nie /assets/ — przy zlej
      // sciezce ten test badal zero plikow i przepuszczal wszystko
      const m = /vts-assets\/img\/(.+)$/.exec(u);
      if (m) uzyte.add(m[1].replace(/\?.*$/, ''));
    });
  }
  const credits = readFileSync('assets/img/CREDITS.md', 'utf8');
  const brak = [...uzyte].filter((f) => !credits.includes(f.split('/').pop()));
  brak.length ? zle(`uzyte bez wpisu w CREDITS.md: ${brak.join(', ')}`)
              : ok(`licencje opisane dla wszystkich ${uzyte.size} uzytych plikow`);
  await p.close();
}

/* --- zrzuty do obejrzenia ------------------------------------------------- */
for (const [w, h] of [[1440, 900], [1100, 900], [390, 844]]) {
  const p = await b.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 2 });
  for (const [s, n] of [['/', 'home'], ['/podnoszenie-mocy/oferta-dla-flot/', 'floty'],
                        ['/hamownia/', 'hamownia'], ['/o-nas/', 'onas']]) {
    await p.goto(BASE + s, { waitUntil: 'networkidle' });
    await p.screenshot({ path: `${OUT}/wiz-${n}-${w}.png` });
  }
  await p.close();
}

console.log(bledy ? `\nPROBLEMOW: ${bledy}` : '\nWARSTWA WIZUALNA OK');
await b.close();
process.exit(bledy ? 1 : 0);
