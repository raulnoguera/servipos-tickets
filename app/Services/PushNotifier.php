<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Envía notificaciones push a los dispositivos del usuario (RF-2.3).
 * Si no hay claves VAPID configuradas, no hace nada: la app sigue
 * funcionando y el aviso queda solo por WhatsApp.
 */
class PushNotifier
{
    public function isEnabled(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    public function ticketAssigned(Ticket $ticket): int
    {
        $ticket->loadMissing(['atm', 'faultType', 'technician']);
        if (! $ticket->technician) {
            return 0;
        }

        return $this->send($ticket->technician, [
            'title' => "Nuevo ticket {$ticket->number}",
            'body' => implode(' · ', array_filter([
                "ATM {$ticket->atm->atm_number}",
                $ticket->atm->agency_name,
                $ticket->faultType?->name,
            ])),
            'url' => "/tickets/{$ticket->id}",
            'tag' => $ticket->number,
        ]);
    }

    /**
     * @param  array{title: string, body: string, url?: string, tag?: string}  $message
     * @return int notificaciones entregadas
     */
    public function send(User $user, array $message): int
    {
        $subscriptions = $user->pushSubscriptions()->get();
        if (! $this->isEnabled() || $subscriptions->isEmpty()) {
            return 0;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ]], ['TTL' => 3600 * 12, 'urgency' => 'high']);

            foreach ($subscriptions as $subscription) {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => 'aes128gcm',
                ]), json_encode($message, JSON_UNESCAPED_UNICODE));
            }

            $delivered = 0;
            foreach ($webPush->flush() as $report) {
                $subscription = $subscriptions->firstWhere('endpoint', $report->getEndpoint());
                if ($report->isSuccess()) {
                    $delivered++;
                    $subscription?->update(['last_used_at' => now()]);
                } elseif ($report->isSubscriptionExpired()) {
                    // El usuario desinstaló la app o revocó el permiso.
                    $subscription?->delete();
                } else {
                    Log::warning('Push no entregado', ['user' => $user->id, 'reason' => $report->getReason()]);
                }
            }

            return $delivered;
        } catch (Throwable $e) {
            Log::error('Error enviando push', ['user' => $user->id, 'error' => $e->getMessage()]);

            return 0;
        }
    }
}
