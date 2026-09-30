<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\Ticket;
use App\Models\User;
use App\Services\PushNotifier;
use Database\Seeders\AtmConditionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class TicketNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{to: list<string>, title: string, body: string}> */
    private array $sent = [];

    private User $admin;

    private User $opener;

    private User $onlyMine;

    private User $muted;

    private User $technician;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AtmConditionSeeder::class);

        $this->partialMock(PushNotifier::class, function (MockInterface $mock) {
            $mock->shouldReceive('deliver')->andReturnUsing(function (Collection $users, array $message) {
                $this->sent[] = ['to' => $users->pluck('email')->sort()->values()->all(), 'title' => $message['title'], 'body' => $message['body']];

                return $users->count();
            });
        });

        $this->admin = $this->withDevice(User::factory()->admin()->create(['email' => 'admin@x.test']));
        $this->opener = $this->withDevice(User::factory()->create(['email' => 'abre@x.test']));
        $this->onlyMine = $this->withDevice(User::factory()->create(['email' => 'mios@x.test', 'notify_scope' => 'mine']));
        $this->muted = $this->withDevice(User::factory()->create(['email' => 'silencio@x.test', 'notify_scope' => 'none']));
        User::factory()->create(['email' => 'sin-dispositivo@x.test']);
        $this->technician = $this->withDevice(User::factory()->technician()->create(['email' => 'tecnico@x.test', 'name' => 'Jesus Ramos']));

        $this->ticket = Ticket::create([
            'number' => 'TK-2026-00007', 'atm_id' => Atm::factory()->create(['agency_name' => 'TOCUYITO'])->id,
            'status' => TicketStatus::Assigned, 'assigned_to' => $this->technician->id,
            'opened_by' => $this->opener->id, 'opened_at' => now()->subHours(2),
        ]);
    }

    public function test_technician_progress_notifies_operators_and_admins_by_preference(): void
    {
        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$this->ticket->id}/status", ['status' => 'en_transito'])->assertOk();
        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$this->ticket->id}/status", ['status' => 'en_sitio'])->assertOk();

        $this->assertCount(2, $this->sent);
        $this->assertSame(['abre@x.test', 'admin@x.test'], $this->sent[0]['to'], '"mine" no abrió el ticket; "none" y sin dispositivo no reciben');
        $this->assertSame('TK-2026-00007 · En camino', $this->sent[0]['title']);
        $this->assertStringStartsWith('Jesus Ramos va hacia TOCUYITO', $this->sent[0]['body']);
        $this->assertSame('TK-2026-00007 · En sitio', $this->sent[1]['title']);
    }

    public function test_only_mine_scope_receives_tickets_they_opened(): void
    {
        $this->ticket->update(['opened_by' => $this->onlyMine->id]);

        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$this->ticket->id}/status", ['status' => 'en_transito']);

        $this->assertSame(['abre@x.test', 'admin@x.test', 'mios@x.test'], $this->sent[0]['to']);
    }

    public function test_pending_visit_notifies_staff_with_the_final_status(): void
    {
        $this->visit($this->technician, 'Operativo / Pendiente por Repuesto');

        $this->assertCount(1, $this->sent);
        $this->assertSame('TK-2026-00007 · Pendiente por repuesto', $this->sent[0]['title']);
        $this->assertStringContainsString('Operativo / Pendiente por Repuesto', $this->sent[0]['body']);
    }

    public function test_closing_notifies_staff_and_confirms_to_the_technician(): void
    {
        $this->visit($this->technician, 'Operativo');

        $this->assertCount(2, $this->sent);
        $this->assertSame(['abre@x.test', 'admin@x.test'], $this->sent[0]['to']);
        $this->assertSame('✅ TK-2026-00007 concluido', $this->sent[0]['title']);
        $this->assertStringContainsString('tiempo total 2 h', $this->sent[0]['body']);
        $this->assertSame(['tecnico@x.test'], $this->sent[1]['to']);
        $this->assertSame('Cierre informado · TK-2026-00007', $this->sent[1]['title']);
    }

    public function test_the_person_who_acts_is_not_notified_of_their_own_action(): void
    {
        $this->visit($this->opener, 'Operativo');

        $this->assertSame(['admin@x.test'], $this->sent[0]['to'], 'el operador que registró el cierre no se avisa a sí mismo');
        $this->assertSame(['tecnico@x.test'], $this->sent[1]['to']);
    }

    public function test_cancelling_notifies_the_technician(): void
    {
        $this->actingAs($this->opener)->postJson("/api/v1/tickets/{$this->ticket->id}/status", ['status' => 'anulado', 'note' => 'Reporte duplicado'])->assertOk();

        $this->assertSame(['tecnico@x.test'], $this->sent[0]['to']);
        $this->assertStringContainsString('Motivo: Reporte duplicado', $this->sent[0]['body']);
    }

    public function test_users_choose_which_ticket_notifications_they_receive(): void
    {
        $this->actingAs($this->opener)->putJson('/api/v1/me/notifications', ['notify_scope' => 'mine'])
            ->assertOk()->assertJsonPath('data.notify_scope', 'mine');
        $this->actingAs($this->opener)->putJson('/api/v1/me/notifications', ['notify_scope' => 'todo'])
            ->assertJsonValidationErrors('notify_scope');
    }

    private function withDevice(User $user): User
    {
        $user->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.Str::random(16),
            'public_key' => 'clave', 'auth_token' => 'secreto',
        ]);

        return $user;
    }

    private function visit(User $by, string $condition): void
    {
        $this->actingAs($by)->postJson("/api/v1/tickets/{$this->ticket->id}/visits", [
            'work_done' => 'Cambio de correas',
            'atm_condition_id' => AtmCondition::firstWhere('name', $condition)->id,
            'parts_changed' => false,
        ])->assertOk();
    }
}
