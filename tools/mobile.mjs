import { chromium } from 'playwright';
const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-proxy-server', '--disable-component-update', '--no-pings', '--disable-sync'],
});
for (const [w, h, name] of [[390, 844, 'phone'], [768, 1024, 'tablet'], [1024, 800, 'laptop']]) {
  const tag = name;
  // hasTouch makes `hover: none` / `pointer: coarse` match, as on a real phone.
  const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1,
                                      hasTouch: tag !== 'laptop' });
  await page.goto(process.env.SITE_URL || 'http://127.0.0.1:8088/', { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(700);
  const over = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  for (const [tag, y] of [['top', 0], ['steps', null], ['grid', null], ['form', null]]) {
    if (tag === 'top') { await page.evaluate(() => window.scrollTo(0, 0)); }
    else { await page.evaluate(s => document.querySelector(s).scrollIntoView(), tag === 'steps' ? '.steps' : tag === 'grid' ? '.catalog' : '.foot'); }
    await page.waitForTimeout(450);
    await page.screenshot({ path: `tools/out/m-${name}-${tag}.png` });
  }
  console.log(`${name.padEnd(7)} ${w}x${h}  horizontal overflow: ${over}px`);
  await page.close();
}
await browser.close();
