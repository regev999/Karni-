// Open the pop-up in the real page and capture it, desktop and phone.
import { chromium } from 'playwright';
import fs from 'node:fs';
const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-proxy-server', '--disable-component-update', '--no-pings', '--disable-sync'],
});
const URL = process.env.SITE_URL || 'http://127.0.0.1:8088/';

for (const [w, h, tag] of [[1440, 900, 'desk'], [390, 844, 'phone']]) {
  const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
  page.setDefaultTimeout(45000);
  fs.rmSync('data/.rate_' + '', { force: true });
  for (const f of fs.readdirSync('data')) if (f.startsWith('.rate_')) fs.rmSync('data/' + f);
  await page.goto(URL, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  await page.evaluate(() => document.querySelector('.card').scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(700);
  await page.screenshot({ path: `tools/out/pm-${tag}-grid.png` });

  await page.evaluate(() => document.querySelector('.card').click());
  await page.waitForTimeout(700);
  await page.screenshot({ path: `tools/out/pm-${tag}-open.png` });

  await page.fill('.pm [name=name]', 'ישראל ישראלי');
  await page.fill('.pm [name=phone]', '050-1112233');
  await page.click('.pm__send');
  await page.waitForTimeout(900);
  await page.screenshot({ path: `tools/out/pm-${tag}-done.png` });
  console.log(`${tag}: done panel visible = ${await page.locator('.pm__done').isVisible()}`);
  await page.close();
}
await browser.close();
