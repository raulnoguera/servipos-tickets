<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Technician = 'technician';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Operator => 'Operador Call Center',
            self::Technician => 'Técnico de Campo',
        };
    }
}
