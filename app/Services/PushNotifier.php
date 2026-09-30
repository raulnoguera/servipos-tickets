<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AtmCondition;
use App\Models\PushSubscription;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Notificaciones push del ciclo del ticket (RF-2.3 y seguimiento):
 * - al técnico: asignación, anulación y confirmación de cierre;
 * - a operadores y administradores: avances del técnico y cierre.
 * Si no hay claves VAPID configuradas, no hace nada.
 */
class PushNotifier
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_MINE = 'mine';

    public const SCOPE_NONE = 'none';

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

    /** "En camino" o "En sitio": avisa a operadores y administradores. */
    public function technicianMoved(Ticket $ticket, User $actor, bool $arrived, CarbonInterface $at): int
    {
        $ticket->loadMissing('atm');

        return $this->deliver($this->staffFor($ticket, $actor), [
            'title' => "{$ticket->number} · ".($arrived ? 'En sitio' : 'En camino'),
            'body' => sprintf(
                '%s %s %s (ATM %s) · %s',
                $actor->name,
                $arrived ? 'llegó a' : 'va hacia',
                $ticket->atm->agency_name,
                $ticket->atm->atm_number,
                $this->time($at),
            ),
            'url' => "/tickets/{$ticket->id}",
            'tag' => $ticket->number,
        ]);
    }

    /** Atención registrada que deja el ticket pendiente. */
    public function visitPending(Ticket $ticket, User $actor, AtmCondition $condition, CarbonInterface $at): int
    {
        $ticket->loadMissing('atm');

        return $this->deliver($this->staffFor($ticket, $actor), [
            'title' => "{$ticket->number} · {$ticket->status->label()}",
            'body' => "{$actor->name} registró la atención en {$ticket->atm->agency_name}: {$condition->name} · {$this->time($at)}",
            'url' => "/tickets/{$ticket->id}",
            'tag' => $ticket->number,
        ]);
    }

    /** Cierre: avisa a operadores/administradores y confirma al técnico que fue informado. */
    public function ticketClosed(Ticket $ticket, User $actor): int
    {
        $ticket->loadMissing(['atm', 'technician']);
        $duration = $ticket->duration_ms !== null ? ' · tiempo total '.$this->duration($ticket->duration_ms) : '';

        $sent = $this->deliver($this->staffFor($ticket, $actor), [
            'title' => "✅ {$ticket->number} concluido",
            'body' => "{$actor->name} cerró la atención en {$ticket->atm->agency_name} (ATM {$ticket->atm->atm_number}){$duration}",
            'url' => "/tickets/{$ticket->id}",
            'tag' => $ticket->number,
        ]);

        if ($ticket->technician) {
            $sent += $this->send($ticket->technician, [
                'title' => "Cierre informado · {$ticket->number}",
                'body' => "El ticket quedó concluido y el Call Center fue notificado{$duration}.",
                'url' => "/tickets/{$ticket->id}",
                'tag' => $ticket->number,
            ]);
        }

        return $sent;
    }

    /** Ticket anulado: el técnico ya no debe atenderlo. */
    public function ticketCancelled(Ticket $ticket, User $actor, ?string $reason): int
    {
        $ticket->loadMissing(['atm', 'technician']);
        if (! $ticket->technician || $ticket->technician->is($actor)) {
            return 0;
        }

        return $this->send($ticket->technician, [
            'title' => "Ticket anulado · {$ticket->number}",
            'body' => "Ya no es necesario atender el ATM {$ticket->atm->atm_number}.".($reason ? " Motivo: {$reason}" : ''),
            'url' => "/tickets/{$ticket->id}",
            'tag' => $ticket->number,
        ]);
    }

    /**
     * Operadores y administradores activos que quieren avisos de este ticket
     * (todos o solo los que abrieron), sin incluir a quien hizo la acción.
     *
     * @return Collection<int, User>
     */
    public function staffFor(Ticket $ticket, ?User $except = null): Collection
    {
        return User::query()
            ->whereIn('role', [Role::Admin, Role::Operator])
            ->where('active', true)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->where(fn ($q) => $q
                ->where('notify_scope', self::SCOPE_ALL)
                ->orWhere(fn ($mine) => $mine->where('notify_scope', self::SCOPE_MINE)->where('id', $ticket->opened_by)))
            ->whereHas('pushSubscriptions')
            ->get();
    }

    /**
     * @param  array{title: string, body: string, url?: string, tag?: string}  $message
     */
    public function send(User $user, array $message): int
    {
        return $this->deliver(collect([$user]), $message);
    }

    /**
     * Envía el mensaje a todos los dispositivos de los usuarios indicados.
     *
     * @param  Collection<int, User>  $users
     * @param  array{title: string, body: string, url?: string, tag?: string}  $message
     * @return int notificaciones entregadas
     */
    public function deliver(Collection $users, array $message): int
    {
        if (! $this->isEnabled() || $users->isEmpty()) {
            return 0;
        }
        $subscriptions = PushSubscription::whereIn('user_id', $users->pluck('id'))->get();
        if ($subscriptions->isEmpty()) {
            return 0;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ]], ['TTL' => 3600 * 12, 'urgency' => 'high']);

            $payload = json_encode($message, JSON_UNESCAPED_UNICODE);
            foreach ($subscriptions as $subscription) {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => 'aes128gcm',
                ]), $payload);
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
                    Log::warning('Push no entregado', ['user' => $subscription?->user_id, 'reason' => $report->getReason()]);
                }
            }

            return $delivered;
        } catch (Throwable $e) {
            Log::error('Error enviando push', ['users' => $users->pluck('id')->all(), 'error' => $e->getMessage()]);

            return 0;
        }
    }

    private function time(CarbonInterface $at): string
    {
        return $at->setTimezone(config('app.timezone'))->format('g:i a');
    }

    private function duration(int $ms): string
    {
        $minutes = intdiv($ms, 60000);
        if ($minutes < 60) {
            return "{$minutes} min";
        }
        $hours = intdiv($minutes, 60);

        return $hours < 24
            ? sprintf('%d h %02d min', $hours, $minutes % 60)
            : sprintf('%d d %02d h', intdiv($hours, 24), $hours % 24);
    }
}
