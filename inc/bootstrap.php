<?php
declare(strict_types=1);

const BASE = __DIR__ . '/..';

// Override DATA_DIR in inc/config.local.php to keep data outside the web root.
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
defined('DATA_DIR') || define('DATA_DIR', BASE . '/data');

const UPLOAD_DIR = BASE . '/uploads/products';
const UPLOAD_URL = 'uploads/products';
// Maker's marks, cut out of the design's tiles so the card can place them itself.
const BRAND_URL  = 'assets/img/brands';

/**
 * A product photo's address, stamped with the file's own time.
 *
 * Replacing a photo in the admin means uploading it under the same name, and
 * re-cutting the catalogue from a new design file rewrites all of them in
 * place. Without the stamp a browser that has seen the old one goes on drawing
 * it, however far the file on disk has moved on.
 */
function upload_url(string $file): string
{
    if ($file === '') {
        return '';
    }
    $path = UPLOAD_DIR . '/' . $file;
    return UPLOAD_URL . '/' . rawurlencode($file)
        . '?v=' . (is_file($path) ? filemtime($path) : '1');
}

// Holds the admin hash and the page's own settings; .php so a stray direct request is inert.
define('SETTINGS_FILE', DATA_DIR . '/settings.php');
define('VIEWS_FILE', DATA_DIR . '/views.php');
define('LOGINS_FILE', DATA_DIR . '/logins.php');
// The live catalogue is a .php behind the same guard, so no server rule has to
// keep it private; the seed beside it is what a fresh install starts from.
define('PRODUCTS_FILE', DATA_DIR . '/products.php');
// The seed ships with the code, so it stays next to it even when DATA_DIR has
// been moved outside the web root.
define('PRODUCTS_SEED', BASE . '/data/products.seed.php');

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Jerusalem');

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/auth.php';

/** Escape for HTML output. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Format a price the way the design does: whole shekels, no separators. */
function shekel(int|float|null $n): string
{
    return $n === null ? '' : (string) (int) round((float) $n);
}

/**
 * The number people are sent to, as a wa.me link. Anything but digits is
 * dropped, and a local 0 becomes Israel's 972 - which is how the number is
 * written on a business card and not how WhatsApp wants it.
 */
function wa_number(): string
{
    $digits = preg_replace('/\D+/', '', (string) (settings()['whatsapp'] ?? '')) ?? '';
    if ($digits === '') {
        return '';
    }
    return str_starts_with($digits, '0') ? '972' . ltrim($digits, '0') : $digits;
}

/** The WhatsApp glyph, drawn once and coloured by whatever contains it. */
function wa_mark(): string
{
    return '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true">'
        . '<path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.26-.46-2.39-1.48-.88-.79-1.48-1.76-1.65-2.06-.18-.3-.02-.46.13-.6.13-.14.3-.35.44-.52.15-.18.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.87 1.22 3.07c.15.2 2.1 3.2 5.08 4.49.7.3 1.26.49 1.69.62.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.18-1.42-.08-.12-.28-.2-.57-.34M12.05 21.79h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.89 9.89-9.89 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.89 6.99c0 5.45-4.43 9.89-9.88 9.89m8.41-18.3A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.69 1.45c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.17-3.48-8.42"/></svg>';
}

/** A link that opens WhatsApp with the message already written. */
function wa_link(string $text = ''): string
{
    $to = wa_number();
    if ($to === '') {
        // Nothing configured yet: the phone in the footer still works.
        return 'tel:' . preg_replace('/\D+/', '', (string) settings()['phone']);
    }
    return 'https://wa.me/' . $to . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

function settings(): array
{
    static $s = null;
    if ($s === null) {
        $stored = read_json(SETTINGS_FILE, []) ?: [];
        // These were lead_emails back when the site carried a contact form.
        $stored['admin_emails'] ??= array_values((array) ($stored['lead_emails'] ?? []));
        $s = $stored + [
            'site_title'   => 'קרני תכלת | מכירת חיסול מתצוגה',
            'description'  => 'מכירה מתצוגה של גופי תאורה בינלאומיים - עד 70% הנחה. לרגל שיפוצים באולם התצוגה.',
            'phone'        => '03-545-0200',
            'address'      => 'הנחושת 4, רמת החייל, ת״א',
            // Where a password reset link is sent. The site keeps no leads.
            'admin_emails' => [],
            'whatsapp'     => '',
            'admin_hash'   => '',
            // While the site lives on a preview address it stays out of the
            // index, so it cannot end up competing with the real domain.
            'noindex'      => false,
            'noindex_host' => '',
            // One-time password reset: only the hash is kept, and only until it expires.
            'reset_hash'    => '',
            'reset_expires' => 0,
        ];
    }
    return $s;
}

function save_settings(array $patch): void
{
    $s = settings();
    write_json(SETTINGS_FILE, array_merge($s, $patch));
}
