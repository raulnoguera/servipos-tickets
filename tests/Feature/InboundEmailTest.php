<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\FaultType;
use App\Models\InboundEmail;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Inbound\BankReportParser;
use App\Services\Inbound\DkimVerifier;
use App\Services\Inbound\InboundMailProcessor;
use App\Services\Inbound\IncomingMail;
use App\Services\Inbound\Mailbox;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

class InboundEmailTest extends TestCase
{
    use RefreshDatabase;

    private const LEGAL = "\n\n[firma]\nEste correo electrónico y cualquier archivo adjunto, son confidenciales y destinados exclusivamente…";

    private Atm $atm;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inbound.domains' => ['banco.test']]);
        $this->atm = Atm::factory()->create(['atm_number' => 'A1916524', 'serial' => 'RDC52714']);
    }

    private function mail(string $text, array $with = []): IncomingMail
    {
        return new IncomingMail(
            messageId: $with['id'] ?? uniqid('m', true).'@banco.test',
            fromEmail: $with['from'] ?? 'reportes@banco.test',
            fromName: 'Mesa de Ayuda',
            subject: $with['subject'] ?? 'Reporte ATM',
            sentAt: $with['at'] ?? CarbonImmutable::now()->subMinutes(10),
            text: $text.self::LEGAL,
            verifiedDomains: $with['verified'] ?? ['banco.test'],
        );
    }

    private function process(string $text, array $with = []): InboundEmail
    {
        return app(InboundMailProcessor::class)->process($this->mail($text, $with));
    }

    public function test_parser_reads_the_report_template_and_its_variants(): void
    {
        $parser = new BankReportParser;

        $this->assertSame(
            ['atm_number' => 'A1918189', 'agency' => 'BARCELONA CENTRO', 'serial' => 'F3301065', 'fault' => 'NO ENTRA EN SERVICIO', 'observation' => 'Verificar el ATM ya que queda la pantalla en negro'],
            $parser->parse('NO ENTRA EN SERVICIO ATM 8189', "Buenos Días.\n\nSe realiza reporte para la atención del siguiente ATM:\n\nATM A1918189 BARCELONA CENTRO\nSerial: F3301065\nFalla : NO ENTRA EN SERVICIO\nObservación: Verificar el ATM ya que queda\nla pantalla en negro.\nGracias por su gestión".self::LEGAL),
        );

        // Número en minúsculas, "Falla" sin dos puntos y un hilo interno del banco citado debajo.
        $thread = $parser->parse('RE: Falla del ATM 8049', "Buenos Dias,\r\n\r\nATM a1918049 EL VIÑEDO\r\nSerial: R3304054\r\nFalla   DISPENSACION\r\n\r\nGracias por su gestión\r\n\r\nDe: ALGUIEN [mailto:alguien@banco.test]\r\nAsunto: Falla del ATM 9999\r\n\r\nATM 9999 OTRO\r\nSerial: ZZZ99999\r\nFalla: OTRA");
        $this->assertSame(['A1918049', 'EL VIÑEDO', 'R3304054', 'DISPENSACION'], [$thread['atm_number'], $thread['agency'], $thread['serial'], $thread['fault']]);

        // Número abreviado y la falla en una línea suelta.
        $short = $parser->parse('Reporte ATM D', "Buenos días.\n\nSe reporta el siguiente ATM:\n\nATM 6524 Maracay Centro\nSerial: RDC52714\nMonitor\n\nGracias por la gestión");
        $this->assertSame(['6524', 'RDC52714', 'Monitor'], [$short['atm_number'], $short['serial'], $short['fault']]);

        // Sin datos en el cuerpo, el número sale del asunto; un saludo no es una falla.
        $subject = $parser->parse('Reporte ATM 6108 valencia sur', "Buenos días\n\nGracias por la gestión");
        $this->assertSame(['6108', null], [$subject['atm_number'], $subject['fault']]);
    }

    public function test_a_report_from_a_known_bank_opens_a_ticket_counted_from_when_it_was_sent(): void
    {
        $operator = User::factory()->create();
        $fault = FaultType::create(['name' => 'Falla de Dispensación']);
        $sentAt = CarbonImmutable::now()->subMinutes(42)->startOfSecond();

        $email = $this->process("ATM 6524 Maracay Centro\nSerial: RDC52714\nFalla: DISPENSACION\nObservación: No entrega billetes.", ['at' => $sentAt]);

        $this->assertSame(InboundEmail::TICKET, $email->status);
        $ticket = $email->ticket;
        $this->assertSame([$this->atm->id, 'email', TicketStatus::Open, $fault->id, null], [$ticket->atm_id, $ticket->source, $ticket->status, $ticket->fault_type_id, $ticket->opened_by]);
        $this->assertTrue($ticket->opened_at->equalTo($sentAt));
        $this->assertStringContainsString('Observación: No entrega billetes', $ticket->description);
        $this->assertStringContainsString('reportes@banco.test', $ticket->description);
        $this->assertSame(1, UserNotification::where('user_id', $operator->id)->where('ticket_id', $ticket->id)->count());
    }

    public function test_an_unrecognised_fault_is_filed_as_unclassified(): void
    {
        FaultType::create(['name' => 'Falla de Lectora']);

        $ticket = $this->process("ATM A1916524 MARACAY\nSerial: RDC52714\nMonitor")->ticket;

        $this->assertSame(InboundMailProcessor::UNCLASSIFIED, $ticket->faultType->name);
        $this->assertStringContainsString('Falla reportada: Monitor', $ticket->description);
    }

    public function test_the_same_email_is_processed_only_once(): void
    {
        $first = $this->process("ATM 6524\nSerial: RDC52714\nFalla: LECTORA", ['id' => 'abc@banco.test']);
        $again = $this->process("ATM 6524\nSerial: RDC52714\nFalla: LECTORA", ['id' => 'abc@banco.test']);

        $this->assertTrue($first->is($again));
        $this->assertSame(1, Ticket::count());
    }

    public function test_a_second_report_for_an_atm_with_an_open_ticket_is_added_to_that_ticket(): void
    {
        $first = $this->process("ATM 6524\nSerial: RDC52714\nFalla: LECTORA")->ticket;

        $email = $this->process("ATM 6524\nSerial: RDC52714\nFalla: LECTORA\nObservación: Sigue sin leer tarjetas.", ['subject' => 'RE: Reporte ATM 6524']);

        $this->assertSame(InboundEmail::FOLLOW_UP, $email->status);
        $this->assertTrue($email->ticket->is($first));
        $this->assertSame(1, Ticket::count());
        $this->assertStringContainsString('Sigue sin leer tarjetas', $first->comments()->sole()->body);
    }

    public function test_doubtful_emails_wait_for_a_person_instead_of_opening_tickets(): void
    {
        Atm::factory()->create(['atm_number' => 'A1926524', 'serial' => 'OTRO0001']);
        Atm::factory()->create(['atm_number' => 'A1917000', 'serial' => 'INACT001', 'active' => false]);

        $cases = [
            'no es de un banco registrado' => $this->process("ATM A1916524\nSerial: RDC52714\nFalla: X", ['from' => 'alguien@otro.test', 'verified' => ['otro.test']]),
            'No se pudo verificar' => $this->process("ATM A1916524\nSerial: RDC52714\nFalla: X", ['verified' => []]),
            'no coincide con el del cajero' => $this->process("ATM A1916524\nSerial: XXX00000\nFalla: X"),
            'pero el correo indica el ATM' => $this->process("ATM A1926524\nSerial: RDC52714\nFalla: X"),
            'cajeros cuyo número termina en 6524' => $this->process("ATM 6524 Maracay\nFalla: X"),
            'no está en el maestro' => $this->process("ATM 9999 Desconocido\nSerial: NOEXISTE1\nFalla: X"),
            'está inactivo' => $this->process("ATM A1917000\nSerial: INACT001\nFalla: X"),
            'No se reconoció el cajero' => $this->process('Buenos días, ¿nos pueden indicar el estatus de los casos pendientes?'),
        ];

        foreach ($cases as $reason => $email) {
            $this->assertSame(InboundEmail::REVIEW, $email->status, $reason);
            $this->assertStringContainsString($reason, $email->reason);
        }
        $this->assertSame(0, Ticket::count());
        // El cajero más probable queda sugerido cuando solo falla el serial o el remitente.
        $this->assertSame($this->atm->id, $cases['no coincide con el del cajero']->atm_id);

        $spam = $this->process('Oferta de préstamos.', ['from' => 'promo@otro.test', 'verified' => ['otro.test']]);
        $this->assertSame(InboundEmail::IGNORED, $spam->status);
    }

    public function test_only_a_valid_signature_from_the_bank_counts_as_verified(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $public = preg_replace('/-----[^-]+-----|\s+/', '', openssl_pkey_get_details($key)['key']);
        $dkim = new DkimVerifier(fn (string $selector, string $domain) => [$selector, $domain] === ['sel', 'banco.test'] ? $public : null);

        $from = 'From: Mesa de Ayuda <reportes@banco.test>';
        $body = "Buenos d=EDas.\r\n\r\nATM 6524 Maracay Centro\r\nSerial: RDC52714\r\nDispensaci=F3n\r\n";
        // El banco firma el remitente dos veces: así nadie puede agregar otro "From" sin romper la firma.
        $signature = 'DKIM-Signature: v=1; a=rsa-sha256; c=simple/simple; d=banco.test; s=sel; h=From:From; bh='.base64_encode(hash('sha256', $body, true)).'; b=';
        openssl_sign($from."\r\n".$signature, $signed, $key, OPENSSL_ALGO_SHA256);
        $raw = implode("\r\n", [
            // Cabecera "de verificación" que cualquiera puede escribir: no se le hace caso.
            'Authentication-Results: servidor.cualquiera.test; dkim=pass header.d=banco.test',
            $signature.base64_encode($signed),
            $from,
            'To: atm@empresa.test',
            'Subject: Reporte ATM D',
            'Date: Wed, 07 Oct 2026 14:29:32 +0000',
            'Message-ID: <firmado-1@banco.test>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset="iso-8859-1"',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            $body,
        ]);

        $this->assertSame(['banco.test'], $dkim->verifiedDomains($raw));
        $this->assertSame(['banco.test'], $dkim->verifiedDomains(str_replace("\r\n", "\n", $raw)));
        $this->assertSame([], $dkim->verifiedDomains(str_replace('RDC52714', 'RDC00000', $raw)), 'cuerpo alterado');
        $this->assertSame([], $dkim->verifiedDomains(str_replace('reportes@', 'gerencia@', $raw)), 'remitente alterado');
        $this->assertSame([], $dkim->verifiedDomains("From: Otro <otro@banco.test>\r\n".$raw), 'segundo remitente agregado');
        $this->assertSame([], $dkim->verifiedDomains(str_replace('d=banco.test; s=sel', 'd=otro.test; s=sel', $raw)), 'dominio sin esa clave');
        $this->assertSame([], $dkim->verifiedDomains(preg_replace('/^DKIM-Signature:.*\r\n/m', '', $raw)), 'sin firma');

        $mail = IncomingMail::fromMessage(Message::fromString($raw), $dkim);
        $this->assertSame(['reportes@banco.test', ['banco.test'], 'Reporte ATM D'], [$mail->fromEmail, $mail->verifiedDomains, $mail->subject]);
        $this->assertSame('2026-10-07T14:29:32+00:00', $mail->sentAt->toIso8601String());
        $this->assertSame('Dispensación', (new BankReportParser)->parse($mail->subject, $mail->text)['fault']);
        $this->assertSame(InboundEmail::TICKET, app(InboundMailProcessor::class)->process($mail)->status);

        // El mismo correo sin firma válida: solo la cabecera falsa. Espera a una persona.
        $unsigned = str_replace(['firmado-1@', 'b='.base64_encode($signed)], ['firmado-2@', 'b='.base64_encode('falsa')], $raw);
        $forged = IncomingMail::fromMessage(Message::fromString($unsigned), $dkim);
        $this->assertSame([], $forged->verifiedDomains);
        $this->assertSame(InboundEmail::REVIEW, app(InboundMailProcessor::class)->process($forged)->status);
    }

    public function test_the_scheduled_reading_leaves_a_trace_the_inbox_can_show(): void
    {
        $operator = User::factory()->create();
        $mailbox = new class($this->mail("ATM 6524\nSerial: RDC52714\nFalla: LECTORA")) extends Mailbox
        {
            public bool $down = false;

            public function __construct(private IncomingMail $mail) {}

            public function configured(): bool
            {
                return true;
            }

            public function pending(): Generator
            {
                if ($this->down) {
                    throw new RuntimeException('connection refused');
                }
                yield $this->mail;
            }
        };
        $this->app->instance(Mailbox::class, $mailbox);

        $this->travelTo('2026-10-12 12:30:00');
        $this->artisan('servipos:leer-correo')->assertSuccessful();
        $this->assertSame(1, Ticket::where('source', 'email')->count());
        $this->actingAs($operator)->getJson('/api/v1/inbound-emails')
            ->assertJsonPath('meta.reading', true)->assertJsonPath('meta.last_read_at', now()->toIso8601String())->assertJsonPath('meta.last_error', null);

        $mailbox->down = true;
        $this->travelTo('2026-10-12 12:32:00');
        $this->artisan('servipos:leer-correo')->assertFailed();
        $this->actingAs($operator)->getJson('/api/v1/inbound-emails')
            ->assertJsonPath('meta.last_error.message', 'connection refused')->assertJsonPath('meta.last_error.at', now()->toIso8601String());

        // Al volver a leer bien, el error desaparece.
        $mailbox->down = false;
        $this->artisan('servipos:leer-correo')->assertSuccessful();
        $this->actingAs($operator)->getJson('/api/v1/inbound-emails')->assertJsonPath('meta.last_error', null);
    }

    public function test_emails_that_waited_for_a_missing_atm_are_resolved_when_reevaluated(): void
    {
        $operator = User::factory()->create();
        $technician = User::factory()->technician()->create();
        $sentAt = CarbonImmutable::now()->subDay()->startOfSecond();

        // Llegan antes de que el cajero exista en el maestro.
        $waiting = $this->process("ATM 7001 Agencia Nueva\nSerial: NUEVO001\nFalla: LECTORA", ['at' => $sentAt]);
        $second = $this->process("ATM 7001 Agencia Nueva\nSerial: NUEVO001\nFalla: LECTORA\nObservación: Sigue igual.");
        $mismatch = $this->process("ATM A1916524\nSerial: XXX00000\nFalla: X");
        $untrusted = $this->process("ATM 7001\nSerial: NUEVO001\nFalla: X", ['from' => 'alguien@otro.test', 'verified' => ['otro.test']]);
        $this->assertSame([InboundEmail::REVIEW, true, false], [$waiting->status, $waiting->trusted, $untrusted->trusted]);

        $atm = Atm::factory()->create(['atm_number' => 'A1917001', 'serial' => 'NUEVO001']);

        $this->actingAs($technician)->postJson('/api/v1/inbound-emails/reevaluate')->assertForbidden();
        $this->actingAs($operator)->postJson('/api/v1/inbound-emails/reevaluate')
            ->assertOk()->assertExactJson(['evaluated' => 3, 'tickets' => 1, 'follow_ups' => 1, 'pending' => 2]);

        // El más antiguo abre el ticket, contado desde que el banco escribió; el siguiente se suma a ese ticket.
        $ticket = $waiting->refresh()->ticket;
        $this->assertSame([InboundEmail::TICKET, $atm->id, 'email', null], [$waiting->status, $ticket->atm_id, $ticket->source, $waiting->reason]);
        $this->assertTrue($ticket->opened_at->equalTo($sentAt));
        $this->assertSame([InboundEmail::FOLLOW_UP, $ticket->id], [$second->refresh()->status, $second->ticket_id]);

        // Lo que sigue siendo dudoso, y lo que no vino de un banco verificado, sigue esperando a una persona.
        $this->assertSame(InboundEmail::REVIEW, $mismatch->refresh()->status);
        $this->assertSame([InboundEmail::REVIEW, null], [$untrusted->refresh()->status, $untrusted->ticket_id]);
        $this->assertSame(1, Ticket::count());
    }

    public function test_staff_resolve_emails_waiting_for_review(): void
    {
        $operator = User::factory()->create();
        $technician = User::factory()->technician()->create();
        $fault = FaultType::create(['name' => 'Comunicación']);
        $sentAt = CarbonImmutable::now()->subHours(2)->startOfSecond();
        $email = $this->process("ATM 9999 Desconocido\nFalla: SIN COMUNICACION", ['at' => $sentAt]);
        $other = $this->process('Consulta general sobre los casos.');

        $this->actingAs($technician)->getJson('/api/v1/inbound-emails')->assertForbidden();
        $this->actingAs($operator)->getJson('/api/v1/inbound-emails?status=revisar')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.counts.revisar', 2)->assertJsonPath('meta.reading', false);

        $this->actingAs($operator)->postJson("/api/v1/inbound-emails/{$email->id}/ticket", ['atm_id' => $this->atm->id])
            ->assertCreated()->assertJsonPath('data.source', 'email');
        $ticket = $email->refresh()->ticket;
        $this->assertSame([InboundEmail::TICKET, $operator->id, $fault->id], [$email->status, $email->handled_by, $ticket->fault_type_id]);
        $this->assertSame([$this->atm->id, $operator->id], [$ticket->atm_id, $ticket->opened_by]);
        $this->assertTrue($ticket->opened_at->equalTo($sentAt));

        // Un correo ya atendido no abre un segundo ticket.
        $this->actingAs($operator)->postJson("/api/v1/inbound-emails/{$email->id}/ticket", ['atm_id' => $this->atm->id])->assertStatus(422);

        $this->actingAs($operator)->postJson("/api/v1/inbound-emails/{$other->id}/ignore")->assertOk()->assertJsonPath('data.status', 'ignorado');
        $this->assertSame(1, Ticket::count());
    }
}
