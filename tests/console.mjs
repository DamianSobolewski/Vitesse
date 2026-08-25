/* Strażnik konsoli w hero.
 *
 * Konsola to najważniejszy element konwersyjny serwisu. Test pilnuje, żeby nowa
 * oprawa nie zjadła tego, co pod nią działa: kaskady, bramki i dostępności.
 * Sprawdzamy zachowanie, nie wygląd — wyglądu pilnują zrzuty.
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = process.env.VTS_BASE || 'http://localhost:8090';
const OUT  = '/tmp/claude-1000/-home-damian-Workspace-Vitesse/bdf3180e-44cb-47e5-a8c4-6c4d5405a949/scratchpad';

let bledy = 0;
const zle = (m) => { bledy++; console.log('  BLAD  ' + m); };
const ok  = (m) => console.log('  ok    ' + m);

// Bramka ma limit 5 zgłoszeń na godzinę z adresu — po kilku przebiegach test
// dostawałby 429 zamiast wyniku. Kasujemy licznik; to środowisko deweloperskie.
try {
  execSync('docker compose run --rm -T wpcli transient delete --all', { stdio: 'ignore' });
} catch { console.log('  (uwaga: nie udalo sie wyczyscic licznika zapytan)'); }

const b = await chromium.launch();

/* --- pełna ścieżka do wyniku na dwóch szerokościach ---------------------- */
for (const [w, h, opis] of [[1440, 1000, 'desktop'], [390, 844, 'telefon']]) {
  console.log(`\n=== ${opis} ${w}x${h} ===`);
  const p = await b.newPage({ viewport: { width: w, height: h },
    hasTouch: w < 500, isMobile: w < 500 });
  const jsErr = [];
  p.on('pageerror', (e) => jsErr.push(e.message));

  let odpowiedz = null;
  p.on('response', async (r) => {
    if (r.url().includes('/lead') && r.request().method() === 'POST' && r.ok()) {
      odpowiedz = await r.json().catch(() => null);
    }
  });

  await p.goto(BASE + '/', { waitUntil: 'networkidle' });

  // kaskada to nadal natywne listy
  const natywne = await p.evaluate(() =>
    [...document.querySelectorAll('.vts-con [data-sel]')].map((e) => e.tagName));
  natywne.length === 4 && natywne.every((t) => t === 'SELECT')
    ? ok('kaskada to cztery natywne listy')
    : zle(`kaskada: ${natywne.join(',') || 'brak'}`);

  // dekoracje nie mogą być klikalne ani focusowalne
  const atrapy = await p.evaluate(() => {
    const SEL = '.vts-con__vent, .vts-con__vent *, .vts-con__trim, .vts-con__hvac, .vts-con__hvac *';
    // Trojkat awaryjnych jest CELOWO klikalny — zapala awaryjne w aucie na
    // zdjeciu. Reszta blachy ma pozostac fasada.
    const d = [...document.querySelectorAll(SEL)]
      .filter((e) => !e.closest('[data-hazard]'));
    const zle = d.filter((e) => e.tabIndex >= 0 || e.onclick ||
      ['A', 'BUTTON', 'INPUT'].includes(e.tagName) ||
      getComputedStyle(e).cursor === 'pointer');
    // liczba znalezionych elementow tez sie liczy: gdyby klasy sie przemianowaly,
    // selektor trafialby w pustke i test przepuszczalby wszystko
    return { sprawdzonych: d.length, klikalnych: zle.length };
  });
  atrapy.sprawdzonych >= 8 && atrapy.klikalnych === 0
    ? ok(`obudowa jest dekoracja: ${atrapy.sprawdzonych} elementow, zaden nie reaguje`)
    : zle(`sprawdzonych ${atrapy.sprawdzonych}, klikalnych ${atrapy.klikalnych}`);

  /* --- swiatla awaryjne: jedyny przycisk na listwie ma dzialac ------------ */
  {
    const przed = await p.evaluate(() => ({
      klasa: document.querySelector('.vts-hero').classList.contains('is-hazard'),
      pressed: document.querySelector('[data-hazard]').getAttribute('aria-pressed'),
      opacity: +getComputedStyle(document.querySelector('.vts-hero__hazard')).opacity,
    }));
    !przed.klasa && przed.pressed === 'false' && przed.opacity === 0
      ? ok('awaryjne domyslnie zgaszone')
      : zle(`stan wyjsciowy awaryjnych: ${JSON.stringify(przed)}`);

    await p.click('[data-hazard]');
    // przez jeden pelny okres zbieramy przebieg — musi migac, a nie tylko zapalic sie
    const proby = [];
    for (let i = 0; i < 12; i++) {
      proby.push(await p.evaluate(() =>
        +getComputedStyle(document.querySelector('.vts-hero__hazard')).opacity));
      await p.waitForTimeout(90);
    }
    const zapalonych = proby.filter((v) => v > 0.5).length;
    const pressed = await p.evaluate(() =>
      document.querySelector('[data-hazard]').getAttribute('aria-pressed'));

    pressed === 'true' ? ok('przycisk melduje stan wcisniety')
                       : zle(`aria-pressed = ${pressed}`);
    zapalonych > 2 && zapalonych < proby.length - 2
      ? ok(`awaryjne migaja — ${zapalonych}/${proby.length} probek zapalonych`)
      : zle(`awaryjne nie migaja: ${zapalonych}/${proby.length} probek zapalonych`);

    await p.click('[data-hazard]');
    await p.waitForTimeout(120);
    const po = await p.evaluate(() =>
      document.querySelector('.vts-hero').classList.contains('is-hazard'));
    !po ? ok('drugie klikniecie gasi awaryjne') : zle('awaryjne nie daja sie zgasic');
  }

  // przed bramką presety muszą być wyłączone
  const przed = await p.evaluate(() =>
    [...document.querySelectorAll('.vts-con [data-srv]')]
      .filter((x) => !x.disabled).length);
  przed === 0 ? ok('presety wariantow przed bramka wylaczone')
              : zle(`${przed} presetow czynnych przed bramka`);

  // pełna kaskada
  await p.selectOption('[data-sel=make]', { label: 'BMW' });
  await p.waitForTimeout(500);
  await p.selectOption('[data-sel=model]', { index: 3 });
  await p.waitForTimeout(500);
  await p.selectOption('[data-sel=gen]', { index: 1 });
  await p.waitForTimeout(500);
  await p.selectOption('[data-sel=eng]', { index: 1 });
  await p.waitForTimeout(900);

  const poWyborze = await p.evaluate(() => {
    const t = (k) => document.querySelector(`[data-f="${k}"]`).textContent.trim();
    return { shp: t('shp'), thp: t('thp'), ghp: t('ghp'),
             bramka: !document.querySelector('[data-gate]').hidden };
  });
  /\d/.test(poWyborze.shp) ? ok(`moc fabryczna na ekranie: ${poWyborze.shp}`)
                           : zle(`brak mocy fabrycznej: ${poWyborze.shp}`);
  // sedno bramki: przed mailem na ekranie nie moze byc ZADNEJ cyfry po modyfikacji
  !/\d/.test(poWyborze.thp + poWyborze.ghp)
    ? ok('wartosci po modyfikacji zaslonięte do czasu podania adresu')
    : zle(`bramka przecieka: po modyfikacji "${poWyborze.thp}", przyrost "${poWyborze.ghp}"`);
  poWyborze.bramka ? ok('bramka widoczna po wyborze silnika') : zle('bramka sie nie pokazala');

  // bramka
  await p.fill('[name=email]', `straznik-${w}@example.com`);   // bez polskich znakow — adres musi przejsc walidacje
  await p.check('[name=consent]');
  await p.click('.vts-con__gate button[type=submit]');
  await p.waitForTimeout(2000);

  if (!odpowiedz) {
    zle('serwer nie zwrocil wyniku (limit zapytan albo blad)');
  } else {
    const po = await p.evaluate(() => {
      const t = (k) => document.querySelector(`[data-f="${k}"]`).textContent.trim();
      return { thp: t('thp'), ghp: t('ghp'),
               on: document.querySelector('[data-srv].is-on')?.dataset.srv || null,
               czynne: [...document.querySelectorAll('[data-srv]')].filter((x) => !x.disabled)
                         .map((x) => x.dataset.srv) };
    });
    const w1 = odpowiedz.results.find((r) => r.code === po.on);
    // liczby maja skonczyc na wartosci z REST, a nie na przypadkowej klatce odliczania
    w1 && po.ghp === '+' + w1.gain_hp + ' KM'
      ? ok(`przyrost konczy na wartosci z serwera: ${po.ghp}`)
      : zle(`przyrost "${po.ghp}" != serwer "+${w1 ? w1.gain_hp : '?'} KM"`);
    w1 && w1.tuned_hp
      ? (po.thp === w1.tuned_hp + ' KM'
          ? ok(`moc po modyfikacji zgodna z serwerem: ${po.thp}`)
          : zle(`po modyfikacji "${po.thp}" != serwer "${w1.tuned_hp} KM"`))
      : ok('serwer nie podaje mocy po modyfikacji dla tej wersji');

    const oczekiwane = odpowiedz.results.map((r) => r.code).sort().join(',');
    po.czynne.sort().join(',') === oczekiwane
      ? ok(`czynne presety zgodne z danymi: ${oczekiwane}`)
      : zle(`presety czynne ${po.czynne.join(',')} != warianty ${oczekiwane}`);
  }

  jsErr.length ? zle('bledy JS: ' + jsErr.join(' | ')) : ok('brak bledow JS');
  await p.screenshot({ path: `${OUT}/kon-test-${w}.png`, fullPage: false });
  await p.close();
}

/* --- lista marek w HTML z serwera ---------------------------------------- */
{
  const html = await (await fetch(BASE + '/')).text();
  const opcji = (html.match(/<option value="[a-z0-9-]+">/g) || []).length;
  opcji > 50 ? ok(`\nlista marek w HTML serwera: ${opcji} pozycji`)
             : zle(`\nlista marek nie jest renderowana serwerowo (${opcji} pozycji)`);
}

/* --- ograniczony ruch ----------------------------------------------------- */
{
  const c = await b.newContext({ reducedMotion: 'reduce', viewport: { width: 1440, height: 1000 } });
  const p = await c.newPage();
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.selectOption('[data-sel=make]', { label: 'BMW' });
  await p.waitForTimeout(500);
  await p.selectOption('[data-sel=model]', { index: 3 });
  await p.waitForTimeout(500);
  await p.selectOption('[data-sel=gen]', { index: 1 });
  await p.waitForTimeout(500);
  await p.selectOption('[data-sel=eng]', { index: 1 });
  await p.waitForTimeout(120);   // celowo krotko: bez odliczania wartosc ma byc juz koncowa
  const v = await p.evaluate(() => document.querySelector('[data-f=shp]').textContent.trim());
  /^\d+ KM$/.test(v) ? ok(`reduced-motion: liczba od razu koncowa (${v})`)
                     : zle(`reduced-motion: na ekranie "${v}" zamiast wartosci koncowej`);
  await c.close();
}

/* --- bez JavaScriptu ------------------------------------------------------ */
{
  const c = await b.newContext({ javaScriptEnabled: false, viewport: { width: 1440, height: 1000 } });
  const p = await c.newPage();
  await p.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  const s = await p.evaluate(() => 0).catch(() => null);   // JS wylaczony — uzywamy locatorow
  const widoczna = await p.locator('[data-sel=make]').isVisible();
  const marek = await p.locator('[data-sel=make] option').count();
  widoczna && marek > 50 ? ok(`bez JS: kaskada widoczna, ${marek} marek do wyboru`)
                         : zle(`bez JS: kaskada widoczna=${widoczna}, marek=${marek}`);
  await c.close();
}

/* --- LCP ------------------------------------------------------------------ */
{
  const p = await b.newPage({ viewport: { width: 1440, height: 1000 } });
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  const lcp = await p.evaluate(() => new Promise((res) => {
    new PerformanceObserver((l) => {
      const e = l.getEntries();
      res(Math.round(e[e.length - 1].startTime));
    }).observe({ type: 'largest-contentful-paint', buffered: true });
    setTimeout(() => res(null), 3000);
  }));
  lcp !== null && lcp < 400 ? ok(`LCP ${lcp} ms`) : zle(`LCP ${lcp} ms (limit 400)`);
  await p.close();
}

console.log(bledy ? `\nPROBLEMOW: ${bledy}` : '\nKONSOLA OK');
await b.close();
process.exit(bledy ? 1 : 0);
