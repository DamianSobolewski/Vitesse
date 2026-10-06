import os from 'node:os';
import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await b.newPage({ viewport: { width: 1400, height: 950 } });
await p.goto('http://localhost:8090/wp-login.php');
await p.fill('#user_login', process.env.U); await p.fill('#user_pass', process.env.P);
await p.click('#wp-submit'); await p.waitForLoadState('networkidle');
console.log('po zalogowaniu:', p.url().replace('http://localhost:8090',''));
const menu = await p.locator('#adminmenu > li > a').allTextContents();
console.log('pozycje menu:', menu.map(m => m.split('\n')[0].trim()).filter(Boolean).join(' | '));
for (const t of ['/wp-admin/plugins.php','/wp-admin/themes.php','/wp-admin/options-general.php','/wp-admin/edit.php?post_type=page']) {
  await p.goto('http://localhost:8090' + t);
  console.log(t.padEnd(38), '->', p.url().replace('http://localhost:8090',''));
}
await p.goto('http://localhost:8090/wp-admin/post-new.php?post_type=vts_dyno');
await p.waitForLoadState('domcontentloaded');
await p.waitForTimeout(1200);
await p.screenshot({ path: `${process.env.VTS_OUT || os.tmpdir()}/f-panel.png`, fullPage: true });
console.log('formularz pomiaru:', await p.locator('.vts-mb__f').count(), 'pol +', await p.locator('.vts-mb__consent').count(), 'zgoda');
await b.close();
