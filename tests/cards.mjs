import { chromium } from 'playwright';
import fs from 'fs';

// Strażnik pustych kafelków. wpautop potrafi rozbić <a class="vts-card"> na klony,
// które są puste, ale klikalne. W źródle tego nie widać — trzeba sprawdzać w DOM.
const B = 'http://localhost:8090';
const manifest = JSON.parse(fs.readFileSync('content/pages.json', 'utf8'));

// odtwarzamy adresy z manifestu (slug + rodzic)
const pages = manifest.pages;
const path = (slug) => {
  const parts = [slug];
  let cur = pages[slug];
  while (cur && cur.parent) { parts.unshift(cur.parent); cur = pages[cur.parent]; }
  return pages[slug].front_page ? '/' : '/' + parts.join('/') + '/';
};

const urls = [...new Set(Object.keys(pages).filter(s => !pages[s].hidden).map(path))];
const b = await chromium.launch();
const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
let bad = 0, checked = 0;

for (const u of urls) {
  const res = await p.goto(B + u, { waitUntil: 'domcontentloaded' });
  if (!res || res.status() !== 200) { console.log(`POMINIETO ${u} (status ${res?.status()})`); continue; }
  checked++;
  const r = await p.evaluate(() => {
    const all = [...document.querySelectorAll('.vts-card, .vts-cat-tile, .vts-dyno__card')];
    const empty = all.filter(el => el.textContent.trim() === '');
    return {
      total: all.length,
      empty: empty.length,
      clickable: empty.filter(el => el.tagName === 'A' && el.getAttribute('href')).length,
    };
  });
  if (r.empty > 0) {
    bad++;
    console.log(`BLAD  ${u.padEnd(48)} kart:${String(r.total).padStart(3)}  PUSTYCH:${r.empty}  klikalnych:${r.clickable}`);
  }
}

// Dodatkowo, w surowej odpowiedzi: karty ZAWIERAJĄ akapity i to jest w porządku.
// Uszkodzeniem jest `</p>` doklejone tuż ZA otwarciem kotwicy albo pusty `<p>`
// tuż PRZED jej zamknięciem — tak wygląda ślad po wpautop.
for (const u of ['/', '/podnoszenie-mocy/', '/podnoszenie-mocy/chip-tuning/']) {
  const html = await (await fetch(B + u)).text();
  const afterOpen = (html.match(/<a class="vts-card"[^>]*>\s*<\/p>/g) || []).length;
  const beforeClose = (html.match(/<p>\s*<\/a>/g) || []).length;
  if (afterOpen || beforeClose) {
    bad++;
    console.log(`BLAD  ${u} — slad po wpautop: ${afterOpen}x </p> po <a>, ${beforeClose}x <p></a>`);
  }
}

console.log(bad ? `\n${bad} PROBLEMOW na ${checked} stronach` : `\nsprawdzono ${checked} stron — zero pustych kafelków`);
await b.close();
process.exit(bad ? 1 : 0);
