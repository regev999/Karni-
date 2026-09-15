<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/notify.php';

// The page posts this with fetch; a visitor without JavaScript gets a redirect
// back to the form instead of a screenful of JSON.
$wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

/** Reply as JSON, or bounce back to the form with a notice code. */
function reply(int $status, array $body, string $code = 'failed'): never
{
    global $wantsJson;
    http_response_code($status);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    header('Location: ../?lead=' . rawurlencode($code) . '#lead', true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['ok' => false, 'error' => 'method']);
}

$field = static fn(string $k, int $max = 200): string
    => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);

// Bots fill every field they can see; this one is hidden from people.
if ($field('website') !== '') {
    reply(200, ['ok' => true, 'message' => 'תודה!'], 'ok');
}

$name    = $field('name', 80);
$phone   = $field('phone', 40);
$email   = $field('email', 120);
$sku     = $field('sku', 60);
$product = $field('product', 120);
$source  = $field('source', 20) === 'popup' ? 'popup' : 'form';

$errors = [];
if ($name === '') {
    $errors['name'] = 'נא למלא שם מלא';
}
if (preg_match_all('/\d/', $phone) < 9) {
    $errors['phone'] = 'נא למלא מספר טלפון תקין';
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'כתובת המייל אינה תקינה';
}
if ($errors) {
    reply(422, ['ok' => false, 'errors' => $errors], (string) array_key_first($errors));
}

// One submission per minute per address keeps a stuck "send" button from flooding.
$stamp = DATA_DIR . '/.rate_' . sha1($_SERVER['REMOTE_ADDR'] ?? '');
if (is_file($stamp) && time() - (int) filemtime($stamp) < 60) {
    reply(429, ['ok' => false, 'error' => 'נשלח זה עתה, נסו שוב בעוד רגע'], 'rate');
}
@touch($stamp);

// Sweep stale stamps now and then, so the folder does not grow without bound.
if (random_int(1, 50) === 1) {
    foreach (glob(DATA_DIR . '/.rate_*') ?: [] as $old) {
        if (time() - (int) filemtime($old) > 3600) {
            @unlink($old);
        }
    }
}

$lead = [
    'name'       => $name,
    'phone'      => $phone,
    'email'      => $email,
    'sku'        => $sku,
    'product'    => $product,
    'source'     => $source,
    'created_at' => date('Y-m-d H:i:s'),
    'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
    'referer'    => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 300),
];

if (!lead_append($lead)) {
    reply(500, ['ok' => false, 'error' => 'שמירה נכשלה']);
}
notify_lead($lead);

reply(200, ['ok' => true, 'message' => 'תודה! קיבלנו את הפרטים ונחזור אליכם בהקדם.'], 'ok');
