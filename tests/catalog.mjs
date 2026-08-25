import { chromium } from 'playwright';

// Sprawdza katalog po przejściu na dane V-techa: kompletność kaskady, szczelność
// bramki leadowej i to, czy widać WSZYSTKIE poziomy produktu — wtyczka VT Konfigurator
// gubiła tu dwa z czterech, więc to jest regresja, której pilnujemy.
const B = 'http://localhost:8090/wp-json/vitesse/v1';
const get = async (p) => (await fetch(B + p)).json();

let fail = 0;
const check = (name, ok, info = '') => {
  if (!ok) fail++;
  console.log(`${ok ? 'OK  ' : 'BLAD'}  ${name}${info ? '  — ' + info : ''}`);
};

const makes = await get('/catalog/makes');
check('marki w kaskadzie', Array.isArray(makes) && makes.length >= 50, `${makes.length} marek`);
check('MAN obecny (marka spoza konfiguratora V-techa)',
  makes.some(m => /^man$/i.test(m.slug)), makes.filter(m => /man/i.test(m.slug)).map(m => m.slug).join(',') || 'brak');

// pełna kaskada na losowej marce z danymi
const make = makes.find(m => m.slug === 'ford') || makes[0];
const models = await get('/catalog/models?make=' + make.slug);
check('modele', models.length > 0, `${make.slug}: ${models.length}`);
const gens = await get('/catalog/generations?model=' + models[0].id);
check('generacje', gens.length > 0, `${models[0].name}: ${gens.length}`);
const engines = await get('/catalog/engines?generation=' + gens[0].id);
check('silniki', engines.length > 0, `${gens[0].name}: ${engines.length}`);

// bramka: dane fabryczne tak, przyrosty nie
const keys = engines.length ? Object.keys(engines[0]) : [];
check('kaskada nie zdradza przyrostow',
  !keys.some(k => /gain|tuned|price/.test(k)), keys.join(','));
check('kaskada podaje moc fabryczna', keys.includes('stock_hp'));
check('kaskada wydaje token bramki', keys.includes('token'));

// pełny wynik dopiero po przejściu bramki
const eng = engines[0];
const res = await fetch(B + '/lead', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ engine_id: eng.id, token: eng.token, email: 'test@example.com', consent: true }),
});
const body = await res.json();
check('bramka zwraca wynik', res.ok, `HTTP ${res.status}`);
if (res.ok) {
  check('warianty uslug obecne', body.results.length > 0,
    body.results.map(r => `${r.code} +${r.gain_hp}KM`).join(' | '));
  check('przyrosty sa dodatnie', body.results.every(r => r.gain_hp > 0 || r.gain_nm > 0));
}

// czy w bazie w ogóle występuje więcej niż jeden poziom PowerChip
const b = await chromium.launch();
const p = await b.newPage();
await p.goto('http://localhost:8090/chiptuning/');
const tiles = await p.locator('.vts-cat-tile').count();
check('indeks katalogu ma kafelki marek', tiles >= 50, `${tiles} kafelkow`);
await b.close();

console.log(fail ? `\n${fail} PROBLEMOW` : '\nkatalog OK');
process.exit(fail ? 1 : 0);
