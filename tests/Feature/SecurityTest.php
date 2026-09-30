<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\PushSubscription;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Reports\TableExporter;
use Database\Seeders\AtmConditionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            $this->postJson('/api/v1/auth/login', ['email' => 'ana@servipos.test', 'password' => 'incorrecta'])
                ->assertJsonPath('errors.email.0', 'Correo o contraseña incorrectos.');
        }

        // Incluso con la contraseña correcta queda bloqueada unos minutos.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn ($msg) => str_starts_with($msg, 'Demasiados intentos fallidos'));
    }

    public function test_successful_login_resets_failed_attempts(): void
    {
        $user = User::factory()->create(['email' => 'luis@servipos.test']);
        foreach (range(1, 4) as $i) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'mal']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
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
            'name' => $technician->name, 'email' => $technician->email, 'role' => 'technician', 'active' => false,
        ])->assertOk();

        $technician->refresh();
        $this->assertNotSame('token-anterior', $technician->remember_token);
        $this->assertSame(0, $technician->pushSubscriptions()->count());
    }

    public function test_technician_cannot_backdate_arrival_to_fake_the_sla(): void
    {
        $this->seed(AtmConditionSeeder::class);
        $technician = User::factory()->technician()->create();
        $ticket = Ticket::create([
            'number' => 'TK-2026-00001', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::OnSite,
            'assigned_to' => $technician->id, 'opened_at' => now()->subHours(5), 'assigned_at' => now()->subHours(4),
        ]);

        $this->actingAs($technician)->postJson("/api/v1/tickets/{$ticket->id}/visits", [
            'work_done' => 'Cambio de dispensador', 'atm_condition_id' => AtmCondition::first()->id, 'parts_changed' => false,
            'arrived_at' => now()->subDays(2)->toIso8601String(),
        ])->assertOk();

        $ticket->refresh();
        $this->assertTrue($ticket->responded_at->greaterThanOrEqualTo($ticket->assigned_at), 'no antes de la asignación');
        $this->assertTrue($ticket->visits()->first()->arrived_at->lessThanOrEqualTo(now()), 'no en el futuro');
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
}
