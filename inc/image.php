<?php
declare(strict_types=1);

/**
 * Flattening a product photo's backdrop.
 *
 * Some of the supplier photos are shot on a light grey sweep rather than on
 * white. On the page each of those reads as a grey box sitting inside the white
 * card - the "shadow" behind the product. Lightening every pale pixel would
 * also wash out the white lampshades this catalogue is mostly made of, so the
 * backdrop is found by flooding inwards from the edge: only pixels connected to
 * the border, and close to its colour, are touched. The product is a wall the
 * flood stops at.
 */

const BACKDROP_MIN_LUMA   = 190;   // darker than this is a real scene, left alone
const BACKDROP_MAX_LUMA   = 250;   // lighter than this is white already
const BACKDROP_MIN_SHARE  = 0.15;  // a sweep fills the frame; a highlight does not
const BACKDROP_TOLERANCE  = 6;    // how far from the seed colour still counts as backdrop
const BACKDROP_NEUTRAL    = 12;    // max channel spread: a colour cast is product, not sweep

/** Luminance, the cheap way; these images are near-neutral by nature. */
function luma(int $r, int $g, int $b): int
{
    return (int) ((($r * 299) + ($g * 587) + ($b * 114)) / 1000);
}

/**
 * The brightness of the sweep this photo was shot on, or null when there isn't
 * one. The test is how much of the frame shares a single off-white, neutral
 * value: a sweep covers most of the picture, while a white lampshade's shaded
 * side is one value among many. Reading the border instead does not work here,
 * because the photo is pasted on a white card and the border is part grey,
 * part card.
 */
function backdrop_seed(\GdImage $im, int $w, int $h): ?int
{
    $hist = [];
    $seen = 0;
    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            $seen++;
            $c = imagecolorat($im, $x, $y);
            $r = ($c >> 16) & 255;
            $g = ($c >> 8) & 255;
            $b = $c & 255;
            if (max($r, $g, $b) - min($r, $g, $b) > BACKDROP_NEUTRAL) {
                continue;
            }
            $l = luma($r, $g, $b);
            if ($l >= BACKDROP_MIN_LUMA && $l <= BACKDROP_MAX_LUMA) {
                $hist[$l] = ($hist[$l] ?? 0) + 1;
            }
        }
    }
    if (!$hist || !$seen) {
        return null;
    }
    $top = array_keys($hist, max($hist))[0];
    return $hist[$top] / $seen >= BACKDROP_MIN_SHARE ? $top : null;
}

/**
 * Repaint the backdrop white. Returns true when the file was rewritten.
 * A scanline flood fill: one span at a time rather than one pixel at a time,
 * which is what keeps a 200-image upload from crawling.
 */
function flatten_backdrop(string $path): bool
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return false;
    }
    $im = @imagecreatefromstring($raw);
    if (!$im) {
        return false;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $seed = backdrop_seed($im, $w, $h);
    if ($seed === null) {
        imagedestroy($im);
        return false;
    }

    imagepalettetotruecolor($im);
    $white = imagecolorallocate($im, 255, 255, 255);
    $near = static function (int $x, int $y) use ($im, $seed): bool {
        $c = imagecolorat($im, $x, $y);
        $r = ($c >> 16) & 255;
        $g = ($c >> 8) & 255;
        $b = $c & 255;
        // Neutral as well as pale: a coloured pixel of the same brightness is
        // part of the product, not part of the sweep.
        return abs(luma($r, $g, $b) - $seed) <= BACKDROP_TOLERANCE
            && max($r, $g, $b) - min($r, $g, $b) <= 12;
    };

    $done = array_fill(0, $h, 0);          // bitmask per row, as a string of bytes
    foreach ($done as $y => $_) {
        $done[$y] = str_repeat("\0", $w);
    }
    $stack = [];
    for ($x = 0; $x < $w; $x++) {
        $stack[] = [$x, 0];
        $stack[] = [$x, $h - 1];
    }
    for ($y = 0; $y < $h; $y++) {
        $stack[] = [0, $y];
        $stack[] = [$w - 1, $y];
    }

    $painted = 0;
    while ($stack) {
        [$x, $y] = array_pop($stack);
        if ($done[$y][$x] !== "\0" || !$near($x, $y)) {
            continue;
        }
        $left = $x;
        while ($left > 0 && $done[$y][$left - 1] === "\0" && $near($left - 1, $y)) {
            $left--;
        }
        $right = $x;
        while ($right < $w - 1 && $done[$y][$right + 1] === "\0" && $near($right + 1, $y)) {
            $right++;
        }
        imageline($im, $left, $y, $right, $y, $white);
        for ($i = $left; $i <= $right; $i++) {
            $done[$y][$i] = "\1";
        }
        $painted += $right - $left + 1;
        foreach ([$y - 1, $y + 1] as $ny) {
            if ($ny < 0 || $ny >= $h) {
                continue;
            }
            for ($i = $left; $i <= $right; $i++) {
                if ($done[$ny][$i] === "\0" && $near($i, $ny)) {
                    $stack[] = [$i, $ny];
                }
            }
        }
    }

    // Nothing worth rewriting the file for.
    if ($painted < $w * $h * 0.05) {
        imagedestroy($im);
        return false;
    }

    $ok = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'webp'        => imagewebp($im, $path, 92),
        'png'         => imagepng($im, $path, 6),
        'gif'         => imagegif($im, $path),
        'avif'        => function_exists('imageavif') ? imageavif($im, $path, 70) : imagejpeg($im, $path, 92),
        default       => imagejpeg($im, $path, 92),
    };
    imagedestroy($im);
    return (bool) $ok;
}
