<?php
/**
 * Re-decide, for every product, whether its card caption needs white text.
 * Run after seeding the catalogue or after replacing photos in bulk:
 *
 *     php tools/detect-text-colour.php          # report only
 *     php tools/detect-text-colour.php --write  # save the result
 */
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/catalog.php';

$write = in_array('--write', $argv, true);
$rows = products_all();
$changed = 0;
$skipped = 0;

foreach ($rows as &$r) {
    $file = $r['image'] ?? '';
    if ($file === '' || !is_file(UPLOAD_DIR . '/' . $file)) {
        $skipped++;
        continue;
    }
    $light = image_wants_light_text(UPLOAD_DIR . '/' . $file);
    if ($light === null) {
        $skipped++;
        continue;
    }
    if ((bool) ($r['light'] ?? false) !== $light) {
        printf("%-22s %-14s %s\n", $r['name'] ?? '', $r['sku'] ?? '',
            $light ? 'dark photo  -> white caption' : 'light photo -> dark caption');
        $r['light'] = $light;
        $changed++;
    }
}
unset($r);

echo "\n", count($rows), " products, $changed changed, $skipped without a readable image\n";

if ($changed && $write) {
    products_save($rows);
    echo "saved\n";
} elseif ($changed) {
    echo "run again with --write to save\n";
}
