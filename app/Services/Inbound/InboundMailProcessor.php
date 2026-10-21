<?php

namespace App\Services\Inbound;

use App\Models\Atm;
use App\Models\FaultType;
use App\Models\InboundEmail;
use App\Models\Ticket;
use App\Models\User;
use App\Services\PushNotifier;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Decide qué hacer con cada correo del buzón de reportes:
 *
 *  - banco conocido + cajero identificado + sin ticket abierto → abre el ticket;
 *  - el cajero ya tiene un ticket abierto → agrega el correo a ese ticket;
 *  - cualquier duda (remitente, cajero, serial) → queda "por revisar" para una persona;
 *  - remitente desconocido y sin datos de cajero → se ignora.
 *
 * Un correo se procesa una sola vez, por su Message-ID.
 */
class InboundMailProcessor
{
    /** Tipo de falla cuando el texto del banco no coincide con ninguno del catálogo. */
    public const UNCLASSIFIED = 'Por clasificar';

    public function __construct(
        private BankReportParser $parser,
        private TicketService $tickets,
        private PushNotifier $push,
    ) {}

    public function process(IncomingMail $mail, bool $notify = true): InboundEmail
    {
        if ($known = InboundEmail::where('message_id', $mail->messageId)->first()) {
            return $known;
        }

        try {
            $email = DB::transaction(fn () => $this->handle($mail));
        } catch (UniqueConstraintViolationException) {
            // Otra lectura del buzón lo procesó en el mismo instante.
            return InboundEmail::where('message_id', $mail->messageId)->firstOrFail();
        }

        if ($notify) {
            try {
                $this->announce($email);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $email;
    }

    private function handle(IncomingMail $mail): InboundEmail
    {
        $parsed = $this->parser->parse($mail->subject, $mail->text);
        $email = new InboundEmail([
            'message_id' => $mail->messageId,
            'from_email' => $mail->fromEmail,
            'from_name' => $mail->fromName,
            'subject' => $mail->subject,
            'sent_at' => $mail->sentAt->setTimezone(config('app.timezone')),
            'body' => mb_strimwidth($this->parser->ownText($mail->text), 0, 8000, '…'),
            'parsed' => $parsed,
        ]);
        $distrust = $this->distrust($mail);
        $email->trusted = $distrust === null;

        return $this->decide($email, $distrust);
    }

    /** Qué hacer con un correo, dado lo que dice y si se confía en quien lo envía. */
    private function decide(InboundEmail $email, ?string $distrust): InboundEmail
    {
        $parsed = $email->parsed;
        if (! $parsed['atm_number'] && ! $parsed['serial']) {
            return $distrust
                ? $this->close($email, InboundEmail::IGNORED, $distrust.' y el correo no menciona ningún cajero.')
                : $this->close($email, InboundEmail::REVIEW, 'No se reconoció el cajero en el correo.');
        }

        [$atm, $problem] = $this->findAtm($parsed['atm_number'], $parsed['serial']);
        $email->atm_id = $atm?->id;
        if ($distrust) {
            return $this->close($email, InboundEmail::REVIEW, $distrust.'.');
        }
        if ($problem) {
            return $this->close($email, InboundEmail::REVIEW, $problem);
        }
        if (! $atm->active) {
            return $this->close($email, InboundEmail::REVIEW, "El cajero {$atm->atm_number} está inactivo.");
        }

        if ($open = $atm->openTickets()->latest('opened_at')->first()) {
            $open->comments()->create([
                'user_id' => null,
                'body' => $this->commentBody($email),
                'created_at' => CarbonImmutable::now(),
            ]);
            $email->ticket_id = $open->id;

            return $this->close($email, InboundEmail::FOLLOW_UP, "El cajero ya tenía abierto el ticket {$open->number}.");
        }

        $email->ticket_id = $this->openTicket($email, $atm, null)->id;

        return $this->close($email, InboundEmail::TICKET);
    }

    /**
     * Vuelve a evaluar un correo que quedó por revisar, con los datos de ahora:
     * sirve cuando el cajero no existía al llegar el correo y ya se cargó. Solo
     * se reevalúan los que vinieron de un banco verificado; el resto sigue
     * esperando a una persona.
     */
    public function reevaluate(InboundEmail $email, bool $notify = true): InboundEmail
    {
        $resolved = false;
        $email = DB::transaction(function () use ($email, &$resolved) {
            $email = InboundEmail::whereKey($email->id)->lockForUpdate()->firstOrFail();
            if ($email->status !== InboundEmail::REVIEW || ! $email->trusted) {
                return $email;
            }
            $email->parsed = $this->parser->parse($email->subject, $email->body);
            $email = $this->decide($email, null);
            $resolved = $email->status !== InboundEmail::REVIEW;

            return $email;
        });

        if ($notify && $resolved) {
            try {
                $this->announce($email);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $email;
    }

    /**
     * Reevalúa todos los correos por revisar, del más antiguo al más reciente.
     *
     * @return array{evaluated: int, tickets: int, follow_ups: int, pending: int}
     */
    public function reevaluatePending(bool $notify = true): array
    {
        $result = ['evaluated' => 0, 'tickets' => 0, 'follow_ups' => 0, 'pending' => 0];
        InboundEmail::where('status', InboundEmail::REVIEW)->where('trusted', true)->orderBy('sent_at')->orderBy('id')->get()
            ->each(function (InboundEmail $email) use (&$result, $notify) {
                $result['evaluated']++;
                match ($this->reevaluate($email, $notify)->status) {
                    InboundEmail::TICKET => $result['tickets']++,
                    InboundEmail::FOLLOW_UP => $result['follow_ups']++,
                    default => null,
                };
            });
        $result['pending'] = InboundEmail::where('status', InboundEmail::REVIEW)->count();

        return $result;
    }

    /** Abre el ticket de un correo; `$by` es quien lo decide a mano desde la bandeja, o nadie si fue automático. */
    public function openTicket(InboundEmail $email, Atm $atm, ?User $by, ?int $faultTypeId = null): Ticket
    {
        $parsed = $email->parsed ?? [];
        $sender = trim(($email->from_name ?? '').' <'.$email->from_email.'>');
        $description = collect([
            ($parsed['fault'] ?? null) ? 'Falla reportada: '.$parsed['fault'] : null,
            ($parsed['observation'] ?? null) ? 'Observación: '.$parsed['observation'] : null,
            'Reportado por correo: '.$sender,
        ])->filter()->implode("\n");

        return $this->tickets->openFromEmail($atm, [
            'fault_type_id' => $faultTypeId ?? $this->faultType($parsed['fault'] ?? null)->id,
            'description' => mb_strimwidth($description, 0, 2000, '…'),
        ], CarbonImmutable::instance($email->sent_at), $by);
    }

    /** Por qué no se confía en el remitente, o null si es un banco conocido y verificado. */
    private function distrust(IncomingMail $mail): ?string
    {
        $allowed = fn (?string $domain) => $domain !== null && collect(config('inbound.domains'))
            ->contains(fn (string $bank) => $domain === $bank || str_ends_with($domain, '.'.$bank));

        if (! $allowed(Str::after($mail->fromEmail, '@'))) {
            return "El remitente {$mail->fromEmail} no es de un banco registrado";
        }
        // El dominio verificado debe ser el del propio remitente (o uno que lo contenga).
        $sender = Str::after($mail->fromEmail, '@');
        if (! collect($mail->verifiedDomains)->contains(fn (string $domain) => $allowed($domain) && ($sender === $domain || str_ends_with($sender, '.'.$domain)))) {
            return "No se pudo verificar que el correo venga realmente de {$mail->fromEmail}";
        }

        return null;
    }

    /**
     * El serial identifica al equipo; el número puede venir abreviado ("6524" por "A1916524").
     *
     * @return array{0: ?Atm, 1: ?string} cajero (o el más probable) y el motivo por el que no basta
     */
    private function findAtm(?string $number, ?string $serial): array
    {
        $bySerial = $serial ? Atm::whereRaw('UPPER(serial) = ?', [$serial])->get() : collect();
        $byNumber = collect();
        if ($number) {
            $byNumber = Atm::where('atm_number', $number)->get();
            if ($byNumber->isEmpty() && ctype_digit($number)) {
                $byNumber = Atm::where('atm_number', 'like', '%'.$number)->get();
            }
        }

        if ($bySerial->count() === 1) {
            $atm = $bySerial->first();
            if ($byNumber->isNotEmpty() && ! $byNumber->contains($atm)) {
                return [null, "El serial {$serial} es del cajero {$atm->atm_number}, pero el correo indica el ATM {$number}."];
            }

            return [$atm, null];
        }
        if ($byNumber->count() === 1) {
            $atm = $byNumber->first();
            if ($serial && $atm->serial && strtoupper($atm->serial) !== $serial) {
                return [$atm, "El serial del correo ({$serial}) no coincide con el del cajero {$atm->atm_number} ({$atm->serial})."];
            }

            return [$atm, null];
        }
        if ($byNumber->count() > 1) {
            return [null, "Hay {$byNumber->count()} cajeros cuyo número termina en {$number}."];
        }

        return [null, 'El cajero '.($number ?? $serial).' no está en el maestro de cajeros.'];
    }

    private function faultType(?string $reported): FaultType
    {
        $simple = fn (?string $text) => Str::squish(preg_replace('/\b(fallas?|de|del|la|el|en)\b/', ' ', Str::lower(Str::ascii((string) $text))));
        $needle = $simple($reported);
        if (mb_strlen($needle) >= 4) {
            foreach (FaultType::where('active', true)->orderBy('id')->get() as $type) {
                $name = $simple($type->name);
                if ($name !== '' && ($name === $needle || str_contains($needle, $name) || str_contains($name, $needle))) {
                    return $type;
                }
            }
        }

        $fallback = FaultType::firstOrCreate(['name' => self::UNCLASSIFIED]);
        if (! $fallback->active) {
            $fallback->update(['active' => true]);
        }

        return $fallback;
    }

    private function commentBody(InboundEmail $email): string
    {
        $sender = $email->from_name ?: $email->from_email;

        return mb_strimwidth("Correo del banco · {$sender}\nAsunto: {$email->subject}\n\n{$email->body}", 0, 3000, '…');
    }

    private function close(InboundEmail $email, string $status, ?string $reason = null): InboundEmail
    {
        $email->fill(['status' => $status, 'reason' => $reason ? mb_strimwidth($reason, 0, 300, '…') : null])->save();

        return $email;
    }

    private function announce(InboundEmail $email): void
    {
        match ($email->status) {
            InboundEmail::TICKET => $this->push->emailTicket($email->ticket),
            InboundEmail::FOLLOW_UP => $this->push->emailFollowUp($email->ticket, $email),
            InboundEmail::REVIEW => $this->push->emailNeedsReview($email),
            default => null,
        };
    }
}
