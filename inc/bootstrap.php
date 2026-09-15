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

// Holds the admin hash and customer details; .php so a stray direct request is inert.
define('SETTINGS_FILE', DATA_DIR . '/settings.php');
define('LEADS_FILE', DATA_DIR . '/leads.php');
define('VIEWS_FILE', DATA_DIR . '/views.php');
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

/** Message for a ?lead= code, used when the form is submitted without JavaScript. */
function lead_notice(string $code): string
{
    return [
        'ok'     => 'תודה! קיבלנו את הפרטים ונחזור אליכם בהקדם.',
        'name'   => 'נא למלא שם מלא.',
        'phone'  => 'נא למלא מספר טלפון תקין.',
        'email'  => 'כתובת המייל אינה תקינה.',
        'rate'   => 'נשלח זה עתה, נסו שוב בעוד רגע.',
        'failed' => 'השליחה נכשלה, נסו שוב או התקשרו אלינו.',
    ][$code] ?? '';
}

function settings(): array
{
    static $s = null;
    if ($s === null) {
        $s = read_json(SETTINGS_FILE, []) + [
            'site_title'   => 'קרני תכלת | מכירת חיסול מתצוגה',
            'description'  => 'מכירה מתצוגה של גופי תאורה בינלאומיים - עד 70% הנחה. לרגל שיפוצים באולם התצוגה.',
            'phone'        => '03-545-0200',
            'address'      => 'הנחושת 4, רמת החייל, ת״א',
            'lead_emails'  => [],
            'webhook_url'  => '',
            'admin_hash'   => '',
            // While the site lives on a preview address it stays out of the
            // index, so it cannot end up competing with the real domain.
            'noindex'      => false,
            'noindex_host' => '',
        ];
    }
    return $s;
}

function save_settings(array $patch): void
{
    $s = settings();
    write_json(SETTINGS_FILE, array_merge($s, $patch));
}
