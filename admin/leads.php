<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/_layout.php';
admin_require();

$leads = leads_all();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete') {
        $drop = array_map('intval', (array) ($_POST['id'] ?? []));
        $kept = array_values(array_filter($leads, static fn($l) => !in_array((int) ($l['id'] ?? 0), $drop, true)));
        write_json(LEADS_FILE, $kept);
        flash('נמחקו ' . (count($leads) - count($kept)) . ' לידים.');
    }
    header('Location: leads.php');
    exit;
}

// Excel opens UTF-8 CSV correctly only when it starts with a BOM.
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['#', 'שם מלא', 'טלפון', 'מייל', 'מוצר', 'מק״ט', 'מקור', 'התקבל'], ',', '"', '');
    foreach ($leads as $l) {
        fputcsv($out, [
            $l['id'] ?? '', $l['name'] ?? '', $l['phone'] ?? '', $l['email'] ?? '',
            $l['product'] ?? '', $l['sku'] ?? '',
            ($l['source'] ?? '') === 'popup' ? 'חלון מוצר' : 'טופס',
            $l['created_at'] ?? '',
        ], ',', '"', '');
    }
    exit;
}

layout_head('לידים');
flash();
$leads = array_reverse($leads);
?>
<div class="tablebar">
  <h1>לידים <span class="muted">(<?= count($leads) ?>)</span></h1>
  <?php if ($leads): ?><a class="btn" href="?export=csv">ייצוא לאקסל (CSV)</a><?php endif; ?>
</div>

<?php if (!$leads): ?>
  <p class="card muted">עדיין לא התקבלו פניות מהטופס.</p>
<?php else: ?>
<form method="post" class="card" onsubmit="return confirm('למחוק את הלידים המסומנים?');">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="delete">
  <table class="grid-table">
    <thead><tr><th></th><th>#</th><th>שם מלא</th><th>טלפון</th><th>מייל</th><th>מוצר</th><th>מק״ט</th><th>מקור</th><th>התקבל</th></tr></thead>
    <tbody>
    <?php foreach ($leads as $l): ?>
      <tr>
        <td class="mid"><input type="checkbox" name="id[]" value="<?= (int) ($l['id'] ?? 0) ?>"></td>
        <td class="mid"><?= (int) ($l['id'] ?? 0) ?></td>
        <td><?= e($l['name'] ?? '') ?></td>
        <td><?php $digits = preg_replace('/\D/', '', (string) ($l['phone'] ?? '')); ?>
            <a href="tel:<?= e($digits) ?>"><?= e($l['phone'] ?? '') ?></a>
            <?php if (strlen($digits) >= 9): ?>
              <a class="wa" href="https://wa.me/972<?= e(ltrim($digits, '0')) ?>" target="_blank" rel="noopener">וואטסאפ</a>
            <?php endif; ?></td>
        <td><?php if (!empty($l['email'])): ?><a href="mailto:<?= e($l['email']) ?>"><?= e($l['email']) ?></a><?php endif; ?></td>
        <td><?= e($l['product'] ?? '') ?></td>
        <td><?= e($l['sku'] ?? '') ?></td>
        <td class="nowrap"><?= ($l['source'] ?? '') === 'popup' ? 'חלון מוצר' : 'טופס' ?></td>
        <td class="nowrap"><?= e($l['created_at'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <button class="btn btn--danger" type="submit">מחיקת המסומנים</button>
</form>
<?php endif;
layout_foot();
