<?php

namespace App\Services\Inbound;

use App\Models\InboundEmail;
use Carbon\CarbonImmutable;
use Generator;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Lee el buzón de reportes por IMAP. Solo lee: no marca los correos como
 * leídos, no los mueve ni los borra, porque el personal sigue usando el buzón.
 */
class Mailbox
{
    /** Cada lectura revisa los últimos días, por si el servidor estuvo sin poder leer. */
    private const LOOK_BACK_DAYS = 3;

    public function configured(): bool
    {
        return filled(config('inbound.host')) && filled(config('inbound.username'))
            && filled(config('inbound.password')) && filled(config('inbound.since'));
    }

    /** Desde cuándo se procesan correos: nunca antes de la activación. */
    public function since(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('inbound.since'))->max(CarbonImmutable::now()->subDays(self::LOOK_BACK_DAYS));
    }

    /** Prueba la conexión: cuántos correos hay en la carpeta desde la fecha de activación. */
    public function check(): int
    {
        $client = $this->connect();
        try {
            return $client->getFolderByPath(config('inbound.folder'))->query()
                ->since($this->since()->subDay())->setFetchBody(false)->leaveUnread()->count();
        } finally {
            $client->disconnect();
        }
    }

    private function connect(): Client
    {
        $client = (new ClientManager(['options' => ['fetch' => IMAP::FT_PEEK]]))->make([
            'host' => config('inbound.host'),
            'port' => config('inbound.port'),
            'encryption' => config('inbound.encryption'),
            'validate_cert' => true,
            'username' => config('inbound.username'),
            'password' => config('inbound.password'),
            'protocol' => 'imap',
            'timeout' => 20,
        ]);
        $client->connect();

        return $client;
    }

    /**
     * Correos nuevos: recibidos desde la activación y aún no procesados.
     *
     * @return Generator<int, IncomingMail>
     */
    public function pending(): Generator
    {
        $since = $this->since();
        $client = $this->connect();

        try {
            $folder = $client->getFolderByPath(config('inbound.folder'));
            // Primero solo las cabeceras: el cuerpo se baja únicamente de los correos nuevos.
            $headers = $folder->query()->since($since->subDay())->setFetchBody(false)->leaveUnread()->setFetchOrderAsc()->get();

            foreach ($headers as $header) {
                $id = trim((string) $header->getMessageId(), " <>\t\r\n");
                if ($id !== '' && InboundEmail::where('message_id', mb_substr($id, 0, 191))->exists()) {
                    continue;
                }
                /** @var Message $message */
                $message = $folder->query()->leaveUnread()->getMessageByUid($header->getUid());
                $mail = IncomingMail::fromMessage($message, config('inbound.trusted_authserv'));
                if ($mail->sentAt->greaterThanOrEqualTo($since)) {
                    yield $mail;
                }
            }
        } finally {
            $client->disconnect();
        }
    }
}
