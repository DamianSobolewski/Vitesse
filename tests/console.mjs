/* Strażnik wyszukiwarki mocy.
 *
 * Panel stoi pod hero strony głównej, bez bramki e-mail (od IX 2026 wynik
 * pokazuje się po kliknięciu przycisku). Test pilnuje tego, co pod spodem
 * działa: kaskady, wyniku z serwera, kontrastu i dostępności.
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import { tmpdir } from 'node:os';

const BASE = process.env.VTS_BASE || 'http://localhost:8090';
const OUT  = process.env.VTS_OUT || tmpdir();

let bledy = 0;
const zle = (m) => { bledy++; console.log('  BLAD  ' + m); };
const ok  = (m) => console.log('  ok    ' + m);

// Wynik ma limit zapytań na godzinę z adresu — po kilku przebiegach test
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
    if (r.url().includes('/catalog/result') && r.ok()) {
      odpowiedz = await r.json().catch(() => null);
    }
  });

  await p.goto(BASE + '/', { waitUntil: 'networkidle' });

  /* --- panel stoi pod hero, nie w nim ------------------------------------ */
  const wHero = await p.evaluate(() => !!document.querySelector('.vts-hero .vts-ps'));
  const podHero = await p.evaluate(() => {
    const h = document.querySelector('.vts-hero'); const ps = document.querySelector('.vts-ps');
    return h && ps && (h.compareDocumentPosition(ps) & Node.DOCUMENT_POSITION_FOLLOWING) > 0;
  });
  !wHero && podHero ? ok('wyszukiwarka pod hero, poza jego kadrem') : zle('wyszukiwarka nie stoi pod hero');
  const brakVin = await p.evaluate(() => !document.querySelector('.vts-ps [data-vin]'));
  brakVin ? ok('bez rzedu VIN (flaga vin_decoder wylaczona)') : zle('rzad VIN renderuje sie mimo wylaczonej flagi');

  /* --- kaskada zostaje na natywnych listach ------------------------------ */
  const natywne = await p.evaluate(() =>
    [...document.querySelectorAll('.vts-ps [data-sel]')].map((e) => e.tagName));
  natywne.length === 4 && natywne.every((t) => t === 'SELECT')
    ? ok('kaskada to cztery natywne listy')
    : zle(`kaskada: ${natywne.join(',') || 'brak'}`);

  /* --- kontrast napisow -------------------------------------------------- */
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
        if (el.closest('[hidden]')) return;
        const v = Math.min(...tla(el).map((bg) => kontrast(cs.color, bg)));
        if (v < 4.5) out.push(`${el.className || el.tagName} "${el.textContent.trim().slice(0, 18)}" ${v.toFixed(2)}:1`);
      });
      return out;
    });
    slabe.length === 0 ? ok('kontrast napisow min. 4,5:1 w najgorszym punkcie tla')
                       : zle(`za slaby kontrast: ${slabe.join(' | ')}`);
  }

  /* --- pelna sciezka do wyniku ------------------------------------------- */
  await p.selectOption('[data-sel=make]', { label: 'BMW' });
  await p.waitForTimeout(500);
  await p.selectOption('[data-sel=model]', { index: 3 });
  await p.waitForTimeout(500);
  await p.selectOption('[data-sel=gen]', { index: 1 });
  await p.waitForTimeout(500);
  await p.selectOption('[data-sel=eng]', { index: 1 });
  await p.waitForTimeout(300);

  const przed = await p.evaluate(() => ({
    ukryty: document.querySelector('[data-out]').hidden,
    cta: !document.querySelector('[data-cta]').disabled,
  }));
  przed.ukryty ? ok('wynik ukryty do czasu klikniecia') : zle('wynik widoczny przed kliknieciem');
  przed.cta ? ok('przycisk aktywny po wyborze silnika') : zle('przycisk nieaktywny po wyborze silnika');

  await p.click('[data-cta]');
  await p.waitForTimeout(2000);

  if (!odpowiedz) {
    zle('serwer nie zwrocil wyniku (limit zapytan albo blad)');
  } else {
    const po = await p.evaluate(() => {
      const t = (k) => document.querySelector(`[data-f="${k}"]`).textContent.trim();
      return { shp: t('shp'), thp: t('thp'), ghp: t('ghp'),
               wariantow: document.querySelectorAll('.vts-ps__srv').length,
               kontakt: document.querySelector('[data-go-contact]').getAttribute('href'),
               katalog: document.querySelector('[data-go-catalog]').getAttribute('href') };
    });
    /\d/.test(po.shp) ? ok(`moc fabryczna na ekranie: ${po.shp}`) : zle(`brak mocy fabrycznej: ${po.shp}`);
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
    /vehicle=/.test(po.kontakt) ? ok('przycisk kontaktu niesie nazwe pojazdu') : zle(`kontakt bez pojazdu: ${po.kontakt}`);
    po.katalog === odpowiedz.url ? ok('przycisk katalogu prowadzi do strony wersji') : zle(`katalog: ${po.katalog}`);
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

/* --- wynik bez tokenu jest odrzucany ------------------------------------- */
{
  const r = await fetch(BASE + '/wp-json/vitesse/v1/catalog/result?engine=1&token=x');
  r.status === 403 ? ok('wynik bez waznego tokenu: 403') : zle(`wynik bez tokenu: ${r.status}`);
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
  await p.click('[data-cta]');                                await p.waitForTimeout(1200);
  const v = await p.evaluate(() => document.querySelector('[data-f=shp]').textContent.trim());
  /^\d+ KM$/.test(v) ? ok(`reduced-motion: liczba od razu koncowa (${v})`)
                     : zle(`reduced-motion: "${v}" zamiast wartosci koncowej`);
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
  widoczna && marek > 50 && zegarow === 5
    ? ok(`bez JS: kaskada widoczna (${marek} marek), ${zegarow} zegarow narysowanych`)
    : zle(`bez JS: kaskada=${widoczna}, marek=${marek}, zegarow=${zegarow}`);
  await c.close();
}

/* --- LCP ------------------------------------------------------------------ */
{
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
