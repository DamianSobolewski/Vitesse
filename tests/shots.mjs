import { chromium } from 'playwright';
const OUT='/tmp/claude-1000/-home-damian-Workspace-Vitesse/bdf3180e-44cb-47e5-a8c4-6c4d5405a949/scratchpad';
const b = await chromium.launch();
// desktop, po sekwencji
const p = await b.newPage({ viewport:{width:1400,height:950}, deviceScaleFactor:1.25 });
await p.goto('http://localhost:8090/', {waitUntil:'networkidle'});
await p.waitForTimeout(2400);
await p.screenshot({path:`${OUT}/fin-desktop.png`});
// mobile
const m = await b.newPage({ viewport:{width:390,height:844}, deviceScaleFactor:2 });
await m.goto('http://localhost:8090/', {waitUntil:'networkidle'});
await m.waitForTimeout(2400);
await m.screenshot({path:`${OUT}/fin-mobile.png`});
console.log('mobile uzywa:', await m.evaluate(()=>getComputedStyle(document.querySelector('.vts-hero__bg')).backgroundImage.match(/hero[^']*\.webp/)?.[0]));
await b.close();
