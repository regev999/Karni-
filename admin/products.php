<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/catalog.php';
require __DIR__ . '/../inc/xlsx.php';
require __DIR__ . '/_layout.php';
admin_require();

$rows = products_all();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'images') {
            $files = $_FILES['images'] ?? null;
            $uploads = [];
            $ok = 0;
            $failed = [];
            foreach (($files['name'] ?? []) as $i => $name) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                try {
                    $uploads[] = ingest_image([
                        'name' => $name, 'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                    ]);
                    $ok++;
                } catch (Throwable $e) {
                    $failed[] = $name . ': ' . $e->getMessage();
                }
            }
            if ($uploads) {
                products_save(catalog_add_images($rows, $uploads));
            }
            flash("נוספו/עודכנו $ok תמונות מוצר.");
            foreach (array_slice($failed, 0, 8) as $f) {
                flash($f, 'bad');
            }

        } elseif ($action === 'sheet') {
            $f = $_FILES['sheet'] ?? null;
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('לא נבחר קובץ.');
            }
            $sheet = sheet_to_products(sheet_rows($f['tmp_name'], (string) $f['name']));
            if (!$sheet) {
                throw new RuntimeException('לא נמצאו שורות מוצר בקובץ. ודאו שיש עמודות: שם מוצר, מק״ט, מחיר לפני הנחה, מחיר אחרי הנחה.');
            }
            products_save(catalog_apply_sheet($rows, $sheet));
            flash('נקלטו ' . count($sheet) . ' שורות מקובץ האקסל.');

        } elseif ($action === 'save') {
            $out = [];
            foreach (($_POST['p'] ?? []) as $p) {
                if (!empty($p['delete'])) {
                    if (!empty($p['image']) && is_file(UPLOAD_DIR . '/' . basename($p['image']))) {
                        @unlink(UPLOAD_DIR . '/' . basename($p['image']));
                    }
                    continue;
                }
                $name = trim((string) ($p['name'] ?? ''));
                if ($name === '' && trim((string) ($p['sku'] ?? '')) === '') {
                    continue;
                }
                $num = static fn($v) => trim((string) $v) === '' ? null : (int) round((float) $v);
                $out[] = [
                    'name'         => $name,
                    'sku'          => trim((string) ($p['sku'] ?? '')),
                    'price_before' => $num($p['price_before'] ?? ''),
                    'price_after'  => $num($p['price_after'] ?? ''),
                    'image'        => basename((string) ($p['image'] ?? '')),
                    'light'        => !empty($p['light']),
                ];
            }
            products_save($out);
            flash('הקטלוג נשמר (' . count($out) . ' מוצרים).');

        } elseif ($action === 'recolour') {
            $changed = 0;
            foreach ($rows as &$r) {
                $file = $r['image'] ?? '';
                if ($file === '' || !is_file(UPLOAD_DIR . '/' . $file)) {
                    continue;
                }
                $light = image_wants_light_text(UPLOAD_DIR . '/' . $file);
                if ($light !== null && (bool) ($r['light'] ?? false) !== $light) {
                    $r['light'] = $light;
                    $changed++;
                }
            }
            unset($r);
            products_save($rows);
            flash($changed ? "עודכן צבע הטקסט ב-$changed מוצרים." : 'צבע הטקסט כבר מתאים בכל המוצרים.');

        } elseif ($action === 'clear') {
            foreach ($rows as $r) {
                if (!empty($r['image']) && is_file(UPLOAD_DIR . '/' . basename($r['image']))) {
                    @unlink(UPLOAD_DIR . '/' . basename($r['image']));
                }
            }
            products_save([]);
            flash('הקטלוג רוקן.');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'bad');
    }
    header('Location: products.php');
    exit;
}

$stats = catalog_stats($rows);
layout_head('מוצרים');
flash();
?>
<h1>מוצרים <span class="muted">(<?= $stats['total'] ?> בקטלוג · <?= $stats['images'] ?> עם תמונה · <?= $stats['prices'] ?> עם מחיר)</span></h1>

<div class="cols">
  <form class="card drop" method="post" enctype="multipart/form-data" id="imgform">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="images">
    <h2>תמונות מוצרים</h2>
    <p class="muted">גררו לכאן תמונות, או בחרו קבצים. <strong>שם הקובץ הוא שם המוצר</strong> —
       למשל <code>KELVIN.jpg</code> ייצור מוצר בשם KELVIN. העלאה חוזרת של אותו שם מחליפה את התמונה.</p>
    <label class="filebtn">בחירת תמונות
      <input type="file" name="images[]" accept="image/*" multiple required>
    </label>
    <p class="filelist" data-for="images"></p>
    <button class="btn" type="submit">העלאה</button>
  </form>

  <form class="card" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="sheet">
    <h2>קובץ אקסל</h2>
    <p class="muted">עמודות: <strong>שם מוצר</strong>, <strong>מק״ט</strong>,
       <strong>מחיר לפני הנחה</strong>, <strong>מחיר אחרי הנחה</strong>.
       הכותרות מזוהות אוטומטית; בלי כותרות — לפי סדר העמודות. נתמך xlsx ו-CSV.
       סדר השורות בקובץ קובע את סדר המוצרים בדף.</p>
    <label class="filebtn">בחירת קובץ
      <input type="file" name="sheet" accept=".xlsx,.csv,.tsv,text/csv" required>
    </label>
    <p class="filelist" data-for="sheet"></p>
    <button class="btn" type="submit">קליטה</button>
  </form>
</div>

<?php if ($rows): ?>
<form method="post" class="card">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="save">
  <div class="tablebar">
    <h2>עריכת הקטלוג</h2>
    <input class="search" type="search" placeholder="סינון לפי שם או מק״ט…" id="filter">
    <button class="btn" type="submit">שמירת שינויים</button>
  </div>
  <table class="grid-table">
    <thead><tr>
      <th>תמונה</th><th>שם מוצר</th><th>מק״ט</th><th>מחיר לפני</th><th>מחיר אחרי</th>
      <th title="טקסט לבן — נקבע אוטומטית לפי התמונה, ניתן לשנות">לבן</th><th>מחיקה</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr data-search="<?= e(mb_strtolower(($r['name'] ?? '') . ' ' . ($r['sku'] ?? ''))) ?>">
        <td class="thumb">
          <?php if (!empty($r['image'])): ?>
            <img src="../<?= e(UPLOAD_URL . '/' . rawurlencode($r['image'])) ?>" alt="" loading="lazy" width="56" height="54">
            <input type="hidden" name="p[<?= $i ?>][image]" value="<?= e($r['image']) ?>">
          <?php else: ?>
            <span class="noimg">אין</span>
            <input type="hidden" name="p[<?= $i ?>][image]" value="">
          <?php endif; ?>
        </td>
        <td><input name="p[<?= $i ?>][name]" value="<?= e($r['name'] ?? '') ?>"></td>
        <td><input name="p[<?= $i ?>][sku]"  value="<?= e($r['sku'] ?? '') ?>" size="12"></td>
        <td><input name="p[<?= $i ?>][price_before]" value="<?= e((string) ($r['price_before'] ?? '')) ?>" size="7" inputmode="numeric"></td>
        <td><input name="p[<?= $i ?>][price_after]"  value="<?= e((string) ($r['price_after'] ?? '')) ?>" size="7" inputmode="numeric"></td>
        <td class="mid"><input type="checkbox" name="p[<?= $i ?>][light]" value="1"<?= !empty($r['light']) ? ' checked' : '' ?>></td>
        <td class="mid"><input type="checkbox" name="p[<?= $i ?>][delete]" value="1"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <button class="btn" type="submit">שמירת שינויים</button>
</form>

<form method="post" class="card">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="recolour">
  <h2>צבע הטקסט על הכרטיסים</h2>
  <p class="muted">שם המוצר והמחיר מודפסים על גבי התמונה. הצבע נקבע אוטומטית בכל העלאה —
     כהה לתמונות בהירות, לבן לתמונות כהות. הריצו את הבדיקה שוב אם החלפתם תמונות מחוץ למסך הזה.</p>
  <button class="btn" type="submit">זיהוי אוטומטי מחדש</button>
</form>

<form method="post" class="card danger" onsubmit="return confirm('למחוק את כל המוצרים ואת כל התמונות שהועלו? לא ניתן לבטל.');">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="clear">
  <button class="btn btn--danger" type="submit">ריקון הקטלוג</button>
</form>
<?php endif; ?>

<script src="admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>" defer></script>
<?php layout_foot();
