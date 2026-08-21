import { chromium } from 'playwright';
const file = 'file:///home/damian/Workspace/Vitesse/design/kierunki-wizualne.html';
const b = await chromium.launch();
const p = await b.newPage({ viewport: { width: 1440, height: 1000 } });
const errs = [];
p.on('pageerror', e => errs.push('PAGEERROR: ' + e.message));
p.on('console', m => { if (m.type() === 'error') errs.push('CONSOLE: ' + m.text()); });
await p.goto(file, { waitUntil: 'networkidle' });

// kaskada w kierunku A
const a = p.locator('.mock-a');
await a.locator('[data-sel="make"]').selectOption({ index: 1 });
await a.locator('[data-sel="model"]').selectOption({ index: 1 });
await a.locator('[data-sel="gen"]').selectOption({ index: 1 });
await a.locator('[data-sel="eng"]').selectOption({ index: 2 });
await p.waitForTimeout(200);
console.log('A moc fabryczna :', await a.locator('[data-f="shp"]').textContent());
console.log('A zablokowanych :', await a.locator('.lock.hid').count());
await a.locator('.gate input').fill('test@example.com');
await a.locator('[data-submit]').click();
await p.waitForTimeout(200);
console.log('A po bramce     :', await a.locator('.lock.hid').count(), '| moc po:', await a.locator('[data-f="thp"]').textContent());

// kierunek B — wykres
const bm = p.locator('.mock-b');
await bm.locator('[data-sel="make"]').selectOption({ index: 2 });
await bm.locator('[data-sel="model"]').selectOption({ index: 1 });
await bm.locator('[data-sel="gen"]').selectOption({ index: 1 });
await bm.locator('[data-sel="eng"]').selectOption({ index: 3 });
await p.waitForTimeout(900);
console.log('B tytul         :', await bm.locator('[data-f="title"]').textContent());
console.log('B przyrost      :', await bm.locator('[data-f="dhp"]').textContent());
console.log('B dlugosc sciezki:', (await bm.locator('[data-f="tuned"]').getAttribute('d')).length);

// kierunek C — kalkulator
const c = p.locator('.mock-c');
console.log('C rocznie       :', await c.locator('[data-c="year"]').textContent());
await c.locator('[data-num="veh"]').fill('50');
await c.locator('[data-num="veh"]').dispatchEvent('input');
await p.waitForTimeout(150);
console.log('C po zmianie    :', await c.locator('[data-c="year"]').textContent(), '| zwrot:', await c.locator('[data-c="payback"]').textContent());

// brak poziomego scrolla
for (const w of [1440, 768, 390]) {
  await p.setViewportSize({ width: w, height: 900 });
  await p.waitForTimeout(250);
  const o = await p.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  console.log(`RWD ${w}px       : nadmiar ${o}px ${o <= 1 ? 'OK' : '<-- PROBLEM'}`);
}
console.log(errs.length ? '\nBLEDY:\n' + errs.join('\n') : '\nbrak bledow JS');
await b.close();
