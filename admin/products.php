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

        } elseif ($action === 'single') {
            $p = array_map('strval', array_intersect_key($_POST, array_flip(
                ['name', 'sku', 'price_before', 'price_after', 'note', 'description', 'where'])));
            try {
                products_save(catalog_add_one($rows, $p, (array) ($_FILES['image'] ?? []),
                                              ($p['where'] ?? '') === 'start'));
            } catch (Throwable $e) {
                // Kept for the form, so a duplicate SKU costs one field, not seven.
                $_SESSION['single_draft'] = $p;
                throw $e;
            }
            flash('המוצר ' . trim($p['name'] ?? '') . ' נוסף ל' . (($p['where'] ?? '') === 'start' ? 'תחילת' : 'סוף') . ' הקטלוג.');

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
            // The rows are keyed by their place in the catalogue, so sorting the
            // table by views on screen cannot reshuffle the page - and so each
            // posted row can be laid over the one it came from. Only the fields
            // the table actually edits travel through the browser; the photo,
            // the maker's mark and its width stay where they are.
            //
            // That is not only tidiness. PHP drops everything past
            // max_input_vars, 1000 by default, without a word: a catalogue of
            // this size sending nine fields a row went over the line, and a
            // save quietly lost the products that fell past it.
            $posted = (array) ($_POST['p'] ?? []);
            ksort($posted, SORT_NUMERIC);
            foreach ($posted as $i => $p) {
                $was = $rows[$i] ?? [];
                if (!empty($p['delete'])) {
                    if (!empty($was['image']) && is_file(UPLOAD_DIR . '/' . basename($was['image']))) {
                        @unlink(UPLOAD_DIR . '/' . basename($was['image']));
                    }
                    continue;
                }
                $name = trim((string) ($p['name'] ?? ''));
                if ($name === '' && trim((string) ($p['sku'] ?? '')) === '') {
                    continue;
                }
                $num = static fn($v) => trim((string) $v) === '' ? null : (int) round((float) $v);
                $row = [
                    'name'         => $name,
                    'sku'          => trim((string) ($p['sku'] ?? '')),
                    'price_before' => $num($p['price_before'] ?? ''),
                    'price_after'  => $num($p['price_after'] ?? ''),
                    'description'  => mb_substr(trim((string) ($p['description'] ?? '')), 0, 600),
                    'ink'          => ($p['ink'] ?? '') === 'light' ? 'light' : 'dark',
                ] + $was;
                // An unticked box sends nothing, so the flag is rebuilt from the
                // post rather than left to $was; only a sold-out row carries it.
                unset($row['sold_out']);
                if (!empty($p['sold_out'])) {
                    $row['sold_out'] = true;
                }
                $out[] = $row;
            }
            products_save($out);
            flash('הקטלוג נשמר (' . count($out) . ' מוצרים).');

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

// Pop-up openings, counted by the page itself. The row keeps its catalogue
// position in $i so that saving a table sorted by views leaves the order alone.
$rows       = with_slugs($rows);
$views      = views_all();
$viewOf     = static fn(array $r): int => (int) ($views[$r['slug']]['views'] ?? 0);
$totalViews = array_sum(array_map($viewOf, $rows));
$byViews    = ($_GET['sort'] ?? '') === 'views';
if ($byViews) {
    uasort($rows, static fn($a, $b) => $viewOf($b) <=> $viewOf($a));
}

session_start_once();
$draft = (array) ($_SESSION['single_draft'] ?? []);
unset($_SESSION['single_draft']);
$d = static fn(string $k): string => e((string) ($draft[$k] ?? ''));

layout_head('מוצרים');
flash();
?>
<h1>מוצרים <span class="muted">(<?= $stats['total'] ?> בקטלוג · <?= $stats['images'] ?> עם תמונה · <?= $stats['prices'] ?> עם מחיר<?= $stats['sold_out'] ? ' · ' . $stats['sold_out'] . ' אזלו' : '' ?>)</span></h1>

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

<form class="card single" method="post" enctype="multipart/form-data" id="single">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="single">
  <h2>הוספת מוצר בודד</h2>
  <p class="muted">מוצר אחד עם כל הפרטים והתמונה, בלי קובץ אקסל. צבע הכיתוב נקבע לפי התמונה,
     ואפשר לשנות אותו אחר כך בטבלה.</p>
  <div class="single__fields">
    <label>שם מוצר *
      <input name="name" value="<?= $d('name') ?>" required maxlength="120">
    </label>
    <label>מק״ט
      <input name="sku" value="<?= $d('sku') ?>" maxlength="40">
    </label>
    <label>מחיר לפני הנחה
      <input name="price_before" value="<?= $d('price_before') ?>" inputmode="numeric" placeholder="₪">
    </label>
    <label>מחיר אחרי הנחה
      <input name="price_after" value="<?= $d('price_after') ?>" inputmode="numeric" placeholder="₪">
    </label>
    <label>שורת הערה <small>אופציונלי — מתחת למק״ט, למשל צבע או גימור</small>
      <input name="note" value="<?= $d('note') ?>" maxlength="80">
    </label>
    <label>מיקום בדף
      <select name="where">
        <option value="end">בסוף הקטלוג</option>
        <option value="start"<?= ($draft['where'] ?? '') === 'start' ? ' selected' : '' ?>>בתחילת הקטלוג</option>
      </select>
    </label>
    <label class="single__wide">תיאור <small>אופציונלי — לגוגל ולחלון המוצר, עד 600 תווים</small>
      <textarea name="description" rows="3" maxlength="600"
                placeholder="2-3 משפטים על גוף התאורה — זה מה שגוגל מציג"><?= $d('description') ?></textarea>
    </label>
  </div>
  <label class="filebtn">בחירת תמונה *
    <input type="file" name="image" accept="image/*">
  </label>
  <p class="filelist" data-for="image"></p>
  <button class="btn" type="submit">הוספת המוצר</button>
</form>

<?php if ($rows): ?>
<form method="post" class="card">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="save">
  <div class="tablebar">
    <h2>עריכת הקטלוג</h2>
    <span class="muted"><?= $totalViews ?> פתיחות פופ-אפ</span>
    <input class="search" type="search" placeholder="סינון לפי שם או מק״ט…" id="filter">
    <button class="btn" type="submit">שמירת שינויים</button>
  </div>
  <table class="grid-table">
    <thead><tr>
      <th>תמונה</th><th>שם מוצר</th><th>מק״ט</th><th>מחיר לפני</th><th>מחיר אחרי</th>
      <th>תיאור <span class="hint">לגוגל ולחלון המוצר</span></th>
      <th class="mid">כיתוב <span class="hint">על תמונה כהה</span></th>
      <th class="mid">
        <a href="?sort=<?= $byViews ? '' : 'views' ?>#views" class="sort<?= $byViews ? ' is-on' : '' ?>"
           title="<?= $byViews ? 'חזרה לסדר הדף' : 'מיון מהנצפה ביותר' ?>">צפיות <?= $byViews ? '▾' : '↕' ?></a>
        <span class="hint">פתיחות הפופ-אפ</span>
      </th>
      <th class="mid">אזל <span class="hint">לא נפתח בדף</span></th>
      <th>מחיקה</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr data-search="<?= e(mb_strtolower(($r['name'] ?? '') . ' ' . ($r['sku'] ?? ''))) ?>"<?= !empty($r['sold_out']) ? ' class="is-out"' : '' ?>>
        <td class="thumb">
          <?php if (!empty($r['image'])): ?>
            <img src="../<?= e(upload_url($r['image'])) ?>" alt="" loading="lazy" width="56" height="54">
          <?php else: ?>
            <span class="noimg">אין</span>
          <?php endif; ?>
        </td>
        <td><input name="p[<?= $i ?>][name]" value="<?= e($r['name'] ?? '') ?>"></td>
        <td><input name="p[<?= $i ?>][sku]"  value="<?= e($r['sku'] ?? '') ?>" size="12"></td>
        <td><input name="p[<?= $i ?>][price_before]" value="<?= e((string) ($r['price_before'] ?? '')) ?>" size="7" inputmode="numeric"></td>
        <td><input name="p[<?= $i ?>][price_after]"  value="<?= e((string) ($r['price_after'] ?? '')) ?>" size="7" inputmode="numeric"></td>
        <td><textarea name="p[<?= $i ?>][description]" rows="2" maxlength="600"
                      placeholder="2-3 משפטים על גוף התאורה — זה מה שגוגל מציג"><?= e((string) ($r['description'] ?? '')) ?></textarea></td>
        <td class="mid">
          <select name="p[<?= $i ?>][ink]" title="צבע השם והמחירים על גבי התמונה">
            <option value="dark"<?= ($r['ink'] ?? 'dark') === 'dark' ? ' selected' : '' ?>>כהה</option>
            <option value="light"<?= ($r['ink'] ?? '') === 'light' ? ' selected' : '' ?>>לבן</option>
          </select>
        </td>
        <td class="mid views" title="<?= e($views[$r['slug']]['last'] ?? '') ? 'אחרונה: ' . e($views[$r['slug']]['last']) : 'טרם נפתח' ?>"><?= $viewOf($r) ?: '—' ?></td>
        <td class="mid"><input type="checkbox" name="p[<?= $i ?>][sold_out]" value="1" title="אזל מהמלאי"<?= !empty($r['sold_out']) ? ' checked' : '' ?>></td>
        <td class="mid"><input type="checkbox" name="p[<?= $i ?>][delete]" value="1"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <button class="btn" type="submit">שמירת שינויים</button>
</form>

<form method="post" class="card danger" onsubmit="return confirm('למחוק את כל המוצרים ואת כל התמונות שהועלו? לא ניתן לבטל.');">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="clear">
  <button class="btn btn--danger" type="submit">ריקון הקטלוג</button>
</form>
<?php endif; ?>

<script src="admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>" defer></script>
<?php layout_foot();
