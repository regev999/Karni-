<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/seo.php';

/**
 * Counts one opening of a product's pop-up.
 *
 * Only the page's script calls this, which is the point: a crawler walking all
 * 117 product addresses never runs it, so the numbers stay about people. The
 * page counts each product once per browser session, so reopening the same one
 * while comparing prices does not inflate it.
 */

header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

// An unknown slug is ignored rather than stored, so the file cannot be filled
// with keys that match no product.
$slug = (string) ($_POST['p'] ?? '');
if ($slug !== '' && product_by_slug($slug)) {
    views_bump($slug);
}

http_response_code(204);
