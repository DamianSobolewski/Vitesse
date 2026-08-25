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

const BASE = process.env.VTS_BASE || 'http://localhost:8090';
const OUT  = '/tmp/claude-1000/-home-damian-Workspace-Vitesse/bdf3180e-44cb-47e5-a8c4-6c4d5405a949/scratchpad';
const SZEROKOSCI = [1440, 1280, 1100, 950, 700, 390];
const STRONY = ['/', '/podnoszenie-mocy/', '/podnoszenie-mocy/chip-tuning/',
  '/podnoszenie-mocy/powerboxy/', '/podnoszenie-mocy/odblokowywanie-sterownikow/',
  '/podnoszenie-mocy/oferta-dla-flot/', '/podnoszenie-mocy/dodatkowe-uslugi-ecu/',
  '/ev-hybryda/', '/hamownia/', '/o-nas/', '/kontakt/', '/wykresy-i-osiagi/'];

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

/* --- 2. cztery kafelki na laptopie ukladaja sie 2x2 ----------------------- */
{
  const p = await b.newPage({ viewport: { width: 1100, height: 900 } });
  await p.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(150);
  const u = await p.evaluate(() => {
    const g = document.querySelector('.vts-grid');
    const rzedy = new Set([...g.children].map((c) => Math.round(c.getBoundingClientRect().top)));
    return { rzedow: rzedy.size, n: g.children.length };
  });
  u.n === 4 && u.rzedow === 2 ? ok('1100px — cztery kafelki w ukladzie 2x2')
                              : zle(`1100px — cztery kafelki w ${u.rzedow} rzedach`);
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
