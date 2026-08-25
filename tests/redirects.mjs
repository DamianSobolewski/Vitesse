import fs from 'fs';
const BASE = 'http://localhost:8090';

// Stare adresy .php z lustra + próbka kluczy ?auto= ze starego serwisu.
// Po przejściu na dane V-techa klucze nie są już w katalogu — źródłem prawdy
// jest mapa wygenerowana przez tools/scrape/map-legacy.py.
const files = fs.readFileSync('content/redirects/legacy-urls.txt', 'utf8').trim().split('\n');
const map = JSON.parse(fs.readFileSync('content/redirects/legacy-catalog.json', 'utf8'));
const keys = Object.keys(map);

const pick = (arr, n) => arr.filter((_, i) => i % Math.max(1, Math.floor(arr.length / n)) === 0).slice(0, n);
// próbka z każdego poziomu: marka (bez _), model, generacja, silnik (najwięcej podkreśleń)
const byDepth = (d) => keys.filter(k => k.split('_').length === d);
const catalog = [
  ...pick(byDepth(1), 6),
  ...pick(byDepth(2), 8),
  ...pick(byDepth(3), 10),
  ...pick(keys.filter(k => k.split('_').length >= 4), 16),
  // klucz, którego w mapie nie ma — musi trafić na przodka, nie na 404
  'Ford_Focus_III_nieistniejacy-silnik-999kW',
].filter(Boolean).map(k => '/chiptuning_lodz.php?auto=' + encodeURIComponent(k));

const urls = [...files.map(f => '/' + f), ...catalog];
let ok = 0, chains = 0, broken = [];

for (const u of urls) {
  const r1 = await fetch(BASE + u, { redirect: 'manual' });
  if (r1.status !== 301) { broken.push(`${u} -> status ${r1.status}`); continue; }

  const loc = r1.headers.get('location');
  const r2 = await fetch(loc, { redirect: 'manual' });
  if (r2.status === 301 || r2.status === 302) { chains++; broken.push(`${u} -> LANCUCH -> ${loc}`); continue; }
  if (r2.status !== 200) { broken.push(`${u} -> ${loc} -> ${r2.status}`); continue; }
  ok++;
}

console.log(`sprawdzono: ${urls.length}  (${files.length} plikow .php + ${catalog.length} adresow katalogu)`);
console.log(`301 w jednym skoku na stronę 200: ${ok}`);
console.log(`lancuchy przekierowan: ${chains}`);
if (broken.length) { console.log('\nPROBLEMY:'); broken.slice(0, 15).forEach(b => console.log('  ' + b)); }
else console.log('\nwszystkie adresy OK');
