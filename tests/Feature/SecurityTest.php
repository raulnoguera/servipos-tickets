<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\AuditLog;
use App\Models\PushSubscription;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Reports\TableExporter;
use App\Services\TicketService;
use Database\Seeders\AtmConditionSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Las peticiones del portal llevan su origen; así Sanctum inicia la sesión.
        $this->withHeader('Referer', config('app.url'));
    }

    public function test_login_without_browser_session_is_rejected_cleanly(): void
    {
        $this->withHeader('Referer', 'https://evil.example')
            ->postJson('/api/v1/auth/login', ['email' => 'x@y.com', 'password' => 'x'])
            ->assertStatus(400);
    }

    public function test_responses_carry_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        $this->assertFalse($response->headers->has('X-Powered-By'));
        // También en la página, por si un proxy o CDN reemplaza la cabecera.
        $response->assertSee('<meta http-equiv="Content-Security-Policy" content="default-src &#039;self&#039;; script-src &#039;self&#039;', false);

        $this->getJson('/api/v1/auth/me')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_cors_only_allows_the_portal_origin(): void
    {
        config(['cors.allowed_origins' => ['https://portal.servipos.test']]);

        $evil = $this->call('OPTIONS', '/api/v1/auth/login', server: [
            'HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertNotSame('https://evil.example', $evil->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $evil->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_account_is_locked_after_five_failed_logins(): void
    {
        $user = User::factory()->create(['email' => 'ana@servipos.test']);
        RateLimiter::clear('login:ana@servipos.test|127.0.0.1');

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/login', ['email' => 'ana@servipos.test', 'password' => self::otraClave()])
                ->assertJsonPath('errors.email.0', 'Usuario o contraseña incorrectos.');
        }

        // Incluso con la contraseña correcta queda bloqueada unos minutos.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $this->claveDePrueba()])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn ($msg) => str_starts_with($msg, 'Demasiados intentos fallidos'));
    }

    public function test_successful_login_resets_failed_attempts(): void
    {
        $user = User::factory()->create(['email' => 'luis@servipos.test']);
        foreach (range(1, 4) as $i) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::otraClave()]);
        }
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $this->claveDePrueba()])->assertOk();
        $this->assertSame(0, RateLimiter::attempts('login:luis@servipos.test|127.0.0.1'));
    }

    public function test_deactivated_user_loses_access_immediately(): void
    {
        $technician = User::factory()->technician()->create();
        $this->actingAs($technician)->getJson('/api/v1/tickets')->assertOk();

        $technician->update(['active' => false]);

        $this->actingAs($technician->refresh())->getJson('/api/v1/tickets')->assertUnauthorized();
    }

    public function test_new_passwords_must_be_strong(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Nuevo', 'email' => 'nuevo@servipos.test', 'role' => 'technician', 'password' => '12345678',
        ])->assertJsonValidationErrors('password');
    }

    public function test_exported_text_is_never_turned_into_a_formula(): void
    {
        $this->seed(AtmConditionSeeder::class);
        $technician = User::factory()->technician()->create();
        $ticket = Ticket::create([
            'number' => 'TK-2026-00001', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::Assigned,
            'assigned_to' => $technician->id, 'opened_at' => now(),
        ]);
        $attack = '=HYPERLINK("https://evil.example","Clic aquí")';
        $this->actingAs($technician)->postJson("/api/v1/tickets/{$ticket->id}/visits", [
            'photo' => UploadedFile::fake()->image('constancia.jpg'),
            'work_done' => $attack, 'atm_condition_id' => AtmCondition::first()->id, 'parts_changed' => false,
        ])->assertOk();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get('/api/v1/reports/detail?type=visits&format=xlsx&from='.now()->toDateString().'&to='.now()->toDateString());
        $sheet = IOFactory::load($response->getFile()->getPathname())->getActiveSheet();

        $cell = null;
        foreach ($sheet->getRowIterator(8) as $row) {
            foreach ($row->getCellIterator() as $c) {
                if ($c->getValue() === $attack) {
                    $cell = $c;
                }
            }
        }
        $this->assertNotNull($cell, 'el texto se exporta tal cual');
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), 'como texto, no como fórmula');
        $this->assertTrue(method_exists(TableExporter::class, 'writeRow'));
    }

    public function test_negative_page_size_cannot_dump_everything(): void
    {
        $operator = User::factory()->create();
        $atm = Atm::factory()->create();
        foreach (range(1, 3) as $i) {
            Ticket::create(['number' => "TK-2026-0000{$i}", 'atm_id' => $atm->id, 'status' => TicketStatus::Open, 'opened_at' => now()]);
        }

        $this->actingAs($operator)->getJson('/api/v1/tickets?per_page=-1')->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonCount(1, 'data');
        $this->actingAs($operator)->getJson('/api/v1/atms?per_page=-1')->assertOk()->assertJsonPath('meta.per_page', 1);
    }

    public function test_push_endpoint_must_be_a_real_push_service(): void
    {
        $user = User::factory()->technician()->create();
        $keys = ['p256dh' => 'clave', 'auth' => 'secreto'];

        foreach (['http://127.0.0.1:6379/', 'https://10.0.0.5/x', 'https://evil.example/fcm.googleapis.com', 'https://fcm.googleapis.com.evil.example/x', 'https://fcm.googleapis.com:8443/x'] as $endpoint) {
            $this->actingAs($user)->postJson('/api/v1/push/subscriptions', ['endpoint' => $endpoint, 'keys' => $keys])
                ->assertJsonValidationErrors('endpoint');
        }
        $this->actingAs($user)->postJson('/api/v1/push/subscriptions', ['endpoint' => 'https://web.push.apple.com/abc', 'keys' => $keys])->assertCreated();
    }

    public function test_someone_elses_device_cannot_be_hijacked_by_knowing_its_endpoint(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->technician()->create();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';
        $this->actingAs($victim)->postJson('/api/v1/push/subscriptions', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'clave', 'auth' => 'secreto']])->assertCreated();

        $this->actingAs($attacker)->postJson('/api/v1/push/subscriptions', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'otra', 'auth' => 'adivinada']])->assertStatus(409);
        $this->assertSame($victim->id, PushSubscription::firstWhere('endpoint', $endpoint)->user_id);
    }

    public function test_deactivating_a_user_revokes_remember_me_and_devices(): void
    {
        $admin = User::factory()->admin()->create();
        $technician = User::factory()->technician()->create(['remember_token' => 'token-anterior']);
        $technician->pushSubscriptions()->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'public_key' => 'k', 'auth_token' => 'a']);

        $this->actingAs($admin)->putJson("/api/v1/users/{$technician->id}", [
            'name' => $technician->name, 'email' => $technician->email, 'phone' => $technician->phone, 'role' => 'technician', 'active' => false,
        ])->assertOk();

        $technician->refresh();
        $this->assertNotSame('token-anterior', $technician->remember_token);
        $this->assertSame(0, $technician->pushSubscriptions()->count());
    }

    public function test_times_sent_with_signal_use_the_server_clock(): void
    {
        $this->seed(AtmConditionSeeder::class);
        $technician = User::factory()->technician()->create();
        $ticket = Ticket::create([
            'number' => 'TK-2026-00001', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::Assigned,
            'assigned_to' => $technician->id, 'opened_at' => now()->subHours(5), 'assigned_at' => now()->subHours(4),
        ]);

        // Llega 4 horas después de la asignación, pero declara que llegó y terminó casi de inmediato.
        $this->actingAs($technician)->postJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'en_sitio', 'at' => now()->subHours(4)->addMinutes(2)->toIso8601String()])->assertOk();
        $this->actingAs($technician)->postJson("/api/v1/tickets/{$ticket->id}/visits", [
            'photo' => UploadedFile::fake()->image('constancia.jpg'),
            'work_done' => 'Cambio de dispensador', 'atm_condition_id' => AtmCondition::where('resulting_ticket_status', 'concluido')->first()->id,
            'parts_changed' => false, 'finished_at' => now()->subHours(4)->addMinutes(3)->toIso8601String(),
            'arrived_at' => now()->subHours(4)->toIso8601String(), // ya no se acepta del cliente
        ])->assertOk();

        $ticket->refresh();
        $this->assertTrue($ticket->responded_at->greaterThan(now()->subMinutes(2)), 'la llegada es la hora del servidor');
        $this->assertTrue($ticket->closed_at->greaterThan(now()->subMinutes(2)), 'el cierre es la hora del servidor');
        $this->assertGreaterThan(4 * 3600 * 1000, $ticket->duration_ms);
    }

    public function test_times_saved_without_signal_are_accepted_but_flagged_as_late(): void
    {
        $technician = User::factory()->technician()->create();
        $ticket = Ticket::create([
            'number' => 'TK-2026-00001', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::Assigned,
            'assigned_to' => $technician->id, 'opened_at' => now()->subHours(5), 'assigned_at' => now()->subHours(4),
        ]);
        $claimed = now()->subHours(2);

        $response = $this->actingAs($technician)->postJson("/api/v1/tickets/{$ticket->id}/status", [
            'status' => 'en_transito', 'at' => $claimed->toIso8601String(), 'queued' => true,
        ])->assertOk();

        $event = collect($response->json('data.events'))->firstWhere('to', 'en_transito');
        $this->assertSame($claimed->toIso8601String(), $event['at'], 'se respeta la hora en que ocurrió');
        $this->assertNotNull($event['received_at'], 'pero queda la hora en que llegó al servidor');
        $this->assertGreaterThan(110 * 60 * 1000, $event['late_ms']);
    }

    public function test_reports_reject_huge_date_ranges(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/v1/reports/summary?from=1900-01-01&to=2100-01-01')->assertJsonValidationErrors('from');
        $this->actingAs($admin)->getJson('/api/v1/reports/summary?from='.now()->subYear()->toDateString().'&to='.now()->toDateString())->assertOk();
    }

    public function test_technician_cannot_follow_a_ticket_reassigned_to_someone_else(): void
    {
        [$before, $now] = User::factory()->technician()->count(2)->create();
        $ticket = Ticket::create([
            'number' => 'TK-2026-00001', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::Assigned,
            'assigned_to' => $now->id, 'opened_at' => now(),
        ]);
        $ticket->visits()->create(['technician_id' => $before->id, 'visit_date' => now(), 'work_done' => 'Revisión']);

        $this->actingAs($before)->postJson("/api/v1/tickets/{$ticket->id}/watch")->assertForbidden();
        $this->actingAs($before)->deleteJson("/api/v1/tickets/{$ticket->id}/watch")->assertOk();
    }

    public function test_account_is_locked_when_guessed_from_many_ips(): void
    {
        $user = User::factory()->create(['email' => 'carla@servipos.test']);
        RateLimiter::clear('login-cuenta:carla@servipos.test');

        foreach (range(1, 30) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::otraClave()])
                ->assertJsonPath('errors.email.0', 'Usuario o contraseña incorrectos.');
        }

        // Desde una IP nueva y con la contraseña correcta: la cuenta sigue bloqueada.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.9.9'])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $this->claveDePrueba()])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn ($msg) => str_starts_with($msg, 'Demasiados intentos fallidos'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.lockout']);
    }

    public function test_sensitive_actions_are_audited_without_secrets(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'jefe@servipos.test']);
        $technician = User::factory()->technician()->create();

        $nueva = self::otraClave();
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => $this->claveDePrueba()])->assertOk();
        $this->putJson("/api/v1/users/{$technician->id}", [
            'name' => $technician->name, 'email' => $technician->email, 'role' => 'operator', 'active' => true, 'password' => $nueva,
        ])->assertOk();
        $this->get('/api/v1/reports/technicians?format=xlsx')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'export.report', 'user_id' => $admin->id]);
        $update = AuditLog::where('action', 'user.updated')->sole();
        $this->assertSame('operator', $update->meta['rol']['ahora']);
        $this->assertSame('cambiada', $update->meta['contraseña']);
        $this->assertStringNotContainsString($nueva, json_encode(AuditLog::all()));

        // La consulta de la auditoría es solo para administradores, y el intento queda registrado.
        $this->getJson('/api/v1/audit')->assertOk()->assertJsonPath('meta.total', 3);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/audit')->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.denied', 'description' => 'Acceso denegado: GET /api/v1/audit']);
    }

    public function test_contact_data_is_only_shown_to_those_who_need_it(): void
    {
        $operator = User::factory()->create();
        [$ana, $luis] = User::factory()->technician()->count(2)->create();
        $ticket = Ticket::create([
            'number' => 'TK-2026-00001', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::Assigned,
            'assigned_to' => $luis->id, 'opened_at' => now(),
        ]);
        $ticket->visits()->create(['technician_id' => $ana->id, 'visit_date' => now(), 'work_done' => 'Revisión']);

        // El operador ya no lista usuarios (correos de administradores); sí ve teléfonos para asignar.
        $this->actingAs($operator)->getJson('/api/v1/users')->assertForbidden();
        $this->actingAs($operator)->getJson('/api/v1/catalogs')->assertOk()->assertJsonPath('technicians.0.phone', fn ($phone) => $phone !== null);
        // Un técnico no ve los teléfonos de sus compañeros.
        $this->actingAs($ana)->getJson('/api/v1/catalogs')->assertOk()->assertJsonPath('technicians.0.phone', null)->assertJsonPath('technicians.1.phone', null);
        $this->actingAs($ana)->getJson("/api/v1/tickets/{$ticket->id}")->assertOk()->assertJsonPath('data.technician.phone', null);
        $this->actingAs($luis)->getJson("/api/v1/tickets/{$ticket->id}")->assertOk()->assertJsonPath('data.technician.phone', $luis->phone);
    }

    public function test_downloads_and_errors_do_not_leak(): void
    {
        $admin = User::factory()->admin()->create();

        // Los Excel no deben quedar en cachés intermedias ni en el disco del navegador.
        $cache = $this->actingAs($admin)->get('/api/v1/reports/technicians?format=xlsx')->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringNotContainsString('public', $cache);

        // Un registro inexistente no revela clases ni ids; filtros con forma de lista no provocan un error 500.
        $this->actingAs($admin)->getJson('/api/v1/tickets/999999')->assertNotFound()->assertExactJson(['message' => 'No se encontró lo solicitado.']);
        $this->actingAs($admin)->getJson('/api/v1/tickets?q[]=a&sla[]=x')->assertStatus(422);
        $this->actingAs($admin)->getJson('/api/v1/atms?q[]=a')->assertStatus(422);
    }

    public function test_reassigned_technician_stops_following_and_can_always_unfollow(): void
    {
        $operator = User::factory()->create();
        [$before, $after] = User::factory()->technician()->count(2)->create();
        $ticket = Ticket::create([
            'number' => 'TK-2026-00001', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::Assigned,
            'assigned_to' => $before->id, 'opened_at' => now(), 'assigned_at' => now(),
        ]);
        $this->actingAs($before)->postJson("/api/v1/tickets/{$ticket->id}/watch")->assertOk();

        $this->actingAs($operator)->postJson("/api/v1/tickets/{$ticket->id}/assign", ['technician_id' => $after->id])->assertOk();
        $this->assertFalse($ticket->watchers()->whereKey($before->id)->exists(), 'deja de seguirlo al perder el ticket');

        // Aunque quedara como seguidor, puede dejar de seguir sin poder ver el ticket, y no recibe sus datos.
        $ticket->watchers()->attach($before->id);
        $this->actingAs($before)->deleteJson("/api/v1/tickets/{$ticket->id}/watch")->assertOk()->assertExactJson(['ok' => true]);
        $this->assertFalse($ticket->watchers()->whereKey($before->id)->exists());
    }

    public function test_commenting_on_a_ticket_no_longer_yours_does_not_follow_it(): void
    {
        [$before, $now] = User::factory()->technician()->count(2)->create();
        $ticket = Ticket::create([
            'number' => 'TK-2026-00001', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::Assigned,
            'assigned_to' => $now->id, 'opened_at' => now(),
        ]);
        $ticket->visits()->create(['technician_id' => $before->id, 'visit_date' => now(), 'work_done' => 'Revisión']);

        $this->actingAs($before)->postJson("/api/v1/tickets/{$ticket->id}/comments", ['body' => 'Dejé el repuesto en la agencia.'])->assertOk();

        $this->assertFalse($ticket->watchers()->whereKey($before->id)->exists());
    }

    public function test_password_reset_disconnects_the_users_devices(): void
    {
        $admin = User::factory()->admin()->create();
        $technician = User::factory()->technician()->create();
        $technician->pushSubscriptions()->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'public_key' => 'k', 'auth_token' => 'a']);

        $this->actingAs($admin)->putJson("/api/v1/users/{$technician->id}", [
            'name' => $technician->name, 'email' => $technician->email, 'phone' => $technician->phone, 'role' => 'technician', 'active' => true, 'password' => self::otraClave(),
        ])->assertOk();

        $this->assertSame(0, $technician->pushSubscriptions()->count());
    }

    public function test_a_replayed_upload_id_from_another_ticket_is_rejected_cleanly(): void
    {
        $this->seed(AtmConditionSeeder::class);
        $technician = User::factory()->technician()->create();
        $make = fn (string $number) => Ticket::create([
            'number' => $number, 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::Assigned,
            'assigned_to' => $technician->id, 'opened_at' => now(),
        ]);
        [$first, $second] = [$make('TK-2026-00001'), $make('TK-2026-00002')];
        $visit = ['client_uuid' => '7f1c1d3e-8a4b-4c5d-9e6f-0a1b2c3d4e5f', 'work_done' => 'Limpieza', 'atm_condition_id' => AtmCondition::first()->id, 'parts_changed' => false];

        $this->actingAs($technician)->postJson("/api/v1/tickets/{$first->id}/visits", $visit + ['photo' => UploadedFile::fake()->image('constancia.jpg')])->assertOk();
        // El mismo envío repetido devuelve la visita ya creada; en otro ticket se rechaza sin error 500.
        $this->actingAs($technician)->postJson("/api/v1/tickets/{$first->id}/visits", $visit + ['photo' => UploadedFile::fake()->image('constancia.jpg')])->assertOk();
        $this->actingAs($technician)->postJson("/api/v1/tickets/{$second->id}/visits", $visit + ['photo' => UploadedFile::fake()->image('constancia.jpg')])->assertStatus(422);
        $this->assertSame(1, $first->visits()->count());
        $this->assertSame(0, $second->visits()->count());
    }

    public function test_demo_data_cannot_be_seeded_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectExceptionMessage('DemoSeeder no se ejecuta en producción');
        $this->app->make(DemoSeeder::class)->run($this->app->make(TicketService::class));
    }

    public function test_published_version_is_public_and_never_cached(): void
    {
        File::ensureDirectoryExists(public_path('build'));
        $file = public_path('build/version.json');
        $previous = is_file($file) ? file_get_contents($file) : null;
        file_put_contents($file, json_encode(['build' => '2026.10.08-213000']));

        try {
            // Sin sesión: la app la consulta también desde la pantalla de inicio.
            $response = $this->getJson('/api/v1/version')->assertOk()->assertExactJson(['build' => '2026.10.08-213000']);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        } finally {
            $previous === null ? unlink($file) : file_put_contents($file, $previous);
        }
    }
}
