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
         * Dominios con una firma DKIM válida en el mensaje ({@see DkimVerifier}).
         *
         * @var list<string>
         */
        public array $verifiedDomains = [],
    ) {}

    public static function fromMessage(Message $message, DkimVerifier $dkim): self
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
            verifiedDomains: $dkim->verifiedDomains(rtrim((string) $message->getHeader()?->raw, "\r\n")."\r\n\r\n".$message->getRawBody()),
        );
    }

    private static function plain(string $html): string
    {
        $html = preg_replace('/<(style|script)\b.*?<\/\1>/is', '', $html);
        $html = preg_replace('/<(br|\/p|\/div|\/tr|\/li)\b[^>]*>/i', "\n", $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
