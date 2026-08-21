import { chromium } from 'playwright';
const OUT = '/tmp/claude-1000/-home-damian-Workspace-Vitesse/bdf3180e-44cb-47e5-a8c4-6c4d5405a949/scratchpad';
const PAGES = ['/', '/podnoszenie-mocy/oferta-dla-flot/', '/kontakt/', '/hamownia/', '/wykresy-i-osiagi/'];
const b = await chromium.launch();
let bad = 0;
for (const w of [1440, 768, 390]) {
  const p = await b.newPage({ viewport: { width: w, height: 900 } });
  for (const path of PAGES) {
    await p.goto('http://localhost:8090' + path, { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(280);
    const o = await p.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    const h1 = await p.locator('h1').count();
    const flag = o > 1 ? ' <-- POZIOMY SCROLL' : (h1 !== 1 ? ` <-- H1 x${h1}` : '');
    if (flag) bad++;
    console.log(`${String(w).padEnd(5)} ${path.padEnd(38)} nadmiar ${String(o).padStart(3)}px  h1=${h1}${flag}`);
  }
  await p.close();
}
// zrzuty mobilne
const m = await b.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
await m.goto('http://localhost:8090/', { waitUntil: 'networkidle' });
await m.screenshot({ path: `${OUT}/site-mobile.png` });
await m.click('.vts-burger');
await m.waitForTimeout(350);
await m.screenshot({ path: `${OUT}/site-mobile-menu.png` });
await m.close();
console.log(bad ? `\nPROBLEMOW: ${bad}` : '\nRWD i H1 OK na wszystkich stronach');
await b.close();
