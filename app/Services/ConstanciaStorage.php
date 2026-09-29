<?php

namespace App\Services;

use App\Models\Ticket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda la foto de la constancia en el disco local del hosting, reducida a
 * 1600 px y JPEG de calidad 72 (~150–300 KB). La app ya la comprime en el
 * teléfono; esto es una segunda barrera por si llega una foto original.
 */
class ConstanciaStorage
{
    private const MAX_SIDE = 1600;

    private const QUALITY = 72;

    private const MAX_PIXELS = 24_000_000;

    public function store(UploadedFile $photo, Ticket $ticket): string
    {
        $path = sprintf('constancias/%s/%s-%s.jpg', now()->format('Y/m'), $ticket->number, Str::lower(Str::random(8)));
        Storage::put($path, $this->compress($photo));

        return $path;
    }

    private function compress(UploadedFile $photo): string
    {
        $original = (string) file_get_contents($photo->getRealPath());
        [$width, $height] = @getimagesizefromstring($original) ?: [0, 0];

        // Una foto de 12 MP ocupa ~50 MB en memoria; más allá de 24 MP no se procesa
        // para no superar el límite del hosting compartido.
        if ($width * $height === 0 || $width * $height > self::MAX_PIXELS) {
            return $original;
        }
        $image = @imagecreatefromstring($original);
        unset($original);
        if ($image === false) {
            return (string) file_get_contents($photo->getRealPath());
        }

        $scale = min(1, self::MAX_SIDE / max($width, $height));
        if ($scale < 1) {
            $scaled = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
            imagedestroy($image);
            $image = $scaled;
        }

        ob_start();
        imagejpeg($image, null, self::QUALITY);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
