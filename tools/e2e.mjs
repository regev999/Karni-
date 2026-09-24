// End-to-end smoke test of the admin panel and the WhatsApp buttons.
import { chromium } from 'playwright';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

// The live catalogue is data/products.php, JSON behind a one-line PHP guard.
function catalogue() {
  const raw = fs.readFileSync('data/products.php', 'utf8');
  return JSON.parse(raw.startsWith('<?php') ? raw.slice(raw.indexOf('\n') + 1) : raw);
}

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
for (const f of ['data/settings.php', 'data/views.php', 'inc/store.php']) {
  const r = await ap.request.get(`${BASE}/${f}`);
  const body = await r.text();
  ok(!/admin_hash|050-|JSON_GUARD/.test(body), `${f} over HTTP leaks nothing (status ${r.status()}, ${body.length}B)`);
}

// 4. Upload product images; the filename becomes the product name.
const before = catalogue().length;
await page.setInputFiles('input[name="images[]"]', ['/tmp/claude-0/t/KELVIN.jpg', '/tmp/claude-0/t/Brand New Lamp.png']);
await page.click('#imgform button[type=submit]');
await page.waitForURL('**/products.php');
const afterImg = catalogue();
ok(afterImg.length === before + 1, `image upload: existing product matched by name, new one added (${before} -> ${afterImg.length})`);
ok(afterImg.some(p => p.name === 'Brand New Lamp'), 'new product named from the filename');
const kelvin = afterImg.find(p => p.name === 'KELVIN');
ok(kelvin && kelvin.image === 'KELVIN.jpg', 'existing KELVIN photo replaced, not duplicated');

// 5. Upload the spreadsheet.
await page.setInputFiles('input[name=sheet]', '/tmp/claude-0/t/catalog.xlsx');
await page.click('form:has(input[value=sheet]) button[type=submit]');
await page.waitForURL('**/products.php');
const merged = catalogue();
ok(merged[0].name === 'KELVIN' && merged[0].sku === 'FLS3198', 'sheet order drives page order');
ok(merged[0].image === 'KELVIN.jpg', 'sheet merge keeps the photo');
ok(merged.some(p => p.name === 'מנורת בדיקה' && p.price_after === 399), 'Hebrew row imported');
ok(merged.some(p => p.name === 'New Only Sheet' && !p.image), 'sheet-only row added without a photo');
ok(merged.some(p => p.name === 'Romeo Moon' && p.price_before === 4802), 'currency and commas stripped');

// 5b. Saving the table by hand must not drop what the table does not show.
const marked = c => c.filter(r => r.brand).length;
const white = c => c.filter(r => r.ink === 'light').length;
const beforeSave = marked(merged);
const beforeWhite = white(merged);
await page.goto(`${BASE}/admin/products.php`);
await page.click('form:has(textarea) button[type=submit]');
await page.waitForURL('**/products.php');
// PHP drops form fields past max_input_vars without a word, so a catalogue this
// size is exactly where a save starts losing products silently.
ok(catalogue().length === merged.length,
   `a hand save keeps every product (${merged.length})`);
ok(beforeSave > 0 && marked(catalogue()) === beforeSave,
   `a hand save keeps every maker's mark (${beforeSave})`);
ok(beforeWhite > 0 && white(catalogue()) === beforeWhite,
   `and every caption the designer set in white (${beforeWhite})`);

// The caption colour is the one thing about a photo the admin can overrule.
await page.selectOption('tbody tr:first-child select[name$="[ink]"]', 'light');
await page.click('form:has(textarea) button[type=submit]');
await page.waitForURL('**/products.php');
ok(catalogue()[0].ink === 'light', 'the admin can set a caption to white');

// 5c. The site icon is uploaded, served, and removable.
await page.goto(`${BASE}/admin/settings.php`);
ok((await page.locator('link[rel=icon]').getAttribute('href')).includes('assets/img/favicon.svg'),
   'with nothing uploaded the icon is the one the design shipped');
await page.setInputFiles('input[name=favicon]', '/tmp/claude-0/t/icon-test.png');
await page.click('form:has(input[name=favicon]) button[type=submit]');
await page.waitForURL('**/settings.php');
const iconOn = await page.goto(`${BASE}/`);
const iconHref = await page.locator('link[rel=icon]').getAttribute('href');
ok(iconHref.startsWith('uploads/site/favicon.png?v='), `the uploaded icon is served (${iconHref})`);
ok((await page.request.get(`${BASE}/${iconHref}`)).status() === 200, 'and it resolves');
ok(iconOn.status() === 200, 'the page still renders');

await page.goto(`${BASE}/admin/settings.php`);
await page.check('input[name=drop_icon]');
await page.click('form:has(input[name=favicon]) button[type=submit]');
await page.waitForURL('**/settings.php');
await page.goto(`${BASE}/`);
ok((await page.locator('link[rel=icon]').getAttribute('href')).includes('assets/img/favicon.svg'),
   'removing it falls back to the design\'s own');

// 6. With no number set, every button falls back to the phone in the footer.
await page.goto(`${BASE}/`);
ok((await page.locator('.wa--cta').getAttribute('href')).startsWith('tel:'),
   'with no WhatsApp number the buttons dial the phone instead');

// 7. The number comes from the settings, and the links are built from it.
await page.goto(`${BASE}/admin/settings.php`);
await page.fill('input[name=whatsapp]', '050-1234567');
await page.click('form button[type=submit]');
ok((await page.locator('input[name=whatsapp]').inputValue()) === '050-1234567', 'WhatsApp number saved');
ok((await page.locator('code').first().textContent()).includes('wa.me/972501234567'),
   'a local number is read as an international one');

await page.goto(`${BASE}/`);
const cta = decodeURIComponent(await page.locator('.wa--cta').getAttribute('href'));
ok(cta.startsWith('https://wa.me/972501234567?text='), `the CTA goes to WhatsApp (${cta.slice(0, 38)})`);
ok(cta.includes('המכירה מתצוגה'), 'the CTA opens WhatsApp with a message already written');
ok(await page.locator('.wa--float').isVisible(), 'the floating WhatsApp bubble is on the page');
ok((await page.locator('.lform, .foot form, .send').count()) === 0, 'no lead form is left anywhere');

// 8. The product pop-up.
const card = page.locator('.card').first();
const wantName = await card.getAttribute('data-name');
const wantSku = await card.getAttribute('data-sku');
await card.click();
await page.waitForTimeout(500);
ok(await page.locator('#product-modal').isVisible(), 'clicking a card opens the pop-up');
ok((await page.locator('.pm__name').textContent()) === wantName, 'pop-up shows the product name');
ok((await page.locator('.pm__sku').textContent()).includes(wantSku), 'pop-up shows the SKU');
ok((await page.locator('.pm__save').textContent()).includes('%'), 'pop-up shows the saving');
ok(await page.evaluate(() => document.documentElement.classList.contains('is-locked')), 'page scroll is locked');

const pmHref = decodeURIComponent(await page.locator('.wa--pm').getAttribute('href'));
ok(pmHref.startsWith('https://wa.me/972501234567?text='), 'the pop-up button goes to WhatsApp');
ok(pmHref.includes(wantName), 'the message names the product the visitor opened');
ok(pmHref.includes(wantSku), 'the message carries the SKU, so nobody has to type it');
ok((await page.locator('.pm__fine').textContent()).includes(wantSku), 'the pop-up says the SKU is in the message');

await page.keyboard.press('Escape');
await page.waitForTimeout(400);
ok(!(await page.locator('#product-modal').isVisible()), 'Escape closes the pop-up');
ok(!(await page.evaluate(() => document.documentElement.classList.contains('is-locked'))), 'scroll lock released');

await card.click();
await page.waitForTimeout(400);
await page.mouse.click(6, 6);                     // the backdrop
await page.waitForTimeout(400);
ok(!(await page.locator('#product-modal').isVisible()), 'a click on the backdrop closes it');

// 9. Opening a product counts once per visit, and the count reaches the admin.
const slug = await card.getAttribute('data-slug');
await page.waitForTimeout(600);
const seen = () => {
  const raw = fs.readFileSync('data/views.php', 'utf8');
  return JSON.parse(raw.replace(/^<\?php[^\n]*\n/, ''));
};
ok((seen()[slug] || {}).views >= 1, 'opening a product is counted');
const once = seen()[slug].views;
await card.click();
await page.waitForTimeout(600);
ok(seen()[slug].views === once, 'reopening the same product in one visit does not count twice');
await page.keyboard.press('Escape');

await page.goto(`${BASE}/admin/products.php?sort=views`);
const top = (await page.locator('tbody tr').first().locator('.views').textContent()).trim();
ok(top === String(once), `sorted by views, the admin puts the opened product on top (${top})`);
ok((await page.locator('tbody tr').first().locator('input[name$="[name]"]').inputValue()) === wantName,
   'and it is the product that was opened');

// 10. Password reset. The mail cannot be read from here, so the token is minted
// through the same code the mail would have carried.
// A reset has to have somewhere to go, so give the site an address first.
await page.goto(`${BASE}/admin/settings.php`);
await page.fill('input[name=admin_emails]', 'owner@example.com');
await page.click('form button[type=submit]');
ok((await page.locator('input[name=admin_emails]').inputValue()) === 'owner@example.com',
   'reset address saved');

await page.goto(`${BASE}/admin/logout.php`);
await page.goto(`${BASE}/admin/`);
ok(await page.locator('a[href="forgot.php"]').isVisible(), 'login screen offers a reset');
await page.click('a[href="forgot.php"]');
await page.click('button[type=submit]');
ok(await page.locator('text=קישור לבחירת סיסמה חדשה').isVisible(), 'reset request is acknowledged');

fs.rmSync('data/.reset_stamp', { force: true });          // skip the ten-minute pause
const token = execFileSync('php', ['-r',
  'require "inc/bootstrap.php"; echo reset_start();']).toString().trim();
ok(/^[0-9a-f]{64}$/.test(token), 'a one-time token is issued');

await page.goto(`${BASE}/admin/reset.php?t=deadbeef`);
ok(await page.locator('text=הקישור אינו תקף').isVisible(), 'a wrong token is refused');

await page.goto(`${BASE}/admin/reset.php?t=${token}`);
await page.fill('input[name=password]', 'short');
await page.fill('input[name=password2]', 'short');
await page.click('button[type=submit]');
ok(await page.locator('text=לפחות 8 תווים').isVisible(), 'reset rejects a short password');
await page.fill('input[name=password]', 'karni-reset-2026');
await page.fill('input[name=password2]', 'karni-reset-2026');
await page.click('button[type=submit]');
ok(page.url().includes('products.php'), 'reset signs the admin straight in');

await page.goto(`${BASE}/admin/reset.php?t=${token}`);
ok(await page.locator('text=הקישור אינו תקף').isVisible(), 'the token cannot be used twice');

await page.goto(`${BASE}/admin/logout.php`);
await page.fill('input[name=password]', 'karni-test-2026');
await page.click('button[type=submit]');
ok(await page.locator('text=סיסמה שגויה').isVisible(), 'the old password no longer works');
await page.fill('input[name=password]', 'karni-reset-2026');
await page.click('button[type=submit]');
ok(page.url().includes('products.php'), 'the new password works');

// 11. Brute-force brake. Left to last, because it locks this address out.
fs.rmSync('data/logins.php', { force: true });
await page.goto(`${BASE}/admin/logout.php`);
for (let i = 0; i < 3; i++) {
  await page.fill('input[name=password]', `wrong-${i}`);
  await page.click('button[type=submit]');
}
ok(await page.locator('text=סיסמה שגויה').isVisible(), 'the first few wrong guesses are just wrong');
await page.fill('input[name=password]', 'wrong-again');
await page.click('button[type=submit]');
ok(await page.locator('text=יותר מדי ניסיונות').isVisible(), 'past that the address is made to wait');

// And the wait is real: the right password is refused while it runs.
await page.fill('input[name=password]', 'karni-reset-2026');
await page.click('button[type=submit]');
ok(!page.url().includes('products.php'), 'even the right password waits its turn');

const locks = JSON.parse(fs.readFileSync('data/logins.php', 'utf8').split('\n').slice(1).join('\n'));
const row = Object.values(locks)[0];
// Four: the right password tried during the wait is turned away before it is
// checked, so waiting it out does not make the wait longer.
ok(row.fails === 4 && row.until > Math.floor(Date.now() / 1000), 'the failures are counted', `${row.fails} fails`);

fs.rmSync('data/logins.php', { force: true });   // and clearing it lets the owner back in
await page.fill('input[name=password]', 'karni-reset-2026');
await page.click('button[type=submit]');
ok(page.url().includes('products.php'), 'once the wait is over the password works again');

await browser.close();
console.log(fail.length ? `\n${fail.length} FAILED: ${fail.join(' | ')}` : '\nall checks passed');
process.exit(fail.length ? 1 : 0);
