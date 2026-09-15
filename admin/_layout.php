<?php
declare(strict_types=1);

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
<link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="admin.css?v=<?= is_file(__DIR__ . '/admin.css') ? filemtime(__DIR__ . '/admin.css') : 1 ?>">
</head>
<body>
<?php if ($nav): ?>
<header class="bar">
  <strong class="bar__brand">קרני תכלת · ניהול</strong>
  <nav class="bar__nav">
    <?php foreach ([
        'products.php' => 'מוצרים',
        'leads.php'    => 'לידים',
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
