<?php
declare(strict_types=1);

/**
 * Repaint the grey studio sweep behind the product photos white.
 * Run after tools/extract_catalog.py, which cuts the tiles out of the design
 * exactly as they were drawn. Uploads go through the same code by themselves.
 *
 *     php tools/flatten.php [directory]
 */

require __DIR__ . '/../inc/image.php';

$dir = $argv[1] ?? __DIR__ . '/../uploads/products';
$files = glob(rtrim($dir, '/') . '/*.{jpg,jpeg,png,webp,gif,avif}', GLOB_BRACE) ?: [];

$done = 0;
$t0 = microtime(true);
foreach ($files as $f) {
    if (flatten_backdrop($f)) {
        $done++;
        echo "  flattened " . basename($f) . "\n";
    }
}
printf("%d of %d photos had a sweep behind them (%.1fs)\n", $done, count($files), microtime(true) - $t0);
