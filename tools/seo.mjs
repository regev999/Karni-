// What a crawler and a chat client see. Run: node tools/seo.mjs
import { chromium } from 'playwright';

const BASE = (process.env.SITE_URL || 'http://127.0.0.1:8088/').replace(/\/$/, '');
let failed = 0;

function ok(cond, label, detail = '') {
  if (!cond) failed++;
  console.log(` ${cond ? 'ok  ' : 'FAIL'} ${label}${detail ? `  [${detail}]` : ''}`);
}

async function get(path) {
  const res = await fetch(BASE + path, { redirect: 'follow' });
  return { status: res.status, body: await res.text(), headers: res.headers };
}

function head(html, re) {
  const m = html.match(re);
  return m ? m[1] : '';
}

function jsonld(html) {
  const m = html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/);
  return m ? JSON.parse(m[1]) : null;
}

const node = (graph, type) =>
  graph.find(n => (Array.isArray(n['@type']) ? n['@type'] : [n['@type']]).includes(type));

/* ------------------------------------------------------------- the list -- */

const home = await get('/');
ok(home.status === 200, 'home responds', `status=${home.status}`);

const canonical = head(home.body, /<link rel="canonical" href="([^"]+)"/);
ok(canonical === BASE + '/', 'home canonical is the bare address', canonical);
ok(/<html lang="he" dir="rtl">/.test(home.body), 'lang and direction declared');
ok((home.body.match(/<h1[\s>]/g) || []).length === 1, 'exactly one h1');
ok(home.body.includes('<main>'), 'main landmark');
ok(/<meta name="robots" content="index, follow/.test(home.body), 'indexable');

const cards = [...home.body.matchAll(/<a class="card" href="\?p=([^"]+)"/g)].map(m => m[1]);
ok(cards.length > 100, 'every product is a crawlable link', `${cards.length} links`);
ok(new Set(cards).size === cards.length, 'product addresses are unique');
ok((home.body.match(/<h3 class="card__name">/g) || []).length === cards.length,
   'every product name is a heading');

const g = jsonld(home.body);
ok(!!g && Array.isArray(g['@graph']), 'home carries a schema.org graph');
const graph = g['@graph'];
ok(!!node(graph, 'WebSite'), 'WebSite node');
const store = node(graph, 'HomeGoodsStore');
ok(!!store && !!store.telephone && !!store.address?.streetAddress,
   'business node with phone and address', store?.address?.addressLocality);
ok(!!node(graph, 'CollectionPage'), 'CollectionPage node');
const list = node(graph, 'ItemList');
ok(list?.itemListElement?.length === cards.length, 'ItemList covers the catalogue',
   `${list?.itemListElement?.length}`);
ok(list?.itemListElement?.every(i => i.url.startsWith(BASE)), 'ItemList links are absolute');

/* ------------------------------------------------------ share and robots -- */

const og = head(home.body, /<meta property="og:image" content="([^"]+)"/);
ok(og.startsWith(BASE), 'og:image is absolute', og);
const ogRes = await fetch(og);
ok(ogRes.status === 200, 'og:image resolves', `status=${ogRes.status}`);
const ogBytes = (await ogRes.arrayBuffer()).byteLength;
ok(ogBytes > 10000 && ogBytes < 300000, 'og:image is a sane size for a chat preview',
   `${Math.round(ogBytes / 1024)} KB`);
ok(head(home.body, /<meta name="twitter:card" content="([^"]+)"/) === 'summary_large_image',
   'twitter card');

const robots = await get('/robots.txt');
ok(robots.status === 200, 'robots.txt served', `status=${robots.status}`);
ok(robots.body.includes('Sitemap: ' + BASE + '/sitemap.xml'), 'robots points at the sitemap');
ok(robots.body.includes('Disallow: /admin/'), 'admin kept out of the index');

const sitemap = await get('/sitemap.xml');
ok(sitemap.status === 200, 'sitemap served', `status=${sitemap.status}`);
const locs = [...sitemap.body.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1]);
ok(locs.length === cards.length + 1, 'sitemap lists the page and every product', `${locs.length}`);
ok(new Set(locs).size === locs.length, 'no duplicate addresses');
ok(locs.every(l => l.startsWith(BASE)), 'sitemap addresses are absolute');

/* ---------------------------------------------------------- one product -- */

const slug = cards[0];
const p = await get('/?p=' + slug);
ok(p.status === 200, 'product address responds', `status=${p.status}`);
const pTitle = head(p.body, /<title>([^<]+)<\/title>/);
const hTitle = head(home.body, /<title>([^<]+)<\/title>/);
ok(pTitle !== hTitle && pTitle.length > 10, 'product has a title of its own', pTitle);
ok(head(p.body, /<link rel="canonical" href="([^"]+)"/) === `${BASE}/?p=${slug}`,
   'product canonical points at itself');
ok(head(p.body, /<meta property="og:type" content="([^"]+)"/) === 'product', 'og:type product');

const pg = jsonld(p.body)['@graph'];
const product = node(pg, 'Product');
ok(!!product, 'Product node');
ok(!!product?.name && !!product?.sku, 'Product carries name and SKU', `${product?.name} ${product?.sku}`);
ok(product?.offers?.priceCurrency === 'ILS' && Number(product?.offers?.price) > 0,
   'Offer carries a price in shekels', product?.offers?.price);
ok(product?.image?.[0]?.startsWith(BASE), 'Product image is absolute');
ok(!!node(pg, 'BreadcrumbList'), 'BreadcrumbList node');

// The details have to be in the HTML, not only painted by the script.
ok(p.body.includes(`<h2 class="pm__name" id="pm-name">${product.name}</h2>`),
   'product name rendered server-side');
ok(p.body.includes('data-open="1"'), 'pop-up marked to open on arrival');

const other = await get('/?p=' + cards[1]);
ok(head(other.body, /<title>([^<]+)<\/title>/) !== pTitle, 'two products, two titles');

const missing = await get('/?p=no-such-product');
ok(head(missing.body, /<link rel="canonical" href="([^"]+)"/) === BASE + '/',
   'an unknown product falls back to the list, not a dead address');

/* ------------------------------------------------------------ behaviour -- */

const browser = await chromium.launch({
  executablePath: process.env.CHROME_BIN || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-proxy-server', '--disable-component-update', '--no-pings', '--disable-sync'],
});
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });

await page.goto(`${BASE}/?p=${slug}`, { waitUntil: 'load' });
ok(await page.locator('#product-modal').isVisible(), 'a product address opens its pop-up');
ok((await page.locator('.pm__name').textContent()).trim() === product.name,
   'the pop-up shows that product');

await page.goto(BASE + '/', { waitUntil: 'load' });
ok(!(await page.locator('#product-modal').isVisible()), 'the list itself opens closed');
await page.locator('.card').nth(2).click();
await page.waitForTimeout(150);
ok(page.url().includes('?p='), 'opening a product changes the address', page.url());
await page.goBack();
await page.waitForTimeout(200);
ok(!(await page.locator('#product-modal').isVisible()), 'back closes the pop-up');
ok(!page.url().includes('?p='), 'back restores the list address');

/* ------------------------------------------------------- the view counter -- */

// The counter is a beacon, so watch the request rather than the database.
const beacons = [];
page.on('request', r => { if (r.url().includes('/api/view.php')) beacons.push(r.postData() || ''); });

const third = await page.locator('.card').nth(3).getAttribute('data-slug');
await page.locator('.card').nth(3).click();
await page.waitForTimeout(250);
ok(beacons.length === 1, 'opening a product counts a view', `${beacons.length} beacon(s)`);
ok(beacons[0].includes(third), 'the beacon names that product', third);

await page.keyboard.press('Escape');
await page.waitForTimeout(150);
await page.locator('.card').nth(3).click();
await page.waitForTimeout(250);
ok(beacons.length === 1, 'reopening the same product in one session counts once',
   `${beacons.length} beacon(s)`);

await page.keyboard.press('Escape');
await page.waitForTimeout(150);
await page.locator('.card').nth(4).click();
await page.waitForTimeout(250);
ok(beacons.length === 2, 'a different product counts again', `${beacons.length} beacon(s)`);

const get405 = await fetch(BASE + '/api/view.php');
ok(get405.status === 405, 'the counter refuses anything but a POST', `status=${get405.status}`);

await browser.close();
console.log(failed ? `\n${failed} check(s) failed` : '\nall SEO checks passed');
process.exit(failed ? 1 : 0);
