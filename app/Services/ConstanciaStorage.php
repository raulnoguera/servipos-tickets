<?php

namespace App\Services;

use App\Models\Ticket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    private const KEEP_IF_UNDER_BYTES = 600 * 1024;

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

        // Solo se guardan imágenes reales. Una foto de 12 MP ocupa ~50 MB en memoria;
        // más allá de 24 MP no se procesa para no superar el límite del hosting compartido.
        if ($width * $height === 0) {
            $this->reject('El archivo no es una imagen válida.');
        }
        if ($width * $height > self::MAX_PIXELS) {
            $this->reject('La foto tiene demasiada resolución. Tómela desde la aplicación, que la reduce automáticamente.');
        }
        // Ya viene reducida desde el teléfono: se guarda tal cual para no perder calidad ni crecer.
        if (max($width, $height) <= self::MAX_SIDE && $photo->getMimeType() === 'image/jpeg' && strlen($original) <= self::KEEP_IF_UNDER_BYTES) {
            return $original;
        }
        $image = @imagecreatefromstring($original);
        unset($original);
        if ($image === false) {
            $this->reject('No se pudo leer la imagen. Tome la foto de nuevo.');
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

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['photo' => $message]);
    }
}
