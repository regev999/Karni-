// Open the pop-up in the real page and capture it, desktop and phone.
import { chromium } from 'playwright';
const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-proxy-server', '--disable-component-update', '--no-pings', '--disable-sync'],
});
const URL = process.env.SITE_URL || 'http://127.0.0.1:8088/';

for (const [w, h, tag] of [[1440, 900, 'desk'], [390, 844, 'phone']]) {
  const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
  page.setDefaultTimeout(45000);
  await page.goto(URL, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  await page.evaluate(() => document.querySelector('.card').scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(700);
  await page.screenshot({ path: `tools/out/pm-${tag}-grid.png` });

  await page.evaluate(() => document.querySelector('.card').click());
  await page.waitForTimeout(700);
  await page.screenshot({ path: `tools/out/pm-${tag}-open.png` });

  const wa = decodeURIComponent(await page.locator('.wa--pm').getAttribute('href'));
  console.log(`${tag}: ${wa.slice(0, 96)}`);
  await page.close();
}
await browser.close();
