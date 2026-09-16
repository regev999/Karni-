<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/notify.php';
require __DIR__ . '/_layout.php';

session_start_once();
if (admin_logged_in()) {
    header('Location: products.php');
    exit;
}
// Nothing to reset before a password has been set: that screen is open anyway.
if (!admin_configured()) {
    header('Location: index.php');
    exit;
}

$sent = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $s = settings();
    $token = reset_start();
    if ($token !== null) {
        $to = array_values(array_filter((array) $s['lead_emails'],
            static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        mail_reset($to, abs_url('admin/reset.php') . '?t=' . $token, $s);
    }
    // The same answer either way: the page never says whether a mail went out,
    // how many addresses there are, or what they are.
    $sent = true;
}

layout_head('איפוס סיסמה', false);
?>
<div class="card login">
  <h1>איפוס סיסמה</h1>
  <?php if ($sent): ?>
    <p class="note">אם מוגדרת כתובת מייל לקבלת לידים, נשלח אליה קישור לבחירת סיסמה חדשה.
       הקישור תקף לחצי שעה ולשימוש אחד.</p>
    <p class="muted">לא הגיע כלום? ייתכן שלא הוגדרה כתובת מייל, או שכבר נשלח קישור
       בעשר הדקות האחרונות. תמיד אפשר גם לאפס ידנית — ראו את ההוראות ב-README.</p>
    <p><a class="btn" href="index.php">חזרה לכניסה</a></p>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <p class="muted">נשלח קישור לבחירת סיסמה חדשה לכתובות המייל שמוגדרות לקבלת לידים.</p>
      <button class="btn" type="submit">שליחת קישור איפוס</button>
      <p><a href="index.php">חזרה לכניסה</a></p>
    </form>
  <?php endif; ?>
</div>
<?php layout_foot();
