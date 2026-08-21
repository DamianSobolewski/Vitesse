import { chromium } from 'playwright';
const OUT = '/tmp/claude-1000/-home-damian-Workspace-Vitesse/bdf3180e-44cb-47e5-a8c4-6c4d5405a949/scratchpad';
const b = await chromium.launch();
const p = await b.newPage({ viewport: { width: 1400, height: 1000 }, deviceScaleFactor: 1.3 });
const errs = [];
p.on('pageerror', e => errs.push('JS: ' + e.message));
p.on('console', m => { if (m.type() === 'error') errs.push('CONSOLE: ' + m.text()); });

await p.goto('http://localhost:8090/', { waitUntil: 'networkidle' });
await p.screenshot({ path: `${OUT}/site-home.png` });
await p.screenshot({ path: `${OUT}/site-home-full.png`, fullPage: true });

// kaskada
await p.selectOption('[data-sel="make"]', 'ford');
await p.waitForTimeout(700);
const models = await p.locator('[data-sel="model"] option').count();
await p.selectOption('[data-sel="model"]', { label: 'Focus' });
await p.waitForTimeout(700);
await p.selectOption('[data-sel="gen"]', { index: 1 });
await p.waitForTimeout(700);
const engines = await p.locator('[data-sel="eng"] option').count();
await p.selectOption('[data-sel="eng"]', { index: 2 });
await p.waitForTimeout(400);
console.log('modeli Ford:', models - 1, '| silnikow w generacji:', engines - 1);
console.log('moc fabryczna:', await p.locator('[data-f="shp"]').textContent());
console.log('zablokowanych komorek:', await p.locator('.vts-ps__cell.is-locked').count());
await p.locator('.vts-ps').screenshot({ path: `${OUT}/site-search.png` });

// bramka
await p.fill('.vts-ps__gate [name=email]', 'test@example.com');
await p.check('.vts-ps__gate [name=consent]');
await p.click('.vts-ps__gate button');
await p.waitForTimeout(2500);
console.log('po bramce zablokowanych:', await p.locator('.vts-ps__cell.is-locked').count());
console.log('moc po:', await p.locator('[data-f="thp"]').textContent());
console.log('warianty uslug:', await p.locator('.vts-ps__srv').count());
await p.locator('.vts-ps').screenshot({ path: `${OUT}/site-search-open.png` });

console.log(errs.length ? 'BLEDY:\n' + errs.join('\n') : 'brak bledow JS');
await b.close();
