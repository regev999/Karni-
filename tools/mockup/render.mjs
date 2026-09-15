// Render each mockup sheet to a PNG at 2x. Element screenshots mis-clip on an
// RTL document, so each sheet is isolated and captured as a full page instead.
import { chromium } from 'playwright';

const IDS = ['p1', 'p2', 'p3', 'p4', 'p5'];
const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-proxy-server', '--disable-component-update', '--no-pings', '--disable-sync'],
});

for (const id of IDS) {
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
  page.setDefaultTimeout(60000);
  await page.goto('http://127.0.0.1:8088/tools/mockup/modal.html', { waitUntil: 'load' });
  await page.addStyleTag({ content:
    `.sheet { display: none !important; }
     #${id} { display: block !important; margin: 0 !important; }
     body { background: #fff !important; }` });
  await page.evaluate(() => document.fonts.ready);
  await page.evaluate(async () => {
    await Promise.all([...document.images].map(i => i.complete && i.naturalWidth
      ? Promise.resolve()
      : new Promise(r => { i.addEventListener('load', r, { once: true });
                           i.addEventListener('error', r, { once: true }); setTimeout(r, 4000); })));
  });
  await page.waitForTimeout(500);
  // Shrink the viewport first, or fullPage pads short sheets out to 1000px.
  await page.setViewportSize({ width: 1440, height: 320 });
  await page.waitForTimeout(150);
  const h = await page.evaluate(() => document.documentElement.scrollHeight);
  await page.screenshot({ path: `tools/out/mock-${id}.png`, fullPage: true });
  console.log(`${id}  1440x${h}`);
  await page.close();
}
await browser.close();
