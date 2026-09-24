<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/catalog.php';
require __DIR__ . '/_layout.php';
admin_require();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    // The site icon is a file rather than a setting: what is on disk is the
    // whole record, so there is nothing to fall out of step with it.
    if (!empty($_POST['drop_icon']) && ($old = site_icon())) {
        @unlink($old['path']);
        flash('האייקון הוסר, חזרנו לברירת המחדל.');
    }
    $up = $_FILES['favicon'] ?? null;
    if (($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        try {
            $ext = site_icon_store($up);
            flash('אייקון האתר עודכן (' . $ext . ').');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'bad');
        }
    }

    $emails = array_values(array_filter(array_map(
        'trim',
        preg_split('/[\s,;]+/', (string) ($_POST['admin_emails'] ?? '')) ?: []
    ), static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));

    $patch = [
        'site_title'  => trim((string) ($_POST['site_title'] ?? '')),
        'description' => trim((string) ($_POST['description'] ?? '')),
        'phone'       => trim((string) ($_POST['phone'] ?? '')),
        'address'     => trim((string) ($_POST['address'] ?? '')),
        'admin_emails' => $emails,
        'whatsapp'     => trim((string) ($_POST['whatsapp'] ?? '')),
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
<form class="card" method="post" autocomplete="off" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

  <h2>וואטסאפ</h2>
  <label>מספר הוואטסאפ של העסק
    <input name="whatsapp" value="<?= e((string) $s['whatsapp']) ?>" placeholder="050-1234567" inputmode="tel">
    <small>לשם מובילים כל הכפתורים בדף. אפשר לכתוב 050-1234567 או 972501234567 — שניהם עובדים.
      <?php if (wa_number() !== ''): ?>
        כרגע: <code>wa.me/<?= e(wa_number()) ?></code>.
      <?php else: ?>
        <strong>עדיין לא הוגדר</strong> — עד שיוגדר, הכפתורים מחייגים לטלפון שבפוטר.
      <?php endif; ?></small></label>

  <h2>אייקון האתר</h2>
  <label>העלאת אייקון (favicon)
    <input type="file" name="favicon" accept=".png,.ico,.webp,.jpg,.jpeg,.gif">
    <small>הסמל הקטן שמופיע בלשונית הדפדפן, במועדפים, ובמסך הבית בטלפון.
      רצוי ריבועי, לפחות 180×180. פורמטים: png, ico, webp, jpg, gif —
      SVG לא מתקבל כאן בכוונה, כי זה הקובץ היחיד שהדף מקשר לתוך ה-head שלו.
      <?php if ($icon = site_icon()): ?>
        כרגע: <img src="../<?= e($icon['url']) ?>" alt="" width="18" height="18"
                   style="vertical-align:-4px;border-radius:3px">
        <code><?= e(basename(parse_url($icon['url'], PHP_URL_PATH) ?: '')) ?></code>.
      <?php else: ?>
        כרגע משתמשים בסמל שהגיע עם העיצוב.
      <?php endif; ?></small></label>
  <?php if (site_icon()): ?>
    <label class="check"><input type="checkbox" name="drop_icon" value="1">
      <span>הסרת האייקון וחזרה לברירת המחדל</span></label>
  <?php endif; ?>

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
  <label>מייל לשחזור סיסמה
    <input name="admin_emails" value="<?= e(implode(', ', (array) $s['admin_emails'])) ?>"
           placeholder="you@example.co.il">
    <small>לשם נשלח קישור האיפוס כשלוחצים "שכחתי סיסמה". בלי כתובת כאן אין לאן לשלוח,
      והאיפוס היחיד הוא הידני שב-README.</small></label>
  <label>סיסמת ניהול חדשה<input type="password" name="password" autocomplete="new-password" placeholder="השאירו ריק כדי לא לשנות"></label>

  <button class="btn" type="submit">שמירה</button>
</form>

<?php layout_foot();
