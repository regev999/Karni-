<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/seo.php';

function layout_head(string $title, bool $nav = true): void
{
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    ?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> — ניהול קרני תכלת</title>
<?php $icon = site_icon(); ?>
<link rel="icon" href="../<?= e($icon['url'] ?? 'assets/img/favicon.svg') ?>"
      type="<?= e($icon['type'] ?? 'image/svg+xml') ?>">
<link rel="stylesheet" href="admin.css?v=<?= is_file(__DIR__ . '/admin.css') ? filemtime(__DIR__ . '/admin.css') : 1 ?>">
</head>
<body>
<?php if ($nav): ?>
<header class="bar">
  <strong class="bar__brand">קרני תכלת · ניהול</strong>
  <nav class="bar__nav">
    <?php foreach ([
        'products.php' => 'מוצרים',
        'settings.php' => 'הגדרות',
    ] as $href => $label): ?>
      <a href="<?= $href ?>"<?= $current === $href ? ' class="is-on"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
    <a href="../" target="_blank" rel="noopener">צפייה בדף ↗</a>
    <a href="logout.php" class="bar__out">יציאה</a>
  </nav>
</header>
<?php endif; ?>
<main class="wrap">
    <?php
    if ($nav) {
        seo_block_banner();
    }
}

/**
 * The reminder to open the site up again. It cannot be a date in a diary: the
 * move to the real domain happens when it happens. So the admin watches for it
 * — the moment the site answers on an address other than the one the block was
 * put in place for, this turns into a call to action.
 */
function seo_block_banner(): void
{
    $s = settings();
    if (empty($s['noindex'])) {
        return;
    }
    $was = (string) ($s['noindex_host'] ?? '');
    $now = current_host();
    $moved = $was !== '' && $was !== $now;
    ?>
<p class="note note--<?= $moved ? 'act' : 'warn' ?>">
  <?php if ($moved): ?>
    <strong>הדומיין השתנה — הגיע הזמן לפתוח את האתר לגוגל.</strong>
    האתר נחסם לאינדוקס כשהוא ישב על <code><?= e($was) ?></code>, והוא עונה עכשיו על
    <code><?= e($now) ?></code>. כל עוד החסימה דולקת, האתר לא יופיע בחיפוש.
    <a href="settings.php">לכיבוי החסימה →</a>
  <?php else: ?>
    <strong>האתר חסום ממנועי חיפוש.</strong>
    כך צריך להיות כל עוד הוא על כתובת זמנית. ביום המעבר לדומיין הסופי — לכבות
    ב<a href="settings.php">הגדרות</a>.
  <?php endif; ?>
</p>
    <?php
}

function layout_foot(): void
{
    echo "</main>\n</body>\n</html>\n";
}

/** Render queued one-shot notices, then clear them. */
function flash(?string $text = null, string $kind = 'ok'): void
{
    session_start_once();
    if ($text !== null) {
        $_SESSION['flash'][] = [$kind, $text];
        return;
    }
    foreach ($_SESSION['flash'] ?? [] as [$k, $t]) {
        echo '<p class="note note--' . e($k) . '">' . e($t) . '</p>';
    }
    unset($_SESSION['flash']);
}
