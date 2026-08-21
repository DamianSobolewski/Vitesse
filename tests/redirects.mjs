import fs from 'fs';
const BASE = 'http://localhost:8090';

// stare adresy .php z lustra + probka realnych kluczy katalogu
const files = fs.readFileSync('content/redirects/legacy-urls.txt', 'utf8').trim().split('\n');
const engines = JSON.parse(fs.readFileSync('content/catalog/engines.json', 'utf8'));
const gens    = JSON.parse(fs.readFileSync('content/catalog/generations.json', 'utf8'));
const makes   = JSON.parse(fs.readFileSync('content/catalog/makes.json', 'utf8'));

const pick = (arr, n) => arr.filter((_, i) => i % Math.max(1, Math.floor(arr.length / n)) === 0).slice(0, n);
const catalog = [
  ...pick(makes, 8).map(m => m.legacy_key),
  ...pick(gens, 12).map(g => g.legacy_key),
  ...pick(engines, 20).map(e => e.legacy_key),
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
