<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'abierto';
    case Assigned = 'asignado';
    case InTransit = 'en_transito';
    case OnSite = 'en_sitio';
    case Closed = 'concluido';
    case PendingPart = 'pendiente_repuesto';
    case PendingValidation = 'pendiente_validacion';
    case Cancelled = 'anulado';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierto',
            self::Assigned => 'Asignado',
            self::InTransit => 'En tránsito',
            self::OnSite => 'En sitio',
            self::Closed => 'Concluido / Operativo',
            self::PendingPart => 'Pendiente por repuesto',
            self::PendingValidation => 'Pendiente por validación / comunicación',
            self::Cancelled => 'Anulado',
        };
    }

    /** Estados en los que el ciclo terminó y el reloj se detiene. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Assigned, self::Cancelled],
            self::Assigned => [self::InTransit, self::OnSite, self::Assigned, self::Cancelled],
            self::InTransit => [self::OnSite, self::Assigned, self::Cancelled],
            self::OnSite => [self::Closed, self::PendingPart, self::PendingValidation],
            // Un ticket pendiente se reabre con una nueva visita.
            self::PendingPart, self::PendingValidation => [self::Assigned, self::InTransit, self::OnSite, self::Closed, self::Cancelled],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
