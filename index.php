<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/seo.php';

$s        = settings();
$products = products_indexed();
$logo     = @file_get_contents(BASE . '/assets/img/logo.svg') ?: '';
$rev      = static fn(string $f): string
    => $f . '?v=' . (is_file(BASE . '/' . $f) ? filemtime(BASE . '/' . $f) : '1');

// ?p=<slug> is a real, indexable address for one product: the same page, with
// that product's details rendered into the pop-up and its own title and schema.
$current = product_by_slug((string) ($_GET['p'] ?? ''));
seo_refresh_static();
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php seo_head($s, $current, $rev('assets/img/og.jpg')); ?>
<link rel="icon" href="<?= e($rev('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preload" href="assets/fonts/Alef-700-hebrew.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/Alef-400-hebrew.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= e($rev('assets/img/hero.webp')) ?>" as="image" type="image/webp" fetchpriority="high">
<link rel="stylesheet" href="<?= e($rev('assets/css/site.css')) ?>">
<?php seo_jsonld($s, $current, $rev('assets/img/og.jpg')); ?>
</head>
<body>
<main>

<header class="hero">
  <picture class="hero__bg">
    <source srcset="<?= e($rev('assets/img/hero.webp')) ?>" type="image/webp">
    <img src="<?= e($rev('assets/img/hero.jpg')) ?>" alt="אולם התצוגה של קרני תכלת" fetchpriority="high" width="1440" height="900">
  </picture>
  <div class="hero__logo"><?= $logo ?></div>
  <p class="hero__kicker">לרגל שיפוצים</p>
  <div class="hero__rate">
    <p class="hero__upto">עד</p>
    <img class="hero__pct" src="<?= e($rev('assets/img/seventy.svg')) ?>" alt="עד 70% הנחה" width="373" height="280">
    <p class="hero__off">הנחה</p>
  </div>
  <h1 class="hero__title"><span>מכירה</span><span>מתצוגה</span></h1>
  <p class="hero__sub"><span>של גופי תאורה</span><span>בינלאומיים</span></p>
</header>

<section class="steps" aria-label="איך זה עובד">
  <ol class="steps__list">
    <?php foreach ([
        ['בוחרים גוף תאורה', []],
        ['משאירים הודעה בוואטסאפ', ['עם הפרטים והבחירה שלכם']],
        ['ונציג שלנו יחזור אליכם בהקדם', []],
    ] as $i => [$title, $lines]): $n = $i + 1; ?>
    <li class="step step--<?= $n ?>">
      <span class="step__disc" aria-hidden="true"></span>
      <img class="step__num" src="<?= e($rev('assets/img/step' . $n . '.svg')) ?>" alt="שלב <?= $n ?>" width="650" height="142">
      <h2 class="step__title"><?= e($title) ?></h2>
      <?php if ($lines): ?><p class="step__text"><?php foreach ($lines as $l): ?><span><?= e($l) ?></span><?php endforeach; ?></p><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
</section>

<section class="catalog" id="catalog">
  <h2 class="sr-only">גופי תאורה במכירה מתצוגה</h2>
  <?php if (!$products): ?>
    <p class="catalog__empty">הקטלוג בהכנה. העלו תמונות מוצרים וקובץ אקסל באזור הניהול כדי להציג אותם כאן.</p>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($products as $i => $p): $img = $p['image'] ?? ''; ?>
    <?php $note = trim((string) ($p['note'] ?? '')); ?>
    <a class="card<?= ($p['ink'] ?? '') === 'light' ? ' card--dark' : '' ?><?= ($p['brand_ink'] ?? '') === 'light' ? ' card--mark-light' : '' ?><?= $note !== '' ? ' card--noted' : '' ?>" href="?p=<?= e(rawurlencode($p['slug'])) ?>"
            data-slug="<?= e($p['slug']) ?>"
            data-name="<?= e($p['name'] ?? '') ?>" data-sku="<?= e($p['sku'] ?? '') ?>"
            data-before="<?= e((string) ($p['price_before'] ?? '')) ?>"
            data-after="<?= e((string) ($p['price_after'] ?? '')) ?>"
            data-desc="<?= e((string) ($p['description'] ?? '')) ?>"
            data-note="<?= e($note) ?>"
            data-wa="<?= e(wa_link(product_message($p, $s))) ?>"
            data-brand="<?= e(!empty($p['brand']) ? BRAND_URL . '/' . rawurlencode($p['brand']) : '') ?>"
            data-img="<?= e(upload_url($img)) ?>"
            aria-label="<?= e(trim(($p['name'] ?? '') . ' ' . ($p['sku'] ?? '')) . ' — לפרטים ויצירת קשר') ?>">
      <?php if ($img): ?>
        <img class="card__img" src="<?= e(upload_url($img)) ?>"
             alt="<?= e(trim(($p['name'] ?? '') . ' ' . ($p['sku'] ?? ''))) ?>"
             width="608" height="582" <?= $i < 8 ? '' : 'loading="lazy" ' ?>decoding="async">
      <?php endif; ?>
      <?php // The maker's mark was cut out of the tile so the card can hang it
            // off its own right edge instead of wherever the artboard left it. ?>
      <?php if (!empty($p['brand'])): ?>
        <img class="card__brand" src="<?= e($rev(BRAND_URL . '/' . rawurlencode($p['brand']))) ?>"
             alt="" aria-hidden="true" style="--w: <?= e((string) (float) ($p['brand_w'] ?? 72)) ?>"
             <?= $i < 8 ? '' : 'loading="lazy" ' ?>decoding="async">
      <?php endif; ?>
      <div class="card__meta">
        <h3 class="card__name"><?= e($p['name'] ?? '') ?></h3>
        <span class="card__sku"><?= e($p['sku'] ?? '') ?></span>
        <?php // The designer's own line - a finish, or "available in 18 colours".
              // Two products differ by nothing else, and without it they read as
              // the same lamp listed twice. ?>
        <?php if ($note !== ''): ?><span class="card__note"><?= e($note) ?></span><?php endif; ?>
        <?php if (!empty($p['price_before'])): ?>
          <span class="card__old"><i>₪</i><?= e(shekel($p['price_before'])) ?><s aria-hidden="true"></s></span>
        <?php endif; ?>
        <?php if (!empty($p['price_after'])): ?>
          <span class="card__new"><i>₪</i><?= e(shekel($p['price_after'])) ?></span>
        <?php endif; ?>
      </div>
      <span class="card__go" aria-hidden="true">
        <svg viewBox="1299.3 12496.2 67.6 43.2" width="16" height="10">
          <path d="M1348.50 12503.27 C1349.31 12502.53 1350.12 12501.47 1351.04 12500.88 C1358.32 12496.16 1366.88 12505.17 1360.22 12513.10 C1353.29 12521.34 1343.56 12528.69 1336.39 12536.89 C1333.12 12539.40 1329.29 12539.17 1326.17 12536.54 C1319.29 12528.44 1309.05 12521.07 1302.52 12512.92 C1300.15 12509.98 1299.30 12506.65 1301.32 12503.23 C1303.25 12499.96 1307.55 12498.86 1310.95 12500.42 C1312.47 12501.12 1316.64 12505.33 1318.19 12506.79 C1322.72 12511.08 1326.93 12515.75 1331.45 12520.05 C1337.18 12514.52 1342.58 12508.60 1348.50 12503.27" fill="currentColor"/>
        </svg>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<section class="lead" id="lead">
  <img class="lead__dolly" src="<?= e($rev('assets/img/dolly.webp')) ?>" alt="" aria-hidden="true" width="375" height="647">
  <h2 class="lead__title">לרכישה שלחו הודעה בווטסאפ</h2>
  <p class="lead__sub">ונציג מטעמנו יחזור אליכם בהקדם</p>
  <a class="wa wa--cta" href="<?= e(wa_link('היי, אני מעוניין/ת בפרטים על המכירה מתצוגה')) ?>"
     target="_blank" rel="noopener">
    <span class="wa__mark" aria-hidden="true"><?= wa_mark() ?></span>
    <span class="wa__label">לשיחה עם נציג <span class="wa__arrow" aria-hidden="true">&lt;</span></span>
  </a>
</section>
</main>

<footer class="foot">
  <div class="foot__brand"><?= $logo ?></div>
  <address class="foot__contact">
    <a href="tel:<?= e(preg_replace('/\D/', '', $s['phone'])) ?>"><?= e($s['phone']) ?></a>
    <span><?= e($s['address']) ?></span>
  </address>
</footer>

<?php
// On ?p=<slug> the pop-up is filled here rather than only by JavaScript, so the
// address has real, unique content for a crawler and for a visitor without JS.
$cName   = (string) ($current['name'] ?? '');
$cSku    = (string) ($current['sku'] ?? '');
$cImg    = (string) ($current['image'] ?? '');
$cDesc   = trim((string) ($current['description'] ?? ''));
$cBrand  = (string) ($current['brand'] ?? '');
$cNote   = trim((string) ($current['note'] ?? ''));
$cBefore = (int) ($current['price_before'] ?? 0);
$cAfter  = (int) ($current['price_after'] ?? 0);
$cPct    = $current ? product_discount($current) : null;
?>
<dialog class="pm" id="product-modal" aria-labelledby="pm-name"<?= $current ? ' data-open="1"' : '' ?>>
  <button class="pm__x" type="button" data-close aria-label="סגירת החלון">&times;</button>
  <div class="pm__media"<?= $current && !$cImg ? ' hidden' : '' ?>>
    <?php // src="" would make the browser fetch the page itself as an image. ?>
    <img class="pm__img"<?= $cImg ? ' src="' . e(upload_url($cImg)) . '"' : '' ?>
         alt="<?= e(trim($cName . ' ' . $cSku)) ?>">
    <img class="pm__brand"<?= $cBrand !== '' ? ' src="' . e(BRAND_URL . '/' . rawurlencode($cBrand)) . '"' : '' ?>
         alt="" aria-hidden="true"<?= $cBrand === '' ? ' hidden' : '' ?>></div>
  <div class="pm__body" tabindex="-1" autofocus>
    <h2 class="pm__name" id="pm-name"><?= e($cName) ?></h2>
    <p class="pm__sku"><?= e($cSku !== '' ? 'מק״ט ' . $cSku : '') ?></p>
    <p class="pm__note"<?= $cNote === '' ? ' hidden' : '' ?>><?= e($cNote) ?></p>
    <div class="pm__prices">
      <span class="pm__old"<?= $cBefore ? '' : ' hidden' ?>><i>₪</i><span><?= e(shekel($cBefore ?: null)) ?></span><s aria-hidden="true"></s></span>
      <span class="pm__new"<?= $cAfter ? '' : ' hidden' ?>><i>₪</i><span><?= e(shekel($cAfter ?: null)) ?></span></span>
      <span class="pm__save"<?= $cPct ? '' : ' hidden' ?>><?= $cPct ? e('חיסכון ₪' . shekel($cBefore - $cAfter) . ' · ' . $cPct . '%') : '' ?></span>
    </div>
    <p class="pm__desc"<?= $cDesc === '' ? ' hidden' : '' ?>><?= e($cDesc) ?></p>
    <hr>

    <div class="pm__ask">
      <p class="pm__lead">מעוניינים? שלחו לנו הודעה — המק״ט כבר בתוכה</p>
      <a class="wa wa--pm" href="<?= e(wa_link(product_message($current, $s))) ?>"
         target="_blank" rel="noopener">
        <span class="wa__mark" aria-hidden="true"><?= wa_mark() ?></span>
        <span class="wa__label">שליחת הודעה בוואטסאפ <span class="wa__arrow" aria-hidden="true">&lt;</span></span>
      </a>
      <p class="pm__or">או חייגו <a href="tel:<?= e(preg_replace('/\D/', '', $s['phone'])) ?>"><?= e($s['phone']) ?></a></p>
      <p class="pm__fine"><?= e(($cSku !== '' ? 'המק״ט ' . $cSku . ' מצורף להודעה · ' : '') . 'אין חיוב ואין רכישה באתר') ?></p>
    </div>
  </div>
</dialog>

<a class="wa wa--float" href="<?= e(wa_link('היי, אני מעוניין/ת בפרטים על המכירה מתצוגה')) ?>"
   target="_blank" rel="noopener" aria-label="שליחת הודעה בוואטסאפ"><?= wa_mark() ?></a>

<script src="<?= e($rev('assets/js/site.js')) ?>" defer></script>
</body>
</html>
