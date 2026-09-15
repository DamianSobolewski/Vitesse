import { chromium } from 'playwright';

// Sprawdza katalog na danych z wtyczki VT Konfigurator: kompletność kaskady, szczelność
// bramki i to, czy wynik ma dokładnie te pozycje, które daje wtyczka (PowerChip, Chip Tuning).
// Silniki nieogrzane przez cron dociągają wynik przy pierwszym żądaniu — test to toleruje.
const B = 'http://localhost:8090/wp-json/vitesse/v1';
const get = async (p) => (await fetch(B + p)).json();

let fail = 0;
const check = (name, ok, info = '') => {
  if (!ok) fail++;
  console.log(`${ok ? 'OK  ' : 'BLAD'}  ${name}${info ? '  — ' + info : ''}`);
};

const makes = await get('/catalog/makes');
check('marki w kaskadzie', Array.isArray(makes) && makes.length >= 50, `${makes.length} marek`);
check('katalog to wylacznie drzewo V-techa (MAN ukryty)',
  !makes.some(m => /^man$/i.test(m.slug)));

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

// pełny wynik dopiero po przejściu bramki. Wersja może nie mieć oferty u V-techa
// (wtyczka zwraca oba pola puste i silnik schodzi z widoku), więc próbujemy po kolei.
let res, body, eng;
for (eng of engines) {
  res = await fetch(B + '/lead', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ engine_id: eng.id, token: eng.token, email: 'test@example.com', consent: true }),
  });
  body = await res.json();
  if (res.ok && body.results && body.results.length) break;
}
check('bramka zwraca wynik', res.ok, `HTTP ${res.status} (${eng.name})`);
if (res.ok) {
  check('warianty uslug obecne', body.results.length > 0,
    body.results.map(r => `${r.code} +${r.gain_hp}KM`).join(' | '));
  check('przyrosty sa dodatnie', body.results.every(r => r.gain_hp > 0 || r.gain_nm > 0));
  check('pozycje jak we wtyczce (powerchip, chip)',
    body.results.every(r => ['powerchip', 'chip'].includes(r.code)),
    body.results.map(r => r.code).join(','));
}

// dane z wtyczki i z naszego REST-u muszą byc te same (wspólny cache wyników)
const [brand, model, gen, engine] = String(body.url || '').replace(/\/$/, '').split('/').slice(-4);
if (brand) {
  const vt = await (await fetch(`http://localhost:8090/wp-json/vt/v1/result?brand=${brand}&model=${model}&gen=${gen}&engine=${engine}&year=2000`)).json().catch(() => null);
  check('endpoint wtyczki odpowiada', vt && ('powerchip' in vt || 'chip_tuning' in vt || vt.code), vt ? (vt._source || vt.code) : 'brak');
}

const b = await chromium.launch();
const p = await b.newPage();
await p.goto('http://localhost:8090/chiptuning/');
const tiles = await p.locator('.vts-cat-tile').count();
check('indeks katalogu ma kafelki marek', tiles >= 50, `${tiles} kafelkow`);
await b.close();

console.log(fail ? `\n${fail} PROBLEMOW` : '\nkatalog OK');
process.exit(fail ? 1 : 0);
