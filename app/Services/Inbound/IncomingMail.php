<?php

namespace App\Services\Inbound;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;
use Webklex\PHPIMAP\Message;

/** Lo que el sistema necesita de un correo, sea cual sea su origen (buzón o archivo .eml). */
final readonly class IncomingMail
{
    public function __construct(
        public string $messageId,
        public string $fromEmail,
        public ?string $fromName,
        public ?string $subject,
        public CarbonImmutable $sentAt,
        public string $text,
        /**
         * Dominios cuya autenticidad confirmó el servidor de correo de la empresa (SPF o DKIM).
         * Puede haber más de uno si el correo llegó reenviado desde otro buzón de la empresa.
         *
         * @var list<string>
         */
        public array $verifiedDomains = [],
    ) {}

    public static function fromMessage(Message $message, string $trustedAuthserv): self
    {
        $from = $message->getFrom()[0] ?? null;
        $email = strtolower(trim((string) ($from->mail ?? '')));
        $text = $message->hasTextBody() ? $message->getTextBody() : self::plain($message->getHTMLBody());
        try {
            $sentAt = CarbonImmutable::instance($message->getDate()->toDate());
        } catch (Throwable) {
            $sentAt = CarbonImmutable::now();
        }
        $id = trim((string) $message->getMessageId(), " <>\t\r\n");

        return new self(
            // Sin Message-ID (raro), una huella del contenido evita procesarlo dos veces.
            messageId: mb_substr($id !== '' ? $id : 'sin-id-'.sha1($email.'|'.$sentAt->toIso8601String().'|'.$message->getSubject()), 0, 191),
            fromEmail: $email,
            fromName: Str::limit(trim((string) ($from->personal ?? '')), 180, '') ?: null,
            subject: Str::limit(trim((string) $message->getSubject()), 480, '…') ?: null,
            sentAt: $sentAt,
            text: mb_scrub($text, 'UTF-8'),
            verifiedDomains: self::verifiedDomains($message, $trustedAuthserv),
        );
    }

    /**
     * Lee la cabecera Authentication-Results que agrega el servidor de la empresa
     * al recibir el correo. Las que no vienen de ese servidor se descartan: el
     * remitente puede escribir cualquier cabecera, esa no.
     *
     * @return list<string>
     */
    private static function verifiedDomains(Message $message, string $trustedAuthserv): array
    {
        if ($trustedAuthserv === '') {
            return [];
        }
        $domains = [];
        foreach ($message->getHeader()?->get('authentication_results')?->toArray() ?? [] as $result) {
            $result = strtolower(preg_replace('/\s+/', ' ', (string) $result));
            if (! str_ends_with(trim(strtok($result, ';')), $trustedAuthserv)) {
                continue;
            }
            if (preg_match('/\bdkim=pass\b[^;]*\bheader\.d=([a-z0-9.-]+)/', $result, $m)) {
                $domains[] = $m[1];
            }
            if (preg_match('/\bspf=pass\b[^;]*\bsmtp\.mailfrom=(?:[^@;\s]*@)?([a-z0-9.-]+)/', $result, $m)) {
                $domains[] = $m[1];
            }
        }

        return array_values(array_unique($domains));
    }

    private static function plain(string $html): string
    {
        $html = preg_replace('/<(style|script)\b.*?<\/\1>/is', '', $html);
        $html = preg_replace('/<(br|\/p|\/div|\/tr|\/li)\b[^>]*>/i', "\n", $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
