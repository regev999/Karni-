// Screenshot the built page at the design's own width and diff it against the
// design render, section by section. Run: node tools/shoot.mjs [--full]
import { chromium } from 'playwright';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';
import fs from 'node:fs';

const URL = process.env.SITE_URL || 'http://127.0.0.1:8088/';
const OUT = 'tools/out';
const DESIGN = `${OUT}/design.png`;

// `shift` aligns a section that sits below the product grid, whose height
// depends on how many rows the catalogue has.
//
// Three sections are expected to differ from the artboard rather than match it:
// `grid`, because the page packs the rows the design leaves half-empty; `lead`,
// whose type and button the client asked to be taken down 30%; and `footer`,
// whose contact block is held clear of the floating button the artboard draws
// on the first screen and never in the footer.
const SECTIONS = [
  ['hero',    0,     900,   0],
  ['steps',   900,   1650,  0],
  ['grid1',   1790,  2560,  0],
  ['grid2',   1790,  3000,  0],
  ['lead',    13134, 13574, 'bottom'],
  ['footer',  13574, 13827, 'bottom'],
];

const browser = await chromium.launch({
  executablePath: process.env.CHROME_BIN || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  // This sandbox has no outbound net, and Chromium's own phone-home retries
  // otherwise keep the network busy for the whole run.
  args: ['--no-proxy-server', '--disable-component-update', '--disable-domain-reliability',
         '--no-pings', '--disable-sync', '--safebrowsing-disable-auto-update',
         '--disable-client-side-phishing-detection'],
});
const page = await browser.newPage({ viewport: { width: 1440, height: 1200 }, deviceScaleFactor: 1 });
page.setDefaultTimeout(60000);
await page.goto(URL, { waitUntil: 'load' });
await page.evaluate(() => document.fonts.ready);

// The artboard draws the floating WhatsApp bubble once, where it would sit on
// the first screen; on the page it is fixed to the viewport, so a full-page
// capture can never land it there. Hide it rather than diff its two positions.
await page.evaluate(() => {
  const f = document.querySelector('.wa--float');
  if (f) { f.style.display = 'none'; }
});

// Force every lazy image in before measuring the full page. Waiting on all of
// them at once is enough to crash the renderer, so walk them in batches.
await page.evaluate(() => {
  document.querySelectorAll('img[loading="lazy"]').forEach(i => { i.loading = 'eager'; });
});
await page.evaluate(async () => {
  const imgs = [...document.images];
  for (let i = 0; i < imgs.length; i += 10) {
    await Promise.all(imgs.slice(i, i + 10).map(im => im.complete && im.naturalWidth
      ? Promise.resolve()
      : new Promise(r => {
          im.addEventListener('load', r, { once: true });
          im.addEventListener('error', r, { once: true });
          setTimeout(r, 5000);
        })));
  }
  await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
});
const pending = await page.evaluate(() =>
  [...document.images].filter(i => !i.complete || !i.naturalWidth).length);
if (pending) console.log(`warning: ${pending} images still loading`);

// Walk the page so every tile is painted at least once before the capture.
await page.evaluate(async () => {
  const step = window.innerHeight;
  for (let y = 0; y < document.documentElement.scrollHeight; y += step) {
    window.scrollTo(0, y);
    await new Promise(r => requestAnimationFrame(() => setTimeout(r, 30)));
  }
  window.scrollTo(0, 0);
  await new Promise(r => setTimeout(r, 300));
});

const height = await page.evaluate(() => document.documentElement.scrollHeight);
await page.screenshot({ path: `${OUT}/build.png`, fullPage: true, timeout: 180000 });
await browser.close();

console.log(`build height ${height}px   design height 13827px   delta ${height - 13827}px`);

if (!fs.existsSync(DESIGN)) { console.log('no design render; skipping diff'); process.exit(0); }
const design = PNG.sync.read(fs.readFileSync(DESIGN));
const build  = PNG.sync.read(fs.readFileSync(`${OUT}/build.png`));

function crop(src, y0, y1) {
  const h = Math.min(y1, src.height) - y0;
  const out = new PNG({ width: 1440, height: h });
  for (let y = 0; y < h; y++)
    src.data.copy(out.data, y * 1440 * 4, ((y + y0) * src.width) * 4, ((y + y0) * src.width + 1440) * 4);
  return out;
}

const delta = build.height - design.height;
let worst = 0;
for (const [name, y0, y1, shift] of SECTIONS) {
  const off = shift === 'bottom' ? delta : (shift || 0);
  if (y1 > design.height || y1 + off > build.height) { console.log(`${name.padEnd(8)} out of range`); continue; }
  const a = crop(design, y0, y1), b = crop(build, y0 + off, y1 + off);
  const diff = new PNG({ width: a.width, height: a.height });
  const n = pixelmatch(a.data, b.data, diff.data, a.width, a.height, { threshold: 0.12, includeAA: false });
  const pct = (n / (a.width * a.height)) * 100;
  worst = Math.max(worst, pct);
  fs.writeFileSync(`${OUT}/diff-${name}.png`, PNG.sync.write(diff));
  console.log(`${name.padEnd(8)} ${String(y0).padStart(6)}-${String(y1).padEnd(6)} mismatch ${pct.toFixed(2)}%  (${n} px)`);
}
console.log(`worst section ${worst.toFixed(2)}%`);
