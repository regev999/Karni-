<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/notify.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'method']));
}

$field = static fn(string $k, int $max = 200): string
    => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);

// Bots fill every field they can see; this one is hidden from people.
if ($field('website') !== '') {
    exit(json_encode(['ok' => true]));
}

$name  = $field('name', 80);
$phone = $field('phone', 40);
$email = $field('email', 120);
$sku   = $field('sku', 60);

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
    http_response_code(422);
    exit(json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE));
}

// One submission per minute per address keeps a stuck "send" button from flooding.
$stamp = DATA_DIR . '/.rate_' . sha1($_SERVER['REMOTE_ADDR'] ?? '');
if (is_file($stamp) && time() - (int) filemtime($stamp) < 60) {
    http_response_code(429);
    exit(json_encode(['ok' => false, 'error' => 'נשלח זה עתה, נסו שוב בעוד רגע'], JSON_UNESCAPED_UNICODE));
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
    'created_at' => date('Y-m-d H:i:s'),
    'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
    'referer'    => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 300),
];

if (!lead_append($lead)) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'שמירה נכשלה'], JSON_UNESCAPED_UNICODE));
}
notify_lead($lead);

echo json_encode(['ok' => true, 'message' => 'תודה! קיבלנו את הפרטים ונחזור אליכם בהקדם.'], JSON_UNESCAPED_UNICODE);
