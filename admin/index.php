<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/_layout.php';

session_start_once();
if (admin_logged_in()) {
    header('Location: products.php');
    exit;
}

$error = '';
$setup = !admin_configured();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if ($setup) {
        // First run: whoever reaches the panel before a password exists sets it.
        $pw = (string) ($_POST['password'] ?? '');
        if (mb_strlen($pw) < 8) {
            $error = 'הסיסמה חייבת להכיל לפחות 8 תווים.';
        } elseif ($pw !== (string) ($_POST['password2'] ?? '')) {
            $error = 'שתי הסיסמאות אינן זהות.';
        } else {
            save_settings(['admin_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
            header('Location: index.php');
            exit;
        }
    } else {
        csrf_check();
        if (admin_login((string) ($_POST['password'] ?? ''))) {
            header('Location: products.php');
            exit;
        }
        $wait = login_locked_for();
        $error = $wait > 0
            ? 'יותר מדי ניסיונות. אפשר לנסות שוב ' . wait_text($wait) . '.'
            : 'סיסמה שגויה.';
        usleep(400000);
    }
}

layout_head($setup ? 'הגדרת סיסמה' : 'כניסה', false);
?>
<form class="card login" method="post" autocomplete="off">
  <?php if (!$setup): ?><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><?php endif; ?>
  <h1><?= $setup ? 'ברוכים הבאים' : 'כניסה לניהול' ?></h1>
  <?php if ($setup): ?>
    <p class="muted">זו הכניסה הראשונה. בחרו סיסמת ניהול — היא תישמר מוצפנת.</p>
  <?php endif; ?>
  <?php if ($error): ?><p class="note note--bad"><?= e($error) ?></p><?php endif; ?>
  <label>סיסמה<input type="password" name="password" required autofocus
         autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>"></label>
  <?php if ($setup): ?>
    <label>אימות סיסמה<input type="password" name="password2" required autocomplete="new-password"></label>
  <?php endif; ?>
  <button class="btn" type="submit"><?= $setup ? 'שמירה וכניסה' : 'כניסה' ?></button>
  <?php if (!$setup): ?><p class="muted"><a href="forgot.php">שכחתי סיסמה</a></p><?php endif; ?>
</form>
<?php layout_foot();
