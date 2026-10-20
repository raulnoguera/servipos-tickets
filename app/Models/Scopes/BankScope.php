<?php

namespace App\Models\Scopes;

use App\Models\Atm;
use App\Models\InboundEmail;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Un operador asignado a ciertos bancos solo ve los cajeros, tickets, visitas y
 * correos de esos bancos. Se aplica a toda consulta hecha en su nombre, así que
 * una pantalla o un reporte nuevo quedan restringidos sin tener que recordarlo.
 *
 * Sin usuario (tareas programadas, consola), un administrador, un técnico o un
 * operador sin bancos asignados: no hay restricción.
 */
class BankScope implements Scope
{
    /** Bancos visibles para quien hace la consulta, o null si no hay restricción. */
    public static function banks(): ?array
    {
        $user = Auth::user();

        return $user instanceof User ? $user->bankScope() : null;
    }

    public function apply(Builder $builder, Model $model): void
    {
        $banks = self::banks();
        if ($banks === null) {
            return;
        }

        match (true) {
            $model instanceof Ticket, $model instanceof Atm => $builder->whereIn($model->qualifyColumn('bank_id'), $banks),
            $model instanceof Visit => $builder->whereHas('ticket'),
            // Un correo cuyo cajero no se identificó lo puede resolver cualquier operador.
            $model instanceof InboundEmail => $builder->where(fn (Builder $q) => $q->whereNull($model->qualifyColumn('atm_id'))->orWhereHas('atm')),
            default => null,
        };
    }
}
