<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/_layout.php';

session_start_once();

// The token travels in the query on the way in and in the form on the way back,
// so a reload after the password is set cannot replay it - it is gone by then.
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$error = '';

if (!reset_valid($token)) {
    layout_head('איפוס סיסמה', false);
    ?>
    <div class="card login">
      <h1>הקישור אינו תקף</h1>
      <p class="note note--bad">קישור האיפוס פג או כבר שימש. אפשר לבקש קישור חדש.</p>
      <p><a class="btn" href="forgot.php">בקשת קישור חדש</a></p>
    </div>
    <?php
    layout_foot();
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $pw = (string) ($_POST['password'] ?? '');
    if (mb_strlen($pw) < 8) {
        $error = 'הסיסמה חייבת להכיל לפחות 8 תווים.';
    } elseif ($pw !== (string) ($_POST['password2'] ?? '')) {
        $error = 'שתי הסיסמאות אינן זהות.';
    } else {
        reset_complete($pw);
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        flash('הסיסמה הוחלפה.');
        header('Location: products.php');
        exit;
    }
}

layout_head('סיסמה חדשה', false);
?>
<form class="card login" method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="t" value="<?= e($token) ?>">
  <h1>בחירת סיסמה חדשה</h1>
  <?php if ($error): ?><p class="note note--bad"><?= e($error) ?></p><?php endif; ?>
  <label>סיסמה חדשה<input type="password" name="password" required autofocus autocomplete="new-password"></label>
  <label>אימות סיסמה<input type="password" name="password2" required autocomplete="new-password"></label>
  <button class="btn" type="submit">שמירה וכניסה</button>
</form>
<?php layout_foot();
