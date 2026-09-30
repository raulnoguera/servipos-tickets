<?php

namespace Tests\Feature;

use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\FaultType;
use App\Models\Ticket;
use App\Models\User;
use App\Services\PushNotifier;
use Database\Seeders\AtmConditionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery\MockInterface;
use Tests\TestCase;

/** Funciones de seguimiento tipo JIRA: prioridad, SLA, edición, comentarios y seguidores. */
class TicketTrackingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: list<string>, 1: string}> avisos capturados: [destinatarios, título] */
    private array $sent = [];

    private User $operator;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AtmConditionSeeder::class);
        $this->operator = User::factory()->create(['name' => 'Ana Operadora']);
        $this->technician = User::factory()->technician()->create(['name' => 'Jesus Ramos']);
    }

    public function test_tickets_are_created_with_priority_and_sla_targets(): void
    {
        $this->travelTo(now()->setTime(8, 0));
        $ticket = $this->open('critica');

        $this->getJson("/api/v1/tickets/{$ticket['id']}")
            ->assertJsonPath('data.priority', 'critica')
            ->assertJsonPath('data.priority_label', 'Crítica')
            ->assertJsonPath('data.sla.response.target_minutes', 120)
            ->assertJsonPath('data.sla.resolution.target_minutes', 240)
            ->assertJsonPath('data.sla.resolution.status', 'on_track');

        $this->travel(200)->minutes();
        $this->getJson("/api/v1/tickets/{$ticket['id']}")
            ->assertJsonPath('data.sla.response.status', 'breached', 'pasaron 3 h 20 min sin llegar (meta 2 h)')
            ->assertJsonPath('data.sla.resolution.status', 'at_risk', '200 de 240 min: más del 80 %');
    }

    public function test_arrival_and_closing_are_measured_against_sla(): void
    {
        $this->travelTo(now()->setTime(8, 0));
        $ticket = Ticket::findOrFail($this->open('alta')['id']);

        $this->travel(3)->hours();
        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'en_sitio'])->assertOk();
        $this->travel(6)->hours();
        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$ticket->id}/visits", [
            'work_done' => 'Listo', 'atm_condition_id' => AtmCondition::firstWhere('name', 'Operativo')->id, 'parts_changed' => false,
        ])->assertOk()
            ->assertJsonPath('data.sla.response.status', 'met', 'llegó a las 3 h (meta 4 h)')
            ->assertJsonPath('data.sla.resolution.status', 'breached', 'cerró a las 9 h (meta 8 h)');
    }

    public function test_breached_and_at_risk_filters(): void
    {
        $this->travelTo(now()->setTime(8, 0));
        $late = $this->open('critica');
        $this->travel(100)->minutes();
        $risky = $this->open('critica');
        $this->travel(100)->minutes(); // late: 200 min (vencido llegada); risky: 100 min (≥ 80 % de 120 de llegada)
        $this->open('baja');

        $this->getJson('/api/v1/tickets?sla=breached')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $late['id']);
        $this->getJson('/api/v1/tickets?sla=at_risk')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $risky['id']);
    }

    public function test_editing_a_ticket_is_logged_in_the_history(): void
    {
        $ticket = $this->open('media');
        $other = FaultType::create(['name' => 'Comunicación']);

        $this->patchJson("/api/v1/tickets/{$ticket['id']}", ['priority' => 'alta', 'fault_type_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('data.priority', 'alta')
            ->assertJsonPath('data.fault_type', 'Comunicación');

        $notes = Ticket::find($ticket['id'])->events()->pluck('note')->implode(' | ');
        $this->assertStringContainsString('Prioridad: Media → Alta', $notes);
        $this->assertStringContainsString('Falla: Falla de Dispensación → Comunicación', $notes);

        $this->actingAs($this->technician)->patchJson("/api/v1/tickets/{$ticket['id']}", ['priority' => 'baja'])->assertForbidden();
    }

    public function test_comments_notify_people_involved_and_author_follows_the_ticket(): void
    {
        $this->capturePush();
        $ticket = $this->open('media');
        $watcher = User::factory()->admin()->create(['name' => 'Luis Admin']);
        $this->actingAs($watcher)->postJson("/api/v1/tickets/{$ticket['id']}/watch")->assertJsonPath('data.watching', true);
        $this->sent = [];

        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$ticket['id']}/comments", ['body' => 'El cajero no tiene energía, espero al electricista.'])
            ->assertOk()
            ->assertJsonPath('data.comments.0.body', 'El cajero no tiene energía, espero al electricista.')
            ->assertJsonPath('data.watching', true);

        $comment = collect($this->sent)->first(fn ($s) => str_contains($s[1], 'Comentario'));
        $this->assertSame(['Ana Operadora', 'Luis Admin'], $comment[0], 'quien abrió y quien sigue; el autor no se avisa a sí mismo');
        $this->assertSame("{$ticket['number']} · Comentario de Jesus Ramos", $comment[1]);
    }

    public function test_watchers_get_progress_notifications_even_with_notifications_off(): void
    {
        $this->capturePush();
        $quiet = User::factory()->admin()->create(['name' => 'Silencio', 'notify_scope' => 'none']);
        $ticket = $this->open('media');
        $this->actingAs($quiet)->postJson("/api/v1/tickets/{$ticket['id']}/watch");
        $this->sent = [];

        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$ticket['id']}/status", ['status' => 'en_transito']);

        $this->assertContains('Silencio', $this->sent[0][0]);
        $this->actingAs($quiet)->deleteJson("/api/v1/tickets/{$ticket['id']}/watch")->assertJsonPath('data.watching', false);
    }

    public function test_admins_update_sla_targets(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson('/api/v1/sla-policies', ['policies' => [['priority' => 'critica', 'response_minutes' => 60, 'resolution_minutes' => 180]]])
            ->assertOk()->assertJsonPath('data.0.response_minutes', 60);
        $this->actingAs($this->operator)->putJson('/api/v1/sla-policies', ['policies' => []])->assertForbidden();
    }

    private function capturePush(): void
    {
        $this->partialMock(PushNotifier::class, function (MockInterface $mock) {
            $mock->shouldReceive('deliver')->andReturnUsing(function (Collection $users, array $message) {
                $this->sent[] = [$users->pluck('name')->sort()->values()->all(), $message['title']];

                return 0;
            });
        });
    }

    /** @return array<string, mixed> */
    private function open(string $priority): array
    {
        return $this->actingAs($this->operator)->postJson('/api/v1/tickets', [
            'atm_id' => Atm::factory()->create()->id,
            'fault_type_id' => FaultType::firstOrCreate(['name' => 'Falla de Dispensación'])->id,
            'priority' => $priority,
            'assigned_to' => $this->technician->id,
        ])->assertCreated()->json('data');
    }
}
