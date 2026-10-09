<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'abierto';
    case Assigned = 'asignado';
    case InTransit = 'en_transito';
    case OnSite = 'en_sitio';
    case PendingClosure = 'por_confirmar';
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
            self::PendingClosure => 'Por confirmar cierre',
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

    /**
     * El reloj del ticket está detenido: terminó, o el técnico ya informó la solución
     * y solo falta que el administrador confirme el cierre.
     */
    public function clockStopped(): bool
    {
        return $this->isFinal() || $this === self::PendingClosure;
    }

    /**
     * Estados con el reloj detenido, para consultas.
     *
     * @return list<self>
     */
    public static function stopped(): array
    {
        return [self::Closed, self::Cancelled, self::PendingClosure];
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Assigned, self::Cancelled],
            self::Assigned => [self::InTransit, self::OnSite, self::Assigned, self::Cancelled],
            self::InTransit => [self::OnSite, self::Assigned, self::Cancelled],
            self::OnSite => [self::PendingClosure, self::PendingPart, self::PendingValidation],
            // Un ticket pendiente se reabre con una nueva visita.
            self::PendingPart, self::PendingValidation => [self::Assigned, self::InTransit, self::OnSite, self::PendingClosure, self::Cancelled],
            // El administrador confirma el cierre, lo devuelve al técnico o lo anula.
            self::PendingClosure => [self::Closed, self::Assigned, self::Cancelled],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
