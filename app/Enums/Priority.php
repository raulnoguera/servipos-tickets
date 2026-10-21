<?php

namespace App\Enums;

enum Priority: string
{
    case Low = 'baja';
    case Medium = 'media';
    case Critical = 'critica';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Baja',
            self::Medium => 'Media',
            self::Critical => 'Crítica',
        };
    }

    /** Orden para listar: la más urgente primero. */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }
}
