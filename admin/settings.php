<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/notify.php';
require __DIR__ . '/_layout.php';
admin_require();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'test') {
        $sent = notify_lead([
            'name' => 'בדיקה מאזור הניהול', 'phone' => '03-545-0200',
            'email' => '', 'sku' => 'TEST', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        flash($sent['mail'] === true ? 'מייל בדיקה נשלח.' :
              ($sent['mail'] === null ? 'לא הוגדרו כתובות מייל.' : 'שליחת המייל נכשלה — בדקו את הגדרות הדואר בשרת.'),
              $sent['mail'] === true ? 'ok' : 'bad');
        if ($sent['webhook'] !== null) {
            flash($sent['webhook'] ? 'ה-webhook הגיב בהצלחה.' : 'ה-webhook לא הגיב כראוי.', $sent['webhook'] ? 'ok' : 'bad');
        }
        header('Location: settings.php');
        exit;
    }

    $emails = array_values(array_filter(array_map(
        'trim',
        preg_split('/[\s,;]+/', (string) ($_POST['lead_emails'] ?? '')) ?: []
    ), static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));

    $patch = [
        'site_title'  => trim((string) ($_POST['site_title'] ?? '')),
        'description' => trim((string) ($_POST['description'] ?? '')),
        'phone'       => trim((string) ($_POST['phone'] ?? '')),
        'address'     => trim((string) ($_POST['address'] ?? '')),
        'lead_emails' => $emails,
        'webhook_url' => trim((string) ($_POST['webhook_url'] ?? '')),
        'noindex'     => !empty($_POST['noindex']),
    ];
    // Remember which address it was blocked on, so the admin can notice later
    // that the site has moved and the block is now costing traffic.
    if ($patch['noindex'] !== !empty(settings()['noindex'])) {
        $patch['noindex_host'] = $patch['noindex'] ? current_host() : '';
        flash($patch['noindex'] ? 'האתר נחסם למנועי החיפוש.' : 'האתר נפתח למנועי החיפוש.');
    }

    $pw = (string) ($_POST['password'] ?? '');
    if ($pw !== '') {
        if (mb_strlen($pw) < 8) {
            flash('הסיסמה חייבת להכיל לפחות 8 תווים — לא שונתה.', 'bad');
        } else {
            $patch['admin_hash'] = password_hash($pw, PASSWORD_DEFAULT);
            flash('סיסמת הניהול עודכנה.');
        }
    }

    save_settings($patch);
    flash('ההגדרות נשמרו.');
    header('Location: settings.php');
    exit;
}

$s = settings();
layout_head('הגדרות');
flash();
?>
<h1>הגדרות</h1>
<form class="card" method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

  <h2>לידים</h2>
  <label>כתובות מייל לקבלת לידים
    <input name="lead_emails" value="<?= e(implode(', ', (array) $s['lead_emails'])) ?>"
           placeholder="sales@example.co.il, office@example.co.il">
    <small>אפשר כמה כתובות, מופרדות בפסיק. כל ליד נשלח מיד לכולן.</small></label>
  <label>כתובת Webhook (וואטסאפ / CRM)
    <input name="webhook_url" value="<?= e((string) $s['webhook_url']) ?>" placeholder="https://hook.eu2.make.com/...">
    <small>כל ליד נשלח כ-JSON ב-POST. שימושי לחיבור ל-Make/Zapier להעברה לוואטסאפ.</small></label>

  <h2>פרטי הדף</h2>
  <label>כותרת הדף (title)<input name="site_title" value="<?= e((string) $s['site_title']) ?>"></label>
  <label>תיאור לחיפוש (description)<input name="description" value="<?= e((string) $s['description']) ?>"></label>
  <label>טלפון בפוטר<input name="phone" value="<?= e((string) $s['phone']) ?>"></label>
  <label>כתובת בפוטר<input name="address" value="<?= e((string) $s['address']) ?>"></label>

  <h2>מנועי חיפוש</h2>
  <label class="check"><input type="checkbox" name="noindex" value="1"<?= !empty($s['noindex']) ? ' checked' : '' ?>>
    <span>חסימת האתר ממנועי חיפוש ומ-AI
      <small>להשאיר דלוק כל עוד האתר יושב על כתובת זמנית, כדי שהיא לא תתחרה בדומיין הסופי.
        ביום המעבר לדומיין האמיתי — לכבות. כרגע האתר על <code><?= e(current_host()) ?></code>.</small></span></label>

  <h2>אבטחה</h2>
  <label>סיסמת ניהול חדשה<input type="password" name="password" autocomplete="new-password" placeholder="השאירו ריק כדי לא לשנות"></label>

  <button class="btn" type="submit">שמירה</button>
</form>

<form class="card" method="post">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="test">
  <h2>בדיקה</h2>
  <p class="muted">שולח ליד לדוגמה לכתובות ול-webhook שהוגדרו, בלי לשמור אותו ברשימה.</p>
  <button class="btn" type="submit">שליחת ליד בדיקה</button>
</form>
<?php layout_foot();
