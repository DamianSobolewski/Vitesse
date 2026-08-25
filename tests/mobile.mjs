/* Strażnik widoku mobilnego.
 *
 * Powstał po zgłoszeniu „na mobilce menu praktycznie nie da się wybrać innych
 * opcji". Przyczyną było backdrop-filter na nagłówku, które czyni go blokiem
 * zawierającym dla potomków position:fixed — menu zamykało się w jego
 * 69-pikselowym prostokącie. Test pilnuje skutku, nie przyczyny: menu ma
 * wypełniać ekran, wszystkie pozycje mają być osiągalne i klikalne.
 */
import { chromium } from 'playwright';

const BASE = process.env.VTS_BASE || 'http://localhost:8090';
const OUT  = '/tmp/claude-1000/-home-damian-Workspace-Vitesse/bdf3180e-44cb-47e5-a8c4-6c4d5405a949/scratchpad';
const VIEWPORTS = [{ w: 390, h: 844, nazwa: 'iPhone 14' }, { w: 360, h: 640, nazwa: 'Android maly' }];
const STRONY = ['/', '/podnoszenie-mocy/', '/chiptuning/audi/', '/kontakt/'];

const b = await chromium.launch();
let bledy = 0;
const zle = (m) => { bledy++; console.log('  BLAD  ' + m); };
const ok  = (m) => console.log('  ok    ' + m);

for (const v of VIEWPORTS) {
  console.log(`\n=== ${v.nazwa} ${v.w}x${v.h} ===`);
  // hasTouch/isMobile — bez tego przegladarka raportuje (pointer: fine)
  // i reguly dotykowe nigdy nie wchodza, choc na telefonie dzialaja
  const p = await b.newPage({ viewport: { width: v.w, height: v.h },
    deviceScaleFactor: 2, hasTouch: true, isMobile: true });
  await p.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(300);

  // --- chrom nad treścią -------------------------------------------------
  const chrom = await p.evaluate(() => {
    const h = document.querySelector('.vts-header');
    return h ? Math.round(h.getBoundingClientRect().bottom) : -1;
  });
  chrom > 0 && chrom < 120 ? ok(`chrom nad trescia ${chrom}px`)
                           : zle(`chrom nad trescia ${chrom}px (limit 120)`);

  // --- menu otwarte ------------------------------------------------------
  await p.click('.vts-burger');
  await p.waitForTimeout(320);

  const menu = await p.evaluate(() => {
    const n = document.getElementById('vts-nav');
    const r = n.getBoundingClientRect();
    const linki = [...n.querySelectorAll('a')];
    // pozycja osiagalna = widoczna teraz albo w zasiegu przewiniecia menu
    const osiagalne = linki.filter((a) => {
      const t = a.getBoundingClientRect().top - r.top + n.scrollTop;
      return t >= 0 && t <= n.scrollHeight;
    }).length;
    const fab = document.querySelector('.vts-fab');
    // Menu jest dzieckiem naglowka, wiec nakladka potrafi zaslonic wlasny
    // przycisk zamkniecia. Nie pytamy o z-index, tylko czy palec w niego trafia.
    const bt = document.querySelector('.vts-burger').getBoundingClientRect();
    const trafiony = document.elementFromPoint(bt.left + bt.width / 2, bt.top + bt.height / 2);
    return {
      wysokosc: Math.round(r.height),
      pozycji: linki.length,
      osiagalne,
      fabWidoczny: !!(fab && getComputedStyle(fab).display !== 'none'),
      zamkniecieKlikalne: !!(trafiony && trafiony.closest('.vts-burger')),
      scrollTla: window.scrollY,
    };
  });

  menu.wysokosc >= v.h * 0.8 ? ok(`menu ${menu.wysokosc}px z ${v.h}px ekranu`)
                             : zle(`menu tylko ${menu.wysokosc}px z ${v.h}px ekranu`);
  menu.osiagalne === menu.pozycji ? ok(`osiagalne wszystkie ${menu.pozycji} pozycje`)
                                  : zle(`osiagalne ${menu.osiagalne} z ${menu.pozycji} pozycji`);
  menu.fabWidoczny ? zle('dolny pasek CTA zaslania menu') : ok('dolny pasek CTA schowany');
  menu.zamkniecieKlikalne ? ok('przycisk zamkniecia nad nakladka')
                          : zle('nakladka zaslania przycisk zamkniecia');

  // hello-elementor maluje button:focus na malinowy #c36. Na telefonie stan po
  // dotknieciu zostaje, wiec obcy kolor zostawal na ekranie.
  const obcy = await p.evaluate(() => [...document.querySelectorAll('*')]
    .filter((e) => {
      const c = getComputedStyle(e);
      return [c.backgroundColor, c.color, c.borderColor].some((v) => v.includes('204, 51, 102'));
    }).length);
  obcy === 0 ? ok('brak malinowego #c36 z motywu bazowego')
             : zle(`#c36 z motywu bazowego na ${obcy} elementach`);

  // tło nie przewija się pod otwartym menu
  await p.mouse.move(v.w / 2, v.h - 40);
  await p.mouse.wheel(0, 600);
  await p.waitForTimeout(220);
  const scrollPo = await p.evaluate(() => window.scrollY);
  scrollPo === menu.scrollTla ? ok('tlo nie przewija sie pod menu')
                              : zle(`tlo przewinelo sie o ${scrollPo - menu.scrollTla}px`);

  await p.screenshot({ path: `${OUT}/mobile-${v.w}-menu.png` });

  // --- pozycja menu naprawdę prowadzi dalej (sedno zgloszenia) -----------
  const cel = await p.evaluate(() => {
    const linki = [...document.querySelectorAll('#vts-nav a')];
    const i = linki.findIndex((x) => new URL(x.href).pathname !== location.pathname);
    return { i, sciezka: i < 0 ? null : new URL(linki[i].href).pathname };
  });
  await p.locator('#vts-nav a').nth(cel.i).click();
  await p.waitForLoadState('domcontentloaded');
  await p.waitForTimeout(250);
  const teraz = new URL(p.url()).pathname;
  teraz === cel.sciezka ? ok(`klikniecie w menu prowadzi do ${teraz}`)
                        : zle(`klikniecie mialo prowadzic do ${cel.sciezka}, jest ${teraz}`);

  // po przejsciu blokada przewijania musi byc zdjeta
  const zablokowane = await p.evaluate(() =>
    getComputedStyle(document.documentElement).overflow === 'hidden');
  zablokowane ? zle('blokada przewijania zostala po zamknieciu menu')
              : ok('blokada przewijania zdjeta');

  // --- cele dotykowe i poziomy scroll na podstronach ---------------------
  for (const s of STRONY) {
    await p.goto(BASE + s, { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(260);
    const r = await p.evaluate(() => {
      // Dwa wyjatki, oba swiadome:
      //  - odnosnik w akapicie (telefon, mail w zdaniu) — rozciagniecie go do
      //    44 px rozwalilo by interlinie; WCAG 2.5.8 wprost zwalnia takie
      //    odnosniki z wymogu rozmiaru,
      //  - element ukryty dla widzacych (skip-link), pojawia sie na focusie.
      const male = [...document.querySelectorAll('a, button, select, input[type="submit"]')]
        .filter((e) => {
          const b = e.getBoundingClientRect();
          if (!b.width || !b.height) return false;
          if (e.closest('p')) return false;
          if (e.classList.contains('screen-reader-text')) return false;
          return b.height < 32;
        }).length;
      return { male, nadmiar: document.documentElement.scrollWidth - window.innerWidth };
    });
    r.male === 0 ? ok(`${s} — samodzielne cele dotykowe maja min. 32px`)
                 : zle(`${s} — celow ponizej 32px: ${r.male}`);
    r.nadmiar > 1 && zle(`${s} — poziomy scroll ${r.nadmiar}px`);
  }

  await p.close();
}

// zrzuty do obejrzenia
const m = await b.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
for (const [s, n] of [['/', 'home'], ['/podnoszenie-mocy/', 'oferta'], ['/chiptuning/audi/', 'katalog']]) {
  await m.goto(BASE + s, { waitUntil: 'networkidle' });
  await m.screenshot({ path: `${OUT}/mobile-${n}.png`, fullPage: false });
}
await m.close();

console.log(bledy ? `\nPROBLEMOW: ${bledy}` : '\nWIDOK MOBILNY OK');
await b.close();
process.exit(bledy ? 1 : 0);
