import { chromium } from 'playwright';
const OUT = '/tmp/claude-1000/-home-damian-Workspace-Vitesse/bdf3180e-44cb-47e5-a8c4-6c4d5405a949/scratchpad';
const B = 'http://localhost:8090/';
const b = await chromium.launch();
let fail = 0;
const check = (name, ok, info = '') => { if (!ok) fail++; console.log(`${ok ? 'OK  ' : 'BLAD'}  ${name}${info ? '  — ' + info : ''}`); };

// --- 1. LCP nie może się pogorszyć, element musi zostać ten sam
// Środowisko lokalne ma rozrzut rzędu +/-100 ms, więc bierzemy medianę z trzech
// przebiegów zamiast pojedynczego pomiaru.
{
  const runs = [];
  let el = '';
  for (let i = 0; i < 3; i++) {
    const ctx = await b.newContext({ viewport: { width: 1400, height: 900 } });
    const p = await ctx.newPage();
    await p.goto(B, { waitUntil: 'networkidle' });
    const r = await p.evaluate(() => new Promise(res => {
      new PerformanceObserver(l => { const e = l.getEntries().at(-1);
        res({ lcp: Math.round(e.startTime), el: e.element?.className || '' }); })
        .observe({ type: 'largest-contentful-paint', buffered: true });
      setTimeout(() => res(null), 3000);
    }));
    if (r) { runs.push(r.lcp); el = r.el; }
    await ctx.close();
  }
  runs.sort((a, b) => a - b);
  const med = runs[Math.floor(runs.length / 2)];
  check('mediana LCP <= 500 ms', med <= 500, `${med} ms  (przebiegi: ${runs.join(', ')})`);
  check('element LCP to nadal tlo hero', el.includes('vts-hero__bg'), el);
}

// --- 2. sekwencja: ciemno na starcie, zapalone po chwili
{
  const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
  await p.goto(B, { waitUntil: 'domcontentloaded' });
  const op = () => p.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.vts-hero__beams')).opacity));
  const veil = () => p.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.vts-hero__veil')).opacity));
  const start = await op();
  const veilStart = await veil();
  check('stan poczatkowy: swiatla zgaszone', start < 0.35, `swiatla ${start}`);
  check('stan poczatkowy: zaslona zalozona', veilStart > 0.5, `zaslona ${veilStart}`);
  await p.waitForTimeout(2600);
  const end = await op();
  const veilEnd = await veil();
  check('stan koncowy: swiatla zapalone', end >= 0.8, `swiatla ${end}`);
  check('stan koncowy: zaslona zdjeta', veilEnd <= 0.05, `zaslona ${veilEnd}`);
  await p.locator('.vts-hero').screenshot({ path: `${OUT}/anim-lit.png` });
  await p.close();
}

// --- 3. wyszukiwarka klikalna w trakcie animacji
{
  const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
  await p.goto(B, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(300);
  let clickable = true;
  try { await p.selectOption('[data-sel="make"]', 'ford', { timeout: 2000 }); }
  catch { clickable = false; }
  check('kaskada dziala w trakcie sekwencji', clickable);
  const pe = await p.evaluate(() => getComputedStyle(document.querySelector('.vts-hero__light')).pointerEvents);
  check('warstwa swiatla nie lapie klikniec', pe === 'none', pe);
  await p.close();
}

// --- 4. bez JavaScriptu wszystko widoczne
{
  const ctx = await b.newContext({ javaScriptEnabled: false, viewport: { width: 1400, height: 900 } });
  const p = await ctx.newPage();
  await p.goto(B, { waitUntil: 'domcontentloaded' });
  const beams = await p.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.vts-hero__beams')).opacity));
  const veilNo = await p.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.vts-hero__veil')).opacity));
  check('bez JS swiatla zapalone', beams >= 0.8, `swiatla ${beams}`);
  check('bez JS zaslony brak', veilNo <= 0.05, `zaslona ${veilNo}`);
  const sections = await p.evaluate(() =>
    [...document.querySelectorAll('.vts-section, .vts-card')]
      .filter(el => parseFloat(getComputedStyle(el).opacity) < 0.9).length);
  check('bez JS zadna sekcja nie jest ukryta', sections === 0, `ukrytych: ${sections}`);
  await p.screenshot({ path: `${OUT}/anim-nojs.png` });
  await ctx.close();
}

// --- 5. ograniczony ruch
{
  const ctx = await b.newContext({ reducedMotion: 'reduce', viewport: { width: 1400, height: 900 } });
  const p = await ctx.newPage();
  await p.goto(B, { waitUntil: 'networkidle' });
  await p.waitForTimeout(600);
  const beams = await p.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.vts-hero__beams')).opacity));
  const veilRm = await p.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.vts-hero__veil')).opacity));
  check('reduced-motion: swiatla od razu zapalone', beams >= 0.8, `swiatla ${beams}`);
  check('reduced-motion: zaslony brak', veilRm <= 0.05, `zaslona ${veilRm}`);
  const running = await p.evaluate(() =>
    document.getAnimations().filter(a => a.playState === 'running').length);
  check('reduced-motion: brak animacji w toku', running === 0, `w toku: ${running}`);
  await ctx.close();
}

// --- 6. wejscia kart
{
  const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
  await p.goto(B, { waitUntil: 'networkidle' });
  await p.waitForTimeout(400);
  const marked = await p.evaluate(() => document.querySelectorAll('.vts-reveal').length);
  check('karty oznaczone do wejscia', marked > 0, `${marked} szt.`);
  const inHero = await p.evaluate(() => document.querySelectorAll('.vts-hero .vts-reveal').length);
  check('hero wykluczone z wejsc', inHero === 0, `w hero: ${inHero}`);
  await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2));
  await p.waitForTimeout(900);
  const shown = await p.evaluate(() =>
    [...document.querySelectorAll('.vts-reveal')]
      .filter(el => el.getBoundingClientRect().top < innerHeight && el.getBoundingClientRect().bottom > 0)
      .every(el => el.classList.contains('is-in')));
  check('karty w widoku sa odsloniete', shown);
  await p.close();
}

console.log(fail ? `\n${fail} PROBLEMOW` : '\nwszystko przechodzi');
await b.close();
