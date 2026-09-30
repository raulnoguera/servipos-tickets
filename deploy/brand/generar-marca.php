<?php
/**
 * Genera las imágenes de marca a partir del logo corporativo (deploy/brand/logo-original.png):
 *   public/brand/mark.png            símbolo "SP" con fondo transparente
 *   public/brand/wordmark.png        texto "ServiPos 2019" negro, fondo transparente
 *   public/brand/wordmark-white.png  texto en blanco para fondos oscuros
 *   public/brand/logo.png            logo completo, fondo transparente
 *   public/icons/*                   íconos de la app y favicon (símbolo sobre blanco)
 * Uso: php deploy/brand/generar-marca.php
 */
$root = dirname(__DIR__, 2);
$src = imagecreatefrompng(__DIR__.'/logo-original.png');
imagepalettetotruecolor($src);

/** "Color a transparencia": el blanco del fondo pasa a transparente conservando bordes suaves. */
function whiteToAlpha(GdImage $im): GdImage
{
    $w = imagesx($im); $h = imagesy($im);
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
            // Cuánto se aleja del blanco (0 = blanco puro).
            $a = max(255 - $r, 255 - $g, 255 - $b) / 255;
            // Ruido de compresión casi blanco: transparente del todo.
            if ($a < 0.12) {
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 255, 255, 255, 127));
                continue;
            }
            $a = min(1, ($a - 0.12) / 0.78);
            $un = fn ($v) => (int) max(0, min(255, round(($v - 255 * (1 - $a)) / max($a, 0.001))));
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $un($r), $un($g), $un($b), (int) round(127 * (1 - $a))));
        }
    }
    return $out;
}

/** Borra manchas sueltas (grupos de menos de $min píxeles visibles), ruido de la imagen original. */
function removeSpecks(GdImage $im, int $min = 30): GdImage
{
    $w = imagesx($im); $h = imagesy($im);
    $visible = fn ($x, $y) => ((imagecolorat($im, $x, $y) >> 24) & 127) < 110;
    $seen = [];
    $clear = imagecolorallocatealpha($im, 255, 255, 255, 127);
    imagealphablending($im, false);
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        if (isset($seen[$y * $w + $x]) || ! $visible($x, $y)) { continue; }
        $stack = [[$x, $y]]; $group = []; $seen[$y * $w + $x] = true;
        while ($stack) {
            [$cx, $cy] = array_pop($stack); $group[] = [$cx, $cy];
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [-1, -1], [1, -1], [-1, 1]] as [$dx, $dy]) {
                $nx = $cx + $dx; $ny = $cy + $dy;
                if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h || isset($seen[$ny * $w + $nx]) || ! $visible($nx, $ny)) { continue; }
                $seen[$ny * $w + $nx] = true; $stack[] = [$nx, $ny];
            }
        }
        if (count($group) < $min) {
            foreach ($group as [$gx, $gy]) {
                // borra el grupo y su halo semitransparente
                for ($oy = -1; $oy <= 1; $oy++) for ($ox = -1; $ox <= 1; $ox++) {
                    if ($gx + $ox >= 0 && $gy + $oy >= 0 && $gx + $ox < $w && $gy + $oy < $h) { imagesetpixel($im, $gx + $ox, $gy + $oy, $clear); }
                }
            }
        }
    }
    return $im;
}

function crop(GdImage $im, int $x, int $y, int $w, int $h): GdImage
{
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopy($out, $im, 0, 0, $x, $y, $w, $h);
    return $out;
}

/** Recorta al contenido visible (quita márgenes transparentes). */
function trimTransparent(GdImage $im): GdImage
{
    $w = imagesx($im); $h = imagesy($im);
    [$x1, $y1, $x2, $y2] = [$w, $h, 0, 0];
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        if (((imagecolorat($im, $x, $y) >> 24) & 127) < 120) { $x1 = min($x1, $x); $y1 = min($y1, $y); $x2 = max($x2, $x); $y2 = max($y2, $y); }
    }
    return crop($im, $x1, $y1, $x2 - $x1 + 1, $y2 - $y1 + 1);
}

/** Pinta de blanco lo oscuro (texto) conservando la transparencia. */
function recolorWhite(GdImage $im): GdImage
{
    $w = imagesx($im); $h = imagesy($im);
    $out = crop($im, 0, 0, $w, $h);
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $alpha = (imagecolorat($im, $x, $y) >> 24) & 127;
        imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 255, 255, 255, $alpha));
    }
    return $out;
}

/** Escala con buena calidad (en pasos de 2x para suavizar al agrandar). */
function scale(GdImage $im, int $w, int $h): GdImage
{
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, imagesx($im), imagesy($im));
    return $out;
}

/** Ícono cuadrado: símbolo centrado sobre blanco, ocupando $ratio del lado. */
function icon(GdImage $mark, int $size, float $ratio, bool $rounded): GdImage
{
    $s = 4; $W = $size * $s;
    $canvas = imagecreatetruecolor($W, $W);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    imagealphablending($canvas, true);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    if ($rounded) {
        $r = (int) ($W * 0.22);
        imagefilledrectangle($canvas, $r, 0, $W - $r, $W, $white);
        imagefilledrectangle($canvas, 0, $r, $W, $W - $r, $white);
        foreach ([[$r, $r], [$W - $r, $r], [$r, $W - $r], [$W - $r, $W - $r]] as [$cx, $cy]) imagefilledellipse($canvas, $cx, $cy, $r * 2, $r * 2, $white);
    } else {
        imagefilledrectangle($canvas, 0, 0, $W, $W, $white);
    }
    $mh = (int) ($W * $ratio); $mw = (int) round($mh * imagesx($mark) / imagesy($mark));
    imagecopyresampled($canvas, $mark, (int) (($W - $mw) / 2), (int) (($W - $mh) / 2), 0, 0, $mw, $mh, imagesx($mark), imagesy($mark));
    return scale($canvas, $size, $size);
}

$clean = removeSpecks(whiteToAlpha($src));
$mark = trimTransparent(crop($clean, 0, 0, 140, imagesy($clean)));
$wordmark = trimTransparent(crop($clean, 140, 0, imagesx($clean) - 140, imagesy($clean)));
$logo = trimTransparent($clean);

$save = function (GdImage $im, string $path) use ($root) {
    imagepng($im, "$root/$path", 9);
    printf("%-34s %dx%d\n", $path, imagesx($im), imagesy($im));
};
$save($mark, 'public/brand/mark.png');
$save($wordmark, 'public/brand/wordmark.png');
$save(recolorWhite($wordmark), 'public/brand/wordmark-white.png');
$save($logo, 'public/brand/logo.png');

$save(icon($mark, 192, 0.78, true), 'public/icons/icon-192.png');
$save(icon($mark, 512, 0.78, true), 'public/icons/icon-512.png');
$save(icon($mark, 512, 0.58, false), 'public/icons/icon-maskable-512.png');
$save(icon($mark, 180, 0.74, false), 'public/icons/apple-touch-icon.png');
$save(scale($mark, (int) round(32 * imagesx($mark) / imagesy($mark)), 32), 'public/icons/favicon-32.png');
copy("$root/public/icons/favicon-32.png", "$root/public/favicon.png");
