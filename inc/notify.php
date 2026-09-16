<?php
declare(strict_types=1);

/** Notify the sales desk about a new lead: email now, webhook in the same breath. */
function notify_lead(array $lead): array
{
    $s = settings();
    $result = ['mail' => null, 'webhook' => null];

    $emails = array_filter((array) ($s['lead_emails'] ?? []), static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
    if ($emails) {
        $result['mail'] = mail_lead($emails, $lead, $s);
    }
    if (!empty($s['webhook_url'])) {
        $result['webhook'] = post_webhook((string) $s['webhook_url'], $lead);
    }
    return $result;
}

function mail_lead(array $to, array $lead, array $s): bool
{
    $host = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $subject = 'ליד חדש מדף הנחיתה — ' . ($lead['name'] ?: 'ללא שם')
        . ($lead['product'] ?? '' ? ' · ' . $lead['product'] : '');

    $rows = '';
    foreach ([
        'שם מלא' => $lead['name'] ?? '',
        'טלפון'  => $lead['phone'] ?? '',
        'מייל'   => $lead['email'] ?? '',
        'מוצר'   => $lead['product'] ?? '',
        'מק״ט'   => $lead['sku'] ?? '',
        'מקור'   => ($lead['source'] ?? '') === 'popup' ? 'חלון מוצר' : 'טופס בתחתית הדף',
        'התקבל'  => $lead['created_at'] ?? '',
    ] as $label => $value) {
        if ($value === '') {
            continue;
        }
        $rows .= '<tr><th align="right" style="padding:6px 12px;background:#f2f2f2;white-space:nowrap">'
            . e($label) . '</th><td style="padding:6px 12px">' . e((string) $value) . '</td></tr>';
    }
    $body = '<!doctype html><html dir="rtl" lang="he"><body style="font-family:Arial,sans-serif">'
        . '<h2 style="color:#00aeef">ליד חדש מדף הנחיתה</h2>'
        . '<table cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;font-size:15px">'
        . $rows . '</table>'
        . '<p style="color:#888;font-size:12px">' . e($s['site_title'] ?? '') . '</p></body></html>';

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . mb_encode_mimeheader('דף נחיתה קרני תכלת') . " <no-reply@{$host}>",
    ];
    if (filter_var($lead['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $lead['email'];
    }

    return @mail(implode(', ', $to), mb_encode_mimeheader($subject), $body, implode("\r\n", $headers));
}

/** POST the lead as JSON. Used for WhatsApp/CRM bridges such as Make or Zapier. */
function post_webhook(string $url, array $lead): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL) || !function_exists('curl_init')) {
        return false;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($lead, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 400;
}

/** The password reset link. Plain and short: it is read once and acted on. */
function mail_reset(array $to, string $url, array $s): bool
{
    $host = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $body = '<!doctype html><html dir="rtl" lang="he"><body style="font-family:Arial,sans-serif">'
        . '<h2 style="color:#00aeef">איפוס סיסמת הניהול</h2>'
        . '<p>התקבלה בקשה לאיפוס סיסמת הניהול של ' . e($s['site_title'] ?? $host) . '.</p>'
        . '<p><a href="' . e($url) . '" style="display:inline-block;padding:12px 26px;border-radius:999px;'
        . 'background:#00aeef;color:#fff;text-decoration:none;font-weight:bold">בחירת סיסמה חדשה</a></p>'
        . '<p style="color:#666;font-size:13px">הקישור תקף לחצי שעה ולשימוש אחד בלבד.<br>'
        . 'אם לא ביקשתם לאפס — אפשר להתעלם, הסיסמה הקיימת ממשיכה לעבוד.</p>'
        . '<p style="color:#aaa;font-size:12px;word-break:break-all">' . e($url) . '</p>'
        . '</body></html>';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . mb_encode_mimeheader('ניהול קרני תכלת') . " <no-reply@{$host}>",
    ];
    return @mail(implode(', ', $to), mb_encode_mimeheader('איפוס סיסמת הניהול'), $body, implode("\r\n", $headers));
}
