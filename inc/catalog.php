<?php
declare(strict_types=1);

const IMAGE_TYPES = [
    IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif',
    IMAGETYPE_WEBP => 'webp', IMAGETYPE_AVIF => 'avif',
];

/** Case- and punctuation-insensitive key used to line images up with sheet rows. */
function match_key(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[\x{2000}-\x{200F}]/u', '', $s);
    return preg_replace('/[\s_\-.,"\x{05F4}\x{05F3}\']+/u', '', $s) ?? $s;
}

/** Turn an uploaded filename into a product name: strip extension and tidy separators. */
function name_from_filename(string $filename): string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $base = preg_replace('/[_]+/u', ' ', $base);
    $base = preg_replace('/\s{2,}/u', ' ', $base);
    return trim($base);
}

/** A filesystem-safe version of the original name, so the file stays recognisable. */
function safe_filename(string $filename, string $ext): string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $base = preg_replace('/[^\p{L}\p{N}\- ]+/u', '', $base) ?? '';
    $base = trim(preg_replace('/\s+/u', '-', $base) ?? '', '-');
    if ($base === '' || str_starts_with($base, '.')) {
        $base = 'product-' . bin2hex(random_bytes(4));
    }
    return mb_substr($base, 0, 80) . '.' . $ext;
}

/**
 * Store one uploaded image and attach it to the product named by the file.
 * Returns [name, storedFilename] or throws.
 */
function ingest_image(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('העלאה נכשלה (' . (int) ($file['error'] ?? -1) . ')');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('קובץ לא תקין');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !isset(IMAGE_TYPES[$info[2]])) {
        throw new RuntimeException('הקובץ אינו תמונה נתמכת (jpg, png, webp, gif, avif)');
    }
    @mkdir(UPLOAD_DIR, 0775, true);

    $ext  = IMAGE_TYPES[$info[2]];
    $name = name_from_filename((string) $file['name']);
    $dest = safe_filename((string) $file['name'], $ext);

    // Never silently overwrite a different product's photo.
    $i = 1;
    while (is_file(UPLOAD_DIR . '/' . $dest) && $i < 500) {
        $dest = safe_filename(pathinfo((string) $file['name'], PATHINFO_FILENAME) . '-' . (++$i), $ext);
    }
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $dest)) {
        throw new RuntimeException('שמירת הקובץ נכשלה');
    }
    @chmod(UPLOAD_DIR . '/' . $dest, 0664);
    return [$name, $dest];
}

/**
 * Merge freshly uploaded images into the catalogue, matching on product name so
 * re-uploading a photo replaces it rather than duplicating the product.
 */
function catalog_add_images(array $rows, array $uploads): array
{
    $index = [];
    foreach ($rows as $i => $r) {
        $index[match_key((string) ($r['name'] ?? ''))] = $i;
    }
    foreach ($uploads as [$name, $file]) {
        $key = match_key($name);
        if (isset($index[$key])) {
            $old = $rows[$index[$key]]['image'] ?? '';
            if ($old !== '' && $old !== $file && is_file(UPLOAD_DIR . '/' . $old)) {
                @unlink(UPLOAD_DIR . '/' . $old);
            }
            $rows[$index[$key]]['image'] = $file;
            continue;
        }
        $rows[] = ['name' => $name, 'sku' => '', 'price_before' => null,
                   'price_after' => null, 'image' => $file];
        $index[$key] = array_key_last($rows);
    }
    return $rows;
}

/**
 * Merge a spreadsheet into the catalogue. Sheet rows win on names and prices,
 * existing rows keep their photo, and the sheet's order becomes the page order.
 */
function catalog_apply_sheet(array $rows, array $sheet): array
{
    $byName = $bySku = [];
    foreach ($rows as $i => $r) {
        $n = match_key((string) ($r['name'] ?? ''));
        $s = match_key((string) ($r['sku'] ?? ''));
        if ($n !== '') { $byName[$n] ??= $i; }
        if ($s !== '') { $bySku[$s] ??= $i; }
    }

    $ordered = [];
    $used = [];
    foreach ($sheet as $row) {
        $n = match_key($row['name']);
        $s = match_key($row['sku']);
        $i = $byName[$n] ?? ($s !== '' ? ($bySku[$s] ?? null) : null);
        if ($i !== null && !isset($used[$i])) {
            $used[$i] = true;
            $ordered[] = array_merge($rows[$i], [
                'name'         => $row['name'] !== '' ? $row['name'] : $rows[$i]['name'],
                'sku'          => $row['sku'],
                'price_before' => $row['price_before'],
                'price_after'  => $row['price_after'],
            ]);
        } else {
            $ordered[] = $row + ['image' => ''];
        }
    }
    // Anything the sheet did not mention keeps its place at the end.
    foreach ($rows as $i => $r) {
        if (!isset($used[$i])) {
            $ordered[] = $r;
        }
    }
    return $ordered;
}

function catalog_stats(array $rows): array
{
    $withImage = $withPrice = 0;
    foreach ($rows as $r) {
        if (!empty($r['image'])) { $withImage++; }
        if (!empty($r['price_after'])) { $withPrice++; }
    }
    return ['total' => count($rows), 'images' => $withImage, 'prices' => $withPrice];
}
