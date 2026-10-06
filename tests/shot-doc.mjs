import os from 'node:os';
import { chromium } from 'playwright';
const b = await chromium.launch();
for (const scheme of ['light', 'dark']) {
  const p = await b.newPage({ viewport: { width: 1240, height: 1050 }, colorScheme: scheme });
  await p.goto('file:///home/damian/Workspace/Vitesse/design/kierunki-wizualne.html', { waitUntil: 'networkidle' });
  await p.screenshot({ path: `${process.env.VTS_OUT || os.tmpdir()}/doc-${scheme}.png` });
  const bg = await p.evaluate(() => getComputedStyle(document.body).backgroundColor);
  console.log(scheme, 'tlo body:', bg);
  await p.close();
}
await b.close();
