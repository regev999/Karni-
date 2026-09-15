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

// 6. Footer lead form.
await page.goto(`${BASE}/`);
await page.click('.foot .send');
await page.waitForTimeout(600);
ok((await page.locator('.foot .lform__msg').textContent()).includes('שם'), 'empty footer form is rejected');
await page.fill('.foot [name=name]', 'ישראל ישראלי');
await page.fill('.foot [name=phone]', '050-1234567');
await page.fill('.foot [name=email]', 'test@example.com');
await page.fill('.foot [name=sku]', 'FLS3198');
await page.click('.foot .send');
await page.waitForTimeout(800);
ok((await page.locator('.foot .lform__msg').textContent()).includes('תודה'), 'valid lead accepted');
const readLeads = () => JSON.parse(fs.readFileSync('data/leads.php', 'utf8').replace(/^<\?php[^\n]*\n/, ''));
let leads = readLeads();
ok(leads.length === 1 && leads[0].phone === '050-1234567', 'lead stored');
ok(leads[0].source === 'form', 'footer lead marked as coming from the form');

// 7. Rate limit.
await page.fill('.foot [name=name]', 'שוב');
await page.fill('.foot [name=phone]', '050-7654321');
await page.click('.foot .send');
await page.waitForTimeout(600);
ok((await page.locator('.foot .lform__msg').textContent()).includes('רגע'), 'second submit is rate limited');

// 8. The product pop-up.
for (const f of fs.readdirSync('data')) if (f.startsWith('.rate_')) fs.rmSync('data/' + f);
await page.reload({ waitUntil: 'load' });
const card = page.locator('.card').first();
const wantName = await card.getAttribute('data-name');
const wantSku = await card.getAttribute('data-sku');
await card.click();
await page.waitForTimeout(500);
ok(await page.locator('#product-modal').isVisible(), 'clicking a card opens the pop-up');
ok((await page.locator('.pm__name').textContent()) === wantName, 'pop-up shows the product name');
ok((await page.locator('.pm__sku').textContent()).includes(wantSku), 'pop-up shows the SKU');
ok((await page.locator('.pm__save').textContent()).includes('%'), 'pop-up shows the saving');
ok((await page.inputValue('.pm [name=sku]')) === wantSku, 'SKU is carried into the pop-up form');
ok(await page.evaluate(() => document.documentElement.classList.contains('is-locked')), 'page scroll is locked');

await page.keyboard.press('Escape');
await page.waitForTimeout(400);
ok(!(await page.locator('#product-modal').isVisible()), 'Escape closes the pop-up');
ok(!(await page.evaluate(() => document.documentElement.classList.contains('is-locked'))), 'scroll lock released');

await card.click();
await page.waitForTimeout(400);
await page.mouse.click(6, 6);                     // the backdrop
await page.waitForTimeout(400);
ok(!(await page.locator('#product-modal').isVisible()), 'a click on the backdrop closes it');

await card.click();
await page.waitForTimeout(400);
await page.click('.pm__send');
await page.waitForTimeout(600);
ok(await page.locator('.pm .pf.is-bad').first().isVisible(), 'pop-up form validates');
await page.fill('.pm [name=name]', 'דנה לוי');
await page.fill('.pm [name=phone]', '052-9876543');
await page.click('.pm__send');
await page.waitForTimeout(900);
ok(await page.locator('.pm__done').isVisible(), 'pop-up shows the thank-you panel');
leads = readLeads();
const popup = leads[leads.length - 1];
ok(popup.source === 'popup' && popup.product === wantName && popup.sku === wantSku,
   `pop-up lead carries product and source (${popup.product} / ${popup.source})`);

// 9. Leads page and CSV export.
await page.goto(`${BASE}/admin/leads.php`);
ok(await page.locator('text=ישראל ישראלי').isVisible(), 'lead shows in the admin table');
ok(await page.locator('text=חלון מוצר').first().isVisible(), 'admin shows the pop-up as the lead source');
const csv = await (await page.request.get(`${BASE}/admin/leads.php?export=csv`)).body();
ok(csv[0] === 0xEF && csv.toString('utf8').includes('ישראל ישראלי'), 'CSV export has a BOM and the lead');
ok(csv.toString('utf8').includes(wantName), 'CSV export includes the product name');

await browser.close();
console.log(fail.length ? `\n${fail.length} FAILED: ${fail.join(' | ')}` : '\nall checks passed');
process.exit(fail.length ? 1 : 0);
