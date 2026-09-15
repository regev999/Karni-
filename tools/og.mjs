// Render the 1200x630 share card that WhatsApp, Facebook and X show when the
// page is pasted into a chat. Run: node tools/og.mjs
import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import fs from 'node:fs';
import path from 'node:path';

const OUT = 'assets/img/og.jpg';

const browser = await chromium.launch({
  executablePath: process.env.CHROME_BIN || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-proxy-server', '--disable-component-update', '--no-pings', '--disable-sync'],
});
const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 });
await page.goto(pathToFileURL(path.resolve('tools/og.html')).href, { waitUntil: 'load' });
await page.evaluate(() => document.fonts.ready);
await page.waitForTimeout(250);

// A share card is fetched by a chat client on a phone, so weight matters more
// than the last few percent of quality.
await page.screenshot({ path: OUT, type: 'jpeg', quality: 82 });
await browser.close();

const kb = fs.statSync(OUT).size / 1024;
console.log(`${OUT}  1200x630  ${kb.toFixed(0)} KB`);
if (kb > 300) console.log('warning: over 300 KB — some chat clients skip the preview');
