<?php

namespace App\Enums;

/** Semáforo del SLA: según el tiempo hábil transcurrido desde la apertura. */
enum SlaLevel: string
{
    case Green = 'verde';
    case Orange = 'naranja';
    case Critical = 'critica';

    public function label(): string
    {
        return match ($this) {
            self::Green => 'Verde',
            self::Orange => 'Naranja',
            self::Critical => 'Crítica',
        };
    }
}
