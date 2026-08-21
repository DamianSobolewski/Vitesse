import { chromium } from 'playwright';
const OUT = '/tmp/claude-1000/-home-damian-Workspace-Vitesse/bdf3180e-44cb-47e5-a8c4-6c4d5405a949/scratchpad';
const B = 'http://localhost:8090';
const b = await chromium.launch();
const p = await b.newPage({ viewport: { width: 1400, height: 1000 }, deviceScaleFactor: 1.3 });
const errs = [];
p.on('pageerror', e => errs.push('JS: ' + e.message));
p.on('console', m => { if (m.type() === 'error' && !m.text().includes('favicon')) errs.push('CONSOLE: ' + m.text()); });

const shots = [
  ['/', 'f-home'],
  ['/podnoszenie-mocy/oferta-dla-flot/', 'f-flota'],
  ['/chiptuning/', 'f-katalog'],
  ['/chiptuning/ford/focus/iii/', 'f-generacja'],
  ['/wykresy-i-osiagi/', 'f-wykresy'],
  ['/kontakt/', 'f-kontakt'],
];
for (const [path, name] of shots) {
  await p.goto(B + path, { waitUntil: 'networkidle' });
  await p.screenshot({ path: `${OUT}/${name}.png` });
  await p.screenshot({ path: `${OUT}/${name}-full.png`, fullPage: true });
}

// kalkulator flotowy
await p.goto(B + '/podnoszenie-mocy/oferta-dla-flot/', { waitUntil: 'networkidle' });
console.log('kalkulator rocznie:', await p.locator('[data-c="year"]').textContent(),
            '| zwrot:', await p.locator('[data-c="payback"]').textContent(),
            '| CO2:', await p.locator('[data-c="co2"]').textContent());

// wykresy
await p.goto(B + '/wykresy-i-osiagi/', { waitUntil: 'networkidle' });
console.log('kafelkow z wykresami:', await p.locator('.vts-dyno__card').count());

// wydajnosc strony glownej
await p.goto(B + '/', { waitUntil: 'networkidle' });
const perf = await p.evaluate(() => new Promise(res => {
  new PerformanceObserver(list => {
    const e = list.getEntries();
    res(Math.round(e[e.length - 1].startTime));
  }).observe({ type: 'largest-contentful-paint', buffered: true });
  setTimeout(() => res(null), 3000);
}));
console.log('LCP:', perf, 'ms');
console.log(errs.length ? 'BLEDY:\n' + errs.join('\n') : 'brak bledow JS');
await b.close();
