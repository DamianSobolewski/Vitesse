/* Strażnik wyszukiwarki mocy w hero.
 *
 * Panel przeszedł drogę: prosty formularz → konsola środkowa → jednostka
 * radia → z powrotem jeden czysty panel (stylizacja kokpitowa przeniosła się
 * do sekcji „Cztery rzeczy"). Test przez cały czas pilnuje tego samego: nie
 * wyglądu, tylko tego, co pod nim działa — kaskady, szczelności bramki
 * i dostępności.
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

  /* --- kaskada zostaje na natywnych listach ------------------------------ */
  const natywne = await p.evaluate(() =>
    [...document.querySelectorAll('.vts-ps [data-sel]')].map((e) => e.tagName));
  natywne.length === 4 && natywne.every((t) => t === 'SELECT')
    ? ok('kaskada to cztery natywne listy')
    : zle(`kaskada: ${natywne.join(',') || 'brak'}`);

  /* --- kontrast napisow --------------------------------------------------
   * Zgloszenie z sesji: „kolor fontow w listach nie ma kontrastu". Przyczyna
   * byla konkretna — wygaszony slot mial 1,64:1. Liczymy realny kontrast
   * kazdego widocznego napisu wzgledem najgorszego punktu tla pod nim.
   */
  {
    const slabe = await p.evaluate(() => {
      const lum = (c) => {
        const [r, g, bb] = c.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number).map((v) => v / 255);
        const f = (x) => (x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4));
        return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(bb);
      };
      const kontrast = (a, bb) => {
        const [hi, lo] = [lum(a), lum(bb)].sort((x, y) => y - x);
        return (hi + 0.05) / (lo + 0.05);
      };
      // Gradientu nie pomijamy — inaczej test ucichlby tam, gdzie jest najmniej
      // pewny. Wyciagamy wszystkie przystanki i bierzemy najgorszy przypadek.
      const tla = (el) => {
        for (let e = el; e; e = e.parentElement) {
          const cs = getComputedStyle(e);
          const gi = cs.backgroundImage;
          if (gi && gi !== 'none') {
            const stopy = (gi.match(/rgba?\([^)]+\)/g) || [])
              .filter((c) => !/rgba\([^)]*,\s*0(\.\d+)?\)$/.test(c));
            if (stopy.length) return stopy;
          }
          const bg = cs.backgroundColor;
          if (bg && !/rgba\(0, 0, 0, 0\)|transparent/.test(bg)) return [bg];
        }
        return ['rgb(15, 17, 22)'];
      };
      const out = [];
      document.querySelectorAll('.vts-ps *').forEach((el) => {
        const wlasny = [...el.childNodes].filter((n) => n.nodeType === 3 && n.textContent.trim()).length > 0;
        if (!wlasny) return;
        const cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none' || +cs.opacity < 0.15) return;
        if (el.closest('.screen-reader-text')) return;
        const v = Math.min(...tla(el).map((bg) => kontrast(cs.color, bg)));
        if (v < 4.5) out.push(`${el.className || el.tagName} "${el.textContent.trim().slice(0, 18)}" ${v.toFixed(2)}:1`);
      });
      return out;
    });
    slabe.length === 0 ? ok('kontrast napisow min. 4,5:1 w najgorszym punkcie tla')
                       : zle(`za slaby kontrast: ${slabe.join(' | ')}`);
  }

  /* --- panel nie moze zaslaniac auta -------------------------------------
   * Zgloszenie: „form zaslania auto". Panel siegal do 890 px, a lewy reflektor
   * wypada na 854 — czyli lezal pod nim. Pozycje lampy liczymy z macierzy SVG
   * warstwy swiatel (punkt 1069/612 w jednostkach viewBox), wiec pomiar jest
   * dokladny i nie zalezy od analizy pikseli.
   */
  if (w >= 1000) {
    const kadr = await p.evaluate(() => {
      const svg = document.querySelector('.vts-hero__beams');
      const pkt = svg.createSVGPoint();
      pkt.x = 1069; pkt.y = 612;                    // lewy reflektor w viewBox
      const ekran = pkt.matrixTransform(svg.getScreenCTM());
      return { lampa: Math.round(ekran.x),
               panel: Math.round(document.querySelector('.vts-ps').getBoundingClientRect().right) };
    });
    kadr.panel < kadr.lampa
      ? ok(`panel konczy sie na ${kadr.panel}, lewy reflektor na ${kadr.lampa} — auto odslonięte`)
      : zle(`panel siega ${kadr.panel}, a lewy reflektor jest na ${kadr.lampa} — zaslania auto`);
  }

  /* --- swiatla awaryjne i wlacznik reflektorow ---------------------------
   * Zostaly po zdjeciu obudowy konsoli, bo naprawde dzialaja — zapalaja
   * swiatla w aucie na zdjeciu obok.
   */
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
    const proby = [];
    for (let i = 0; i < 12; i++) {
      proby.push(await p.evaluate(() =>
        +getComputedStyle(document.querySelector('.vts-hero__hazard')).opacity));
      await p.waitForTimeout(90);
    }
    const zapalonych = proby.filter((v) => v > 0.5).length;
    zapalonych > 2 && zapalonych < proby.length - 2
      ? ok(`awaryjne migaja — ${zapalonych}/${proby.length} probek zapalonych`)
      : zle(`awaryjne nie migaja: ${zapalonych}/${proby.length}`);
    await p.click('[data-hazard]');
    await p.waitForTimeout(120);
    await p.evaluate(() => document.querySelector('.vts-hero').classList.contains('is-hazard'))
      ? zle('awaryjne nie daja sie zgasic') : ok('drugie klikniecie gasi awaryjne');
  }
  {
    const stan = async () => p.evaluate(() => ({
      lit: document.querySelector('.vts-hero').classList.contains('is-lit'),
      pressed: document.querySelector('[data-power]').getAttribute('aria-pressed'),
    }));
    const a = await stan();
    await p.click('[data-power]'); await p.waitForTimeout(120);
    const b2 = await stan();
    await p.click('[data-power]'); await p.waitForTimeout(120);
    const c2 = await stan();
    a.lit && !b2.lit && c2.lit && b2.pressed === 'false' && c2.pressed === 'true'
      ? ok('wlacznik gasi i zapala swiatla pojazdu')
      : zle(`wlacznik swiatel: ${JSON.stringify([a, b2, c2])}`);
  }

  /* --- pelna sciezka do wyniku ------------------------------------------- */
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
  !/\d/.test(poWyborze.thp + poWyborze.ghp)
    ? ok('wartosci po modyfikacji zaslonięte do czasu podania adresu')
    : zle(`bramka przecieka: "${poWyborze.thp}" / "${poWyborze.ghp}"`);
  poWyborze.bramka ? ok('bramka widoczna po wyborze silnika') : zle('bramka sie nie pokazala');

  await p.fill('[name=email]', `straznik-${w}@example.com`);   // bez polskich znakow
  await p.check('[name=consent]');
  await p.click('.vts-ps__gate button[type=submit]');
  await p.waitForTimeout(2000);

  if (!odpowiedz) {
    zle('serwer nie zwrocil wyniku (limit zapytan albo blad)');
  } else {
    const po = await p.evaluate(() => {
      const t = (k) => document.querySelector(`[data-f="${k}"]`).textContent.trim();
      return { thp: t('thp'), ghp: t('ghp'),
               wariantow: document.querySelectorAll('.vts-ps__srv').length };
    });
    const naj = odpowiedz.results.reduce((a, r) => (!a || r.gain_hp > a.gain_hp ? r : a), null);
    naj && po.ghp === '+' + naj.gain_hp + ' KM'
      ? ok(`przyrost konczy na wartosci z serwera: ${po.ghp}`)
      : zle(`przyrost "${po.ghp}" != serwer "+${naj ? naj.gain_hp : '?'} KM"`);
    naj && naj.tuned_hp
      ? (po.thp === naj.tuned_hp + ' KM'
          ? ok(`moc po modyfikacji zgodna z serwerem: ${po.thp}`)
          : zle(`po modyfikacji "${po.thp}" != serwer "${naj.tuned_hp} KM"`))
      : ok('serwer nie podaje mocy po modyfikacji dla tej wersji');
    po.wariantow === odpowiedz.results.length
      ? ok(`rozpisane wszystkie ${po.wariantow} warianty`)
      : zle(`wariantow na stronie ${po.wariantow}, z serwera ${odpowiedz.results.length}`);
  }

  jsErr.length ? zle('bledy JS: ' + jsErr.join(' | ')) : ok('brak bledow JS');
  await p.screenshot({ path: `${OUT}/panel-test-${w}.png` });
  await p.close();
}

/* --- lista marek w HTML z serwera ---------------------------------------- */
{
  const html = await (await fetch(BASE + '/')).text();
  const opcji = (html.match(/<option value="[a-z0-9-]+">/g) || []).length;
  opcji > 50 ? ok(`\nlista marek w HTML serwera: ${opcji} pozycji`)
             : zle(`\nlista marek nie jest renderowana serwerowo (${opcji})`);
}

/* --- ograniczony ruch ----------------------------------------------------- */
{
  const c = await b.newContext({ reducedMotion: 'reduce', viewport: { width: 1440, height: 1000 } });
  const p = await c.newPage();
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.selectOption('[data-sel=make]', { label: 'BMW' }); await p.waitForTimeout(500);
  await p.selectOption('[data-sel=model]', { index: 3 });    await p.waitForTimeout(500);
  await p.selectOption('[data-sel=gen]', { index: 1 });      await p.waitForTimeout(500);
  await p.selectOption('[data-sel=eng]', { index: 1 });      await p.waitForTimeout(120);
  const v = await p.evaluate(() => document.querySelector('[data-f=shp]').textContent.trim());
  /^\d+ KM$/.test(v) ? ok(`reduced-motion: liczba od razu koncowa (${v})`)
                     : zle(`reduced-motion: "${v}" zamiast wartosci koncowej`);
  // wskazowki zegarow tez maja stac od razu na miejscu
  await p.locator('.vts-gauges').scrollIntoViewIfNeeded();
  await p.waitForTimeout(200);
  const stoi = await p.evaluate(() => {
    const g = document.querySelector('.vts-gauge');
    const t = getComputedStyle(g.querySelector('.vts-gauge__needle')).transform;
    return t !== 'none' && t !== 'matrix(1, 0, 0, 1, 0, 0)';
  });
  stoi ? ok('reduced-motion: wskazowki zegarow od razu na wartosci')
       : zle('reduced-motion: wskazowki zegarow zostaly na zerze');
  await c.close();
}

/* --- bez JavaScriptu ------------------------------------------------------ */
{
  const c = await b.newContext({ javaScriptEnabled: false, viewport: { width: 1440, height: 1000 } });
  const p = await c.newPage();
  await p.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  const widoczna = await p.locator('[data-sel=make]').isVisible();
  const marek = await p.locator('[data-sel=make] option').count();
  const zegarow = await p.locator('.vts-gauge').count();
  widoczna && marek > 50 && zegarow === 4
    ? ok(`bez JS: kaskada widoczna (${marek} marek), ${zegarow} zegary narysowane`)
    : zle(`bez JS: kaskada=${widoczna}, marek=${marek}, zegarow=${zegarow}`);
  await c.close();
}

/* --- LCP ------------------------------------------------------------------ */
{
  // Pojedyncza probka waha sie o ~100 ms miedzy przebiegami, wiec prog na niej
  // albo migotal, albo musialby byc bezuzytecznie luzny. Mediana z trzech jest
  // stabilna, a realna regresja i tak ja przesunie.
  const probki = [];
  for (let i = 0; i < 3; i++) {
    const p = await b.newPage({ viewport: { width: 1440, height: 1000 } });
    await p.goto(BASE + '/', { waitUntil: 'networkidle' });
    probki.push(await p.evaluate(() => new Promise((res) => {
      new PerformanceObserver((l) => {
        const e = l.getEntries();
        res(Math.round(e[e.length - 1].startTime));
      }).observe({ type: 'largest-contentful-paint', buffered: true });
      setTimeout(() => res(null), 3000);
    })));
    await p.close();
  }
  probki.sort((a, c) => a - c);
  const lcp = probki[1];
  lcp !== null && lcp < 450 ? ok(`LCP ${lcp} ms (mediana z ${probki.join(', ')})`)
                            : zle(`LCP ${lcp} ms (limit 450, probki ${probki.join(', ')})`);
}

console.log(bledy ? `\nPROBLEMOW: ${bledy}` : '\nPANEL OK');
await b.close();
process.exit(bledy ? 1 : 0);
