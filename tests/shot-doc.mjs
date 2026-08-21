import { chromium } from 'playwright';
const b = await chromium.launch();
for (const scheme of ['light', 'dark']) {
  const p = await b.newPage({ viewport: { width: 1240, height: 1050 }, colorScheme: scheme });
  await p.goto('file:///home/damian/Workspace/Vitesse/design/kierunki-wizualne.html', { waitUntil: 'networkidle' });
  await p.screenshot({ path: `/tmp/claude-1000/-home-damian-Workspace-Vitesse/bdf3180e-44cb-47e5-a8c4-6c4d5405a949/scratchpad/doc-${scheme}.png` });
  const bg = await p.evaluate(() => getComputedStyle(document.body).backgroundColor);
  console.log(scheme, 'tlo body:', bg);
  await p.close();
}
await b.close();
