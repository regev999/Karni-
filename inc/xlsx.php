<?php
declare(strict_types=1);

/**
 * Minimal reader for the spreadsheet formats the catalogue arrives in.
 * .xlsx is a zip of XML, so ZipArchive + SimpleXML is enough - no Composer needed.
 */

/** Read the first worksheet of an .xlsx as a list of rows of strings. */
function xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('התוסף ZipArchive אינו מותקן בשרת, לכן לא ניתן לקרוא קובצי Excel. שמרו את הקובץ כ-CSV.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('לא הצלחתי לפתוח את קובץ ה-Excel.');
    }
    try {
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sst = @simplexml_load_string($xml);
            foreach ($sst?->si ?? [] as $si) {
                // A cell's string may be split across several runs (<r><t>..).
                $shared[] = $si->t !== null && count($si->t) ? (string) $si->t
                    : implode('', array_map(static fn($r) => (string) $r->t, iterator_to_array($si->r ?? [])));
            }
        }

        $sheet = xlsx_first_sheet_path($zip);
        $xml = $zip->getFromName($sheet);
        if ($xml === false) {
            throw new RuntimeException('לא נמצא גיליון בקובץ ה-Excel.');
        }
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            throw new RuntimeException('גיליון ה-Excel פגום.');
        }

        $rows = [];
        foreach ($doc->sheetData->row ?? [] as $row) {
            $cells = [];
            foreach ($row->c ?? [] as $c) {
                $idx = xlsx_col_index((string) $c['r']);
                $type = (string) $c['t'];
                if ($type === 's') {
                    $value = $shared[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($c->is->t ?? '');
                } else {
                    $value = (string) ($c->v ?? '');
                }
                $cells[$idx] = trim($value);
            }
            if (!$cells) {
                $rows[] = [];
                continue;
            }
            $rows[] = array_map(
                static fn($i) => $cells[$i] ?? '',
                range(0, max(array_keys($cells)))
            );
        }
        return $rows;
    } finally {
        $zip->close();
    }
}

/** Follow workbook.xml -> rels to the first sheet, rather than assuming sheet1.xml. */
function xlsx_first_sheet_path(ZipArchive $zip): string
{
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $wbx = @simplexml_load_string($wb);
        $rx  = @simplexml_load_string($rels);
        $first = $wbx?->sheets?->sheet[0] ?? null;
        if ($first !== null && $rx !== false) {
            $rid = (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            foreach ($rx->Relationship as $rel) {
                if ((string) $rel['Id'] !== $rid) {
                    continue;
                }
                // A target may be package-absolute ("/xl/worksheets/sheet1.xml")
                // or relative to the workbook's own folder.
                $target = (string) $rel['Target'];
                $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                if ($zip->locateName($path) !== false) {
                    return $path;
                }
            }
        }
    }
    if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
        return 'xl/worksheets/sheet1.xml';
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $name)) {
            return $name;
        }
    }
    return 'xl/worksheets/sheet1.xml';
}

/** "BC12" -> 54 (zero-based column index). */
function xlsx_col_index(string $ref): int
{
    $n = 0;
    for ($i = 0, $len = strlen($ref); $i < $len; $i++) {
        $ch = strtoupper($ref[$i]);
        if ($ch < 'A' || $ch > 'Z') {
            break;
        }
        $n = $n * 26 + (ord($ch) - 64);
    }
    return max(0, $n - 1);
}

/** Read a CSV, coping with a BOM and with files saved as Windows-1255. */
function csv_rows(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('לא הצלחתי לקרוא את הקובץ.');
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        // Excel on a Hebrew Windows saves CSV as CP1255, which mbstring may not know.
        $converted = null;
        foreach (['Windows-1255', 'CP1255', 'ISO-8859-8'] as $enc) {
            if (function_exists('iconv')) {
                $try = @iconv($enc, 'UTF-8//IGNORE', $raw);
                if ($try !== false && $try !== '') {
                    $converted = $try;
                    break;
                }
            }
            if (in_array($enc, mb_list_encodings(), true)) {
                $converted = mb_convert_encoding($raw, 'UTF-8', $enc);
                break;
            }
        }
        $raw = $converted ?? $raw;
    }
    $delim = substr_count($raw, "\t") > substr_count($raw, ',') ? "\t" : ',';
    $rows = [];
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $rows[] = array_map(static fn($c) => trim((string) $c), $cells);
    }
    fclose($fh);
    return $rows;
}

/** Read whichever of the two formats this file is. */
function sheet_rows(string $path, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'tsv' || $ext === 'txt') {
        return csv_rows($path);
    }
    if ($ext === 'xls') {
        throw new RuntimeException('פורמט xls ישן אינו נתמך. שמרו את הקובץ כ-xlsx או כ-CSV.');
    }
    return xlsx_rows($path);
}

/**
 * Map a sheet to product rows. Columns are found by header name where possible,
 * and fall back to the documented order: name, SKU, price before, price after.
 */
function sheet_to_products(array $rows): array
{
    $aliases = [
        'name'         => ['שם מוצר', 'שם המוצר', 'שם', 'מוצר', 'name', 'product', 'product name', 'title'],
        'sku'          => ['מקט', 'מק״ט', 'מק"ט', 'מק\'\'ט', 'קוד', 'sku', 'code', 'catalog', 'catalogue'],
        'price_before' => ['מחיר לפני הנחה', 'מחיר לפני', 'מחיר מקורי', 'מחיר קטלוגי', 'לפני', 'price before', 'was', 'old price', 'list price'],
        'price_after'  => ['מחיר אחרי הנחה', 'מחיר אחרי', 'מחיר מבצע', 'מחיר סופי', 'אחרי', 'price after', 'now', 'new price', 'sale price'],
        // Optional, and the one column that decides whether a product page has
        // anything of its own to say to a search engine.
        'description'  => ['תיאור', 'תאור', 'תיאור מוצר', 'פירוט', 'הערות', 'description', 'details', 'about', 'summary'],
    ];
    $norm = static fn(string $s): string => preg_replace('/[\s"\x{05F4}\x{05F3}\'`.\-_]+/u', '', mb_strtolower($s));

    $map = [];
    $start = 0;
    foreach ($rows as $i => $row) {
        if ($i > 4) {
            break;
        }
        $found = [];
        foreach ($row as $c => $cell) {
            foreach ($aliases as $key => $names) {
                if (isset($found[$key]) || $cell === '') {
                    continue;
                }
                foreach ($names as $n) {
                    if ($norm($cell) === $norm($n)) {
                        $found[$key] = $c;
                        break 2;
                    }
                }
            }
        }
        if (count($found) >= 2) {
            $map = $found;
            $start = $i + 1;
            break;
        }
    }
    if (!$map) {
        $map = ['name' => 0, 'sku' => 1, 'price_before' => 2, 'price_after' => 3];
    }

    $money = static function (string $v): ?int {
        $v = preg_replace('/[^\d.,]/u', '', $v);
        $v = str_replace(',', '', $v);
        return $v === '' ? null : (int) round((float) $v);
    };

    $out = [];
    foreach (array_slice($rows, $start) as $row) {
        $get = static fn(string $k): string => isset($map[$k]) ? (string) ($row[$map[$k]] ?? '') : '';
        $name = trim($get('name'));
        $sku  = trim($get('sku'));
        if ($name === '' && $sku === '') {
            continue;
        }
        $out[] = [
            'name'         => $name,
            'sku'          => $sku,
            'price_before' => $money($get('price_before')),
            'price_after'  => $money($get('price_after')),
            'description'  => mb_substr(trim($get('description')), 0, 600),
        ];
    }
    return $out;
}
