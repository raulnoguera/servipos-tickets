<?php

namespace App\Services\Import;

use Illuminate\Support\Str;

/** Limpieza de los textos del consolidado (mayúsculas, espacios, errores de tipeo). */
class Normalizer
{
    /** Nombres canónicos de estados/regiones, indexados por su clave normalizada. */
    private const STATES = [
        'DISTRITO CAPITAL' => 'Distrito Capital',
        'MIRANDA' => 'Miranda',
        'ANZOATEGUI' => 'Anzoátegui',
        'ARAGUA' => 'Aragua',
        'CARABOBO' => 'Carabobo',
        'LARA' => 'Lara',
        'TACHIRA' => 'Táchira',
        'VARGAS' => 'Vargas',
        'LA GUAIRA' => 'Vargas',
        'BOLIVAR' => 'Bolívar',
        'APURE' => 'Apure',
        'SAN FERNANDO DE APURE' => 'Apure',
        'SUCRE' => 'Sucre',
        'YARACUY' => 'Yaracuy',
        'MONAGAS' => 'Monagas',
        'GUARICO' => 'Guárico',
        'PORTUGUESA' => 'Portuguesa',
        'POTUGUESA' => 'Portuguesa',
        'COJEDES' => 'Cojedes',
        'LOS LLANOS' => 'Los Llanos',
        'ZULIA' => 'Zulia',
        'FALCON' => 'Falcón',
        'MERIDA' => 'Mérida',
        'TRUJILLO' => 'Trujillo',
        'BARINAS' => 'Barinas',
        'NUEVA ESPARTA' => 'Nueva Esparta',
        'DELTA AMACURO' => 'Delta Amacuro',
        'AMAZONAS' => 'Amazonas',
    ];

    /** Recorta y colapsa espacios; devuelve null si queda vacío. */
    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = preg_replace('/\s+/u', ' ', trim((string) $value));

        return $value === '' ? null : $value;
    }

    /** Clave de comparación: mayúsculas y sin acentos. */
    public static function key(?string $value): string
    {
        return Str::upper(Str::ascii((string) self::text($value)));
    }

    public static function state(mixed $value): ?string
    {
        $text = self::text($value);
        if ($text === null) {
            return null;
        }

        return self::STATES[self::key($text)] ?? Str::title(Str::lower($text));
    }

    /** Repuestos en MAYÚSCULAS, como la mayoría del catálogo actual. */
    public static function part(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === null ? null : Str::upper($text);
    }

    /** "OTEVA 562", "OPTEVA562" → "OPTEVA 562". */
    public static function model(mixed $value): ?string
    {
        $text = self::text($value);
        if ($text === null) {
            return null;
        }
        if (preg_match('/^OP?TEVA\s*(\d+)$/i', $text, $m)) {
            return 'OPTEVA '.$m[1];
        }

        return Str::upper($text);
    }

    public static function yesNo(mixed $value): ?bool
    {
        return match (self::key(self::text($value))) {
            'SI' => true,
            'NO' => false,
            default => null,
        };
    }
}
