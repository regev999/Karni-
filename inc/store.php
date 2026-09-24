<?php
declare(strict_types=1);

/**
 * Files holding an admin hash or customer details are stored as .php behind this
 * guard line, so they stay silent even on a server that ignores .htaccess.
 */
const JSON_GUARD = "<?php http_response_code(404); exit; ?>\n";

/** Read a JSON file, returning $fallback when it is missing or unreadable. */
function read_json(string $path, mixed $fallback = null): mixed
{
    if (!is_file($path)) {
        return $fallback;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $fallback;
    }
    if (str_starts_with($raw, '<?php')) {
        $raw = substr($raw, (int) strpos($raw, "\n") + 1);
    }
    $val = json_decode($raw, true);
    return $val === null && json_last_error() !== JSON_ERROR_NONE ? $fallback : $val;
}

/** Write JSON atomically so a crashed request cannot leave a half-written file. */
function write_json(string $path, mixed $value): bool
{
    @mkdir(dirname($path), 0775, true);
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json !== false && str_ends_with($path, '.php')) {
        $json = JSON_GUARD . $json;
    }
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0664);
    return true;
}

function products_all(): array
{
    // null, not [], so that a catalogue someone deliberately emptied is not
    // mistaken for a fresh install and re-seeded.
    $rows = read_json(PRODUCTS_FILE, null);
    if ($rows === null) {
        $legacy = DATA_DIR . '/products.json';
        $rows = read_json(is_file($legacy) ? $legacy : PRODUCTS_SEED, []) ?: [];
        if ($rows) {
            write_json(PRODUCTS_FILE, $rows);
        }
    }
    return array_values(array_filter($rows, static fn($r) => is_array($r) && ($r['name'] ?? '') !== ''));
}

function products_save(array $rows): bool
{
    $i = 0;
    foreach ($rows as &$r) {
        $r['id'] = ++$i;
    }
    unset($r);
    return write_json(PRODUCTS_FILE, array_values($rows));
}

/**
 * Read, change and write one JSON file under an exclusive lock, so that two
 * requests landing together cannot each write over the other's change.
 */
function json_update(string $path, callable $change): bool
{
    @mkdir(dirname($path), 0775, true);
    $fh = fopen($path, 'c+');
    if (!$fh) {
        return false;
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return false;
        }
        $raw = stream_get_contents($fh);
        if (str_starts_with($raw, '<?php')) {
            $raw = substr($raw, (int) strpos($raw, "\n") + 1);
        }
        $rows = $change(trim($raw) === '' ? [] : (json_decode($raw, true) ?: []));
        $json = (str_ends_with($path, '.php') ? JSON_GUARD : '')
            . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $json);
        fflush($fh);
        return true;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** How many times each product's pop-up was opened, keyed by its URL slug. */
function views_all(): array
{
    return read_json(VIEWS_FILE, []) ?: [];
}

/** Count one pop-up opening. */
function views_bump(string $slug): bool
{
    return json_update(VIEWS_FILE, static function (array $rows) use ($slug): array {
        $rows[$slug] = [
            'views' => (int) ($rows[$slug]['views'] ?? 0) + 1,
            'last'  => date('Y-m-d H:i:s'),
        ];
        return $rows;
    });
}
