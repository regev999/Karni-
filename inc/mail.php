<?php
declare(strict_types=1);

/** The one message this site sends. There is no contact form and no leads. */

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
