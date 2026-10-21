<?php

namespace App\Enums;

/** Zona del cajero: define las metas de SLA. */
enum Zone: string
{
    case Urban = 'urbana';
    case ExtraUrban = 'extraurbana';

    public function label(): string
    {
        return match ($this) {
            self::Urban => 'Urbana',
            self::ExtraUrban => 'Extraurbana',
        };
    }

    /** Lee la zona escrita en el Excel o en un formulario («Extra urbana», «EXTRAURBANA»…). */
    public static function fromText(?string $text): ?self
    {
        $t = preg_replace('/[^a-z]/', '', strtolower(str_replace(['Á', 'á'], 'a', (string) $text)));

        return match (true) {
            $t === '' => null,
            str_starts_with($t, 'extra') || str_starts_with($t, 'rural') => self::ExtraUrban,
            str_starts_with($t, 'urban') => self::Urban,
            default => null,
        };
    }
}
