<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$s        = settings();
$products = products_all();
$logo     = @file_get_contents(BASE . '/assets/img/logo.svg') ?: '';
$rev      = static fn(string $f): string
    => $f . '?v=' . (is_file(BASE . '/' . $f) ? filemtime(BASE . '/' . $f) : '1');
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($s['site_title']) ?></title>
<meta name="description" content="<?= e($s['description']) ?>">
<meta name="theme-color" content="#00aeef">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($s['site_title']) ?>">
<meta property="og:description" content="<?= e($s['description']) ?>">
<meta property="og:image" content="assets/img/hero.jpg">
<meta property="og:locale" content="he_IL">
<link rel="icon" href="<?= e($rev('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preload" href="assets/fonts/Alef-700-hebrew.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/Alef-400-hebrew.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e($rev('assets/css/site.css')) ?>">
</head>
<body>

<header class="hero">
  <picture class="hero__bg">
    <source srcset="assets/img/hero.webp" type="image/webp">
    <img src="assets/img/hero.jpg" alt="אולם התצוגה של קרני תכלת" fetchpriority="high" width="1440" height="900">
  </picture>
  <div class="hero__logo"><?= $logo ?></div>
  <p class="hero__kicker">לרגל שיפוצים</p>
  <div class="hero__rate">
    <p class="hero__upto">עד</p>
    <img class="hero__pct" src="assets/img/seventy.svg" alt="עד 70% הנחה" width="373" height="280">
    <p class="hero__off">הנחה</p>
  </div>
  <h1 class="hero__title"><span>מכירה</span><span>מתצוגה</span></h1>
  <p class="hero__sub"><span>של גופי תאורה</span><span>בינלאומיים</span></p>
</header>

<section class="steps" aria-label="איך זה עובד">
  <ol class="steps__list">
    <?php foreach ([
        ['בוחרים גוף תאורה', ['גוללים ובוחרים את מה שאוהבים']],
        ['יוצרים קשר טלפוני או משאירים פרטים', ['ציינו את המק״ט של גוף התאורה שאהבתם']],
        ['נציג שלנו יחזור אליכם', ['באפשרותכם גם להגיע לאולם התצוגה', 'ולרכוש במקום']],
    ] as $i => [$title, $lines]): $n = $i + 1; ?>
    <li class="step step--<?= $n ?>">
      <span class="step__disc" aria-hidden="true"></span>
      <img class="step__num" src="assets/img/step<?= $n ?>.svg" alt="שלב <?= $n ?>" width="650" height="142">
      <h2 class="step__title"><?= e($title) ?></h2>
      <p class="step__text"><?php foreach ($lines as $l): ?><span><?= e($l) ?></span><?php endforeach; ?></p>
    </li>
    <?php endforeach; ?>
  </ol>
</section>

<section class="catalog" id="catalog" aria-label="גופי תאורה במכירה">
  <?php if (!$products): ?>
    <p class="catalog__empty">הקטלוג בהכנה. העלו תמונות מוצרים וקובץ אקסל באזור הניהול כדי להציג אותם כאן.</p>
  <?php else: ?>
  <div class="grid">
    <?php $prevRow = null; foreach ($products as $i => $p):
        $row = $p['row'] ?? null;
        $col = $p['col'] ?? null;
        // Honour the design's row/column when the catalogue carries it, so gaps
        // in a row stay where the designer put them. Carried as a custom
        // property so the narrow layout can ignore it without needing !important.
        $style = ($row !== null && $col !== null && $row !== $prevRow && (int) $col !== 1)
            ? ' style="--col:' . (int) $col . '"' : '';
        $prevRow = $row;
        $img = $p['image'] ?? '';
    ?>
    <button type="button" class="card<?= !empty($p['light']) ? ' card--light' : '' ?>"<?= $style ?>
            data-name="<?= e($p['name'] ?? '') ?>" data-sku="<?= e($p['sku'] ?? '') ?>"
            data-before="<?= e((string) ($p['price_before'] ?? '')) ?>"
            data-after="<?= e((string) ($p['price_after'] ?? '')) ?>"
            data-img="<?= e($img ? UPLOAD_URL . '/' . rawurlencode($img) : '') ?>">
      <?php if ($img): ?>
        <img class="card__img" src="<?= e(UPLOAD_URL . '/' . rawurlencode($img)) ?>"
             alt="<?= e(trim(($p['name'] ?? '') . ' ' . ($p['sku'] ?? ''))) ?>"
             width="608" height="582" <?= $i < 8 ? '' : 'loading="lazy" ' ?>decoding="async">
      <?php endif; ?>
      <div class="card__meta">
        <span class="card__name"><?= e($p['name'] ?? '') ?></span>
        <span class="card__sku"><?= e($p['sku'] ?? '') ?></span>
        <?php if (!empty($p['price_before'])): ?>
          <span class="card__old"><i>₪</i><?= e(shekel($p['price_before'])) ?><s aria-hidden="true"></s></span>
        <?php endif; ?>
        <?php if (!empty($p['price_after'])): ?>
          <span class="card__new"><i>₪</i><?= e(shekel($p['price_after'])) ?></span>
        <?php endif; ?>
      </div>
      <span class="card__cta" aria-hidden="true">לפרטים ויצירת קשר</span>
    </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<section class="lead" id="lead">
  <img class="lead__dolly" src="assets/img/dolly.webp" alt="" aria-hidden="true" width="699" height="1047">
  <h2 class="lead__title">לרכישה צרו קשר בטופס</h2>
  <p class="lead__sub">מלאו פרטים בטופס ונחזור אליכם בהקדם</p>
  <img class="lead__chevron" src="assets/img/chevron.svg" alt="" aria-hidden="true" width="68" height="43">
</section>

<footer class="foot">
  <form class="lform" action="api/lead.php" method="post" novalidate>
    <p class="lform__hp" aria-hidden="true"><label>אל תמלאו שדה זה<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
    <div class="lform__row">
      <div class="field"><input id="f-name"  name="name" aria-label="שם מלא" type="text"  autocomplete="name"       placeholder="שם מלא" required></div>
      <div class="field"><input id="f-sku"   name="sku" aria-label="מק״ט" type="text"  autocomplete="off"        placeholder="מק&quot;ט"></div>
      <div class="field"><input id="f-phone" name="phone" aria-label="טלפון" type="tel"   autocomplete="tel"        placeholder="טלפון" required inputmode="tel"></div>
      <div class="field"><input id="f-email" name="email" aria-label="מייל" type="email" autocomplete="email"      placeholder="מייל"></div>
      <button class="send" type="submit">
        <span class="send__label">שלח</span>
        <span class="send__arrow" aria-hidden="true">&lt;</span>
      </button>
    </div>
    <?php
    // Normally filled in by fetch; this covers a submit without JavaScript.
    // The code is looked up here rather than echoed back, so the URL cannot put
    // arbitrary words on the page.
    $noJs = lead_notice((string) ($_GET['lead'] ?? ''));
    ?>
    <p class="lform__msg<?= $noJs && ($_GET['lead'] ?? '') !== 'ok' ? ' is-bad' : '' ?>"
       role="status" aria-live="polite"><?= e($noJs) ?></p>
  </form>

  <div class="foot__brand"><?= $logo ?></div>
  <address class="foot__contact">
    <a href="tel:<?= e(preg_replace('/\D/', '', $s['phone'])) ?>"><?= e($s['phone']) ?></a>
    <span><?= e($s['address']) ?></span>
  </address>
</footer>

<dialog class="pm" id="product-modal" aria-labelledby="pm-name">
  <button class="pm__x" type="button" data-close aria-label="סגירת החלון">&times;</button>
  <div class="pm__media"><img class="pm__img" src="" alt=""></div>
  <div class="pm__body" tabindex="-1" autofocus>
    <p class="pm__name" id="pm-name"></p>
    <p class="pm__sku"></p>
    <div class="pm__prices">
      <span class="pm__old" hidden><i>₪</i><span></span><s aria-hidden="true"></s></span>
      <span class="pm__new" hidden><i>₪</i><span></span></span>
      <span class="pm__save" hidden></span>
    </div>
    <hr>

    <div class="pm__ask">
      <p class="pm__lead">מעוניינים? השאירו פרטים ונחזור אליכם</p>
      <form class="lform pm__form" action="api/lead.php" method="post" novalidate>
        <p class="lform__hp" aria-hidden="true"><label>אל תמלאו שדה זה<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
        <input type="hidden" name="sku" value="">
        <input type="hidden" name="product" value="">
        <input type="hidden" name="source" value="popup">
        <div class="pm__fields">
          <label class="pf"><input name="name" type="text" autocomplete="name" placeholder="שם מלא" aria-label="שם מלא" required></label>
          <label class="pf"><input name="phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="טלפון" aria-label="טלפון" required></label>
          <label class="pf pf--wide"><input name="email" type="email" autocomplete="email" placeholder="מייל (לא חובה)" aria-label="מייל"></label>
        </div>
        <div class="pm__actions">
          <button class="send pm__send" type="submit"><span class="send__label">שלח</span><span class="send__arrow" aria-hidden="true">&lt;</span></button>
          <p class="pm__or">או חייגו <a href="tel:<?= e(preg_replace('/\D/', '', $s['phone'])) ?>"><?= e($s['phone']) ?></a></p>
        </div>
        <p class="lform__msg" role="status" aria-live="polite"></p>
      </form>
      <p class="pm__fine"></p>
    </div>

    <div class="pm__done" hidden>
      <span class="pm__tick" aria-hidden="true">✓</span>
      <p class="pm__done-t">תודה, קיבלנו את הפרטים</p>
      <p class="pm__done-s"></p>
      <button class="pm__ghost" type="button" data-close>חזרה לגלריה</button>
    </div>
  </div>
</dialog>

<script src="<?= e($rev('assets/js/site.js')) ?>" defer></script>
</body>
</html>
