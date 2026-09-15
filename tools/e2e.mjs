// End-to-end smoke test of the admin panel and the lead form.
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = process.env.SITE_URL || 'http://127.0.0.1:8088';
const browser = await chromium.launch({
  executablePath: process.env.CHROME_BIN || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  // This sandbox has no outbound net, and Chromium's own phone-home retries
  // otherwise keep the network busy forever.
  args: ['--disable-component-update', '--disable-domain-reliability', '--no-pings',
         '--disable-sync', '--safebrowsing-disable-auto-update', '--disable-client-side-phishing-detection'],
});
const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
const fail = [];
const ok = (cond, label) => { console.log(`${cond ? ' ok ' : 'FAIL'}  ${label}`); if (!cond) fail.push(label); };

// 1. First run asks to set a password.
await page.goto(`${BASE}/admin/`);
ok(await page.locator('text=ברוכים הבאים').isVisible(), 'first run shows password setup');
await page.fill('input[name=password]', 'short');
await page.fill('input[name=password2]', 'short');
await page.click('button[type=submit]');
ok(await page.locator('text=לפחות 8 תווים').isVisible(), 'rejects a short password');

await page.fill('input[name=password]', 'karni-test-2026');
await page.fill('input[name=password2]', 'karni-test-2026');
await page.click('button[type=submit]');
ok(page.url().includes('/admin/'), 'password set, back to login');

// 2. Wrong then right password.
await page.fill('input[name=password]', 'nope-nope-nope');
await page.click('button[type=submit]');
ok(await page.locator('text=סיסמה שגויה').isVisible(), 'rejects a wrong password');
await page.fill('input[name=password]', 'karni-test-2026');
await page.click('button[type=submit]');
await page.waitForURL('**/products.php');
ok(true, 'signs in');

// 3. Admin pages are closed to visitors who are not signed in.
const anon = await browser.newContext();
const ap = await anon.newPage();
await ap.goto(`${BASE}/admin/products.php`);
ok(ap.url().endsWith('/admin/index.php'), 'products.php redirects anonymous visitors');
for (const f of ['data/settings.php', 'data/leads.php', 'inc/store.php']) {
  const r = await ap.request.get(`${BASE}/${f}`);
  const body = await r.text();
  ok(!/admin_hash|050-|JSON_GUARD/.test(body), `${f} over HTTP leaks nothing (status ${r.status()}, ${body.length}B)`);
}

// 4. Upload product images; the filename becomes the product name.
const before = JSON.parse(fs.readFileSync('data/products.json', 'utf8')).length;
await page.setInputFiles('input[name="images[]"]', ['/tmp/claude-0/t/KELVIN.jpg', '/tmp/claude-0/t/Brand New Lamp.png']);
await page.click('#imgform button[type=submit]');
await page.waitForURL('**/products.php');
const afterImg = JSON.parse(fs.readFileSync('data/products.json', 'utf8'));
ok(afterImg.length === before + 1, `image upload: existing product matched by name, new one added (${before} -> ${afterImg.length})`);
ok(afterImg.some(p => p.name === 'Brand New Lamp'), 'new product named from the filename');
const kelvin = afterImg.find(p => p.name === 'KELVIN');
ok(kelvin && kelvin.image === 'KELVIN.jpg', 'existing KELVIN photo replaced, not duplicated');

// 5. Upload the spreadsheet.
await page.setInputFiles('input[name=sheet]', '/tmp/claude-0/t/catalog.xlsx');
await page.click('form:has(input[value=sheet]) button[type=submit]');
await page.waitForURL('**/products.php');
const merged = JSON.parse(fs.readFileSync('data/products.json', 'utf8'));
ok(merged[0].name === 'KELVIN' && merged[0].sku === 'FLS3198', 'sheet order drives page order');
ok(merged[0].image === 'KELVIN.jpg', 'sheet merge keeps the photo');
ok(merged.some(p => p.name === 'מנורת בדיקה' && p.price_after === 399), 'Hebrew row imported');
ok(merged.some(p => p.name === 'New Only Sheet' && !p.image), 'sheet-only row added without a photo');
ok(merged.some(p => p.name === 'Romeo Moon' && p.price_before === 4802), 'currency and commas stripped');

// 6. Lead form on the public page.
await page.goto(`${BASE}/`);
await page.click('.send');
await page.waitForTimeout(600);
ok((await page.locator('.lform__msg').textContent()).includes('שם'), 'empty form is rejected');
await page.fill('[name=name]', 'ישראל ישראלי');
await page.fill('[name=phone]', '050-1234567');
await page.fill('[name=email]', 'test@example.com');
await page.fill('[name=sku]', 'FLS3198');
await page.click('.send');
await page.waitForTimeout(800);
ok((await page.locator('.lform__msg').textContent()).includes('תודה'), 'valid lead accepted');
const leads = JSON.parse(fs.readFileSync('data/leads.php', 'utf8').replace(/^<\?php[^\n]*\n/, ''));
ok(leads.length === 1 && leads[0].phone === '050-1234567', 'lead stored');

// 7. Rate limit.
await page.fill('[name=name]', 'שוב');
await page.fill('[name=phone]', '050-7654321');
await page.click('.send');
await page.waitForTimeout(600);
ok((await page.locator('.lform__msg').textContent()).includes('רגע'), 'second submit is rate limited');

// 8. Clicking a product carries its SKU into the form.
await page.evaluate(() => document.querySelector('.card').click());
await page.waitForTimeout(700);
ok((await page.inputValue('[name=sku]')) !== '', 'clicking a card fills the SKU field');

// 9. Leads page and CSV export.
await page.goto(`${BASE}/admin/leads.php`);
ok(await page.locator('text=ישראל ישראלי').isVisible(), 'lead shows in the admin table');
const csv = await (await page.request.get(`${BASE}/admin/leads.php?export=csv`)).body();
ok(csv[0] === 0xEF && csv.toString('utf8').includes('ישראל ישראלי'), 'CSV export has a BOM and the lead');

await browser.close();
console.log(fail.length ? `\n${fail.length} FAILED: ${fail.join(' | ')}` : '\nall checks passed');
process.exit(fail.length ? 1 : 0);
