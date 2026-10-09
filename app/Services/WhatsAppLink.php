<?php

namespace App\Services;

use App\Models\Ticket;

/**
 * Enlace "click to chat" de WhatsApp con el resumen del ticket ya escrito.
 * Es el canal alterno a la notificación push: no depende de APIs no oficiales.
 */
class WhatsAppLink
{
    public static function forTicket(Ticket $ticket): ?string
    {
        // Números venezolanos locales (0414…) → formato internacional (58414…).
        $phone = $ticket->technician?->whatsappNumber();
        if ($phone === null) {
            return null;
        }

        $atm = $ticket->atm;
        $lines = array_filter([
            "*Ticket {$ticket->number}* — ServiPos 2019",
            "ATM: {$atm->atm_number}".($atm->model ? " ({$atm->model})" : ''),
            'Agencia: '.trim(($atm->agency_code ? "{$atm->agency_code} - " : '').$atm->agency_name),
            $atm->state?->name ? "Estado: {$atm->state->name}" : null,
            $atm->address ? "Dirección: {$atm->address}" : null,
            $ticket->faultType ? "Falla: {$ticket->faultType->name}" : null,
            $ticket->description ? "Detalle: {$ticket->description}" : null,
            'Ver en la app: '.url("/tickets/{$ticket->id}"),
        ]);

        return 'https://wa.me/'.$phone.'?text='.rawurlencode(implode("\n", $lines));
    }
}
