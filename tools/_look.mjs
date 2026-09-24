import { chromium } from 'playwright';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-proxy-server','--disable-component-update','--no-pings','--disable-sync'] });
const p = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
await p.goto('http://127.0.0.1:8088/', { waitUntil: 'load' });
await p.evaluate(() => document.fonts.ready);
await p.evaluate(() => { document.querySelectorAll('img[loading="lazy"]').forEach(i => i.loading='eager'); });
for (const [sku, tag] of [['FLS3198','top'], ['TOS05973','dark']]) {
  await p.evaluate(s => [...document.querySelectorAll('.card')].find(c => c.dataset.sku === s)
                          .scrollIntoView({ block: 'center' }), sku);
  await p.waitForTimeout(1400);
  await p.screenshot({ path: `tools/out/look-${tag}.png` });
}
console.log('ok');
await b.close();
