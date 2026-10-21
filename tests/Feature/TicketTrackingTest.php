<?php

namespace Tests\Feature;

use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\FaultType;
use App\Models\Ticket;
use App\Models\User;
use App\Services\PushNotifier;
use Carbon\CarbonImmutable;
use Database\Seeders\AtmConditionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery\MockInterface;
use Tests\TestCase;

/** Funciones de seguimiento tipo JIRA: semáforo de SLA, edición, comentarios y seguidores. */
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

    /** Lunes 19/10/2026 a las 2 p. m., hora de Caracas. */
    private function monday(string $time = '14:00'): CarbonImmutable
    {
        return CarbonImmutable::parse("2026-10-19 {$time}", config('app.timezone'));
    }

    public function test_an_urban_ticket_only_counts_banking_hours(): void
    {
        $this->travelTo($this->monday());
        $ticket = $this->open();

        $this->getJson("/api/v1/tickets/{$ticket['id']}")
            ->assertJsonPath('data.sla.level', 'verde')
            ->assertJsonPath('data.sla.zone', 'urbana')
            ->assertJsonPath('data.sla.orange_at', $this->monday('11:00')->addDay()->toIso8601String(), '1 h el lunes + 3 h el martes')
            ->assertJsonPath('data.sla.critical_at', $this->monday('15:00')->addDay()->toIso8601String());

        $this->travelTo($this->monday('20:00'));
        $this->getJson("/api/v1/tickets/{$ticket['id']}")->assertJsonPath('data.sla.level', 'verde')->assertJsonPath('data.sla.elapsed_minutes', 60);

        $this->travelTo($this->monday('11:30')->addDay());
        $this->getJson("/api/v1/tickets/{$ticket['id']}")->assertJsonPath('data.sla.level', 'naranja');

        $this->travelTo($this->monday('08:30')->addDays(2));
        $this->getJson("/api/v1/tickets/{$ticket['id']}")->assertJsonPath('data.sla.level', 'critica');
    }

    public function test_an_extraurban_ticket_counts_business_days_and_skips_holidays(): void
    {
        $this->travelTo($this->monday());
        $ticket = $this->open(Atm::factory()->create(['zone' => 'extraurbana']));

        $this->getJson("/api/v1/tickets/{$ticket['id']}")
            ->assertJsonPath('data.sla.orange_at', $this->monday()->addDay()->toIso8601String(), 'un día hábil: martes 2 p. m.')
            ->assertJsonPath('data.sla.critical_at', $this->monday()->addDays(2)->toIso8601String());

        // El martes pasa a ser feriado: todo se corre un día.
        $this->actingAs(User::factory()->admin()->create())->postJson('/api/v1/holidays', ['date' => '2026-10-20', 'name' => 'Feriado de prueba'])->assertCreated();
        $this->actingAs($this->operator)->getJson("/api/v1/tickets/{$ticket['id']}")
            ->assertJsonPath('data.sla.orange_at', $this->monday()->addDays(2)->toIso8601String());
    }

    public function test_the_color_is_frozen_when_the_technician_reports_the_closure(): void
    {
        $this->travelTo($this->monday());
        $ticket = $this->open();

        $this->travelTo($this->monday('10:00')->addDay()); // 3 h hábiles
        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$ticket['id']}/visits", [
            'work_done' => 'Listo', 'atm_condition_id' => AtmCondition::firstWhere('name', 'Operativo')->id, 'parts_changed' => false,
        ])->assertOk()->assertJsonPath('data.sla.level', 'verde')->assertJsonPath('data.sla.running', false);

        $this->travelTo($this->monday()->addDays(3));
        $this->actingAs($this->operator)->getJson("/api/v1/tickets/{$ticket['id']}")->assertJsonPath('data.sla.level', 'verde');
    }

    public function test_traffic_light_filters_and_dashboard(): void
    {
        $this->travelTo($this->monday('08:00'));
        $critical = $this->open();
        $this->travelTo($this->monday('11:00')); // el primero lleva 3 h
        $orange = $this->open();
        $this->travelTo($this->monday('14:30'));
        $green = $this->open();
        $this->travelTo($this->monday('10:00')->addDay()); // 9 h, 6 h y 2,5 h hábiles

        $this->getJson('/api/v1/tickets?sla=critica')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $critical['id']);
        $this->getJson('/api/v1/tickets?sla=naranja')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $orange['id']);
        $this->getJson('/api/v1/tickets?sla=verde')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $green['id']);
        $this->getJson('/api/v1/tickets?sort=sla')->assertJsonPath('data.0.id', $critical['id'])->assertJsonPath('data.2.id', $green['id']);
        $this->getJson('/api/v1/dashboard')->assertJsonPath('kpis.sla_critical', 1)->assertJsonPath('kpis.sla_orange', 1);
    }

    public function test_changing_the_zone_of_an_atm_moves_its_open_tickets(): void
    {
        $this->travelTo($this->monday('08:00'));
        $atm = Atm::factory()->create();
        $ticket = $this->open($atm);
        $this->travelTo($this->monday('13:00')); // 5 h: naranja en urbana
        $this->getJson("/api/v1/tickets/{$ticket['id']}")->assertJsonPath('data.sla.level', 'naranja');

        $atm->update(['zone' => 'extraurbana']);
        $this->getJson("/api/v1/tickets/{$ticket['id']}")->assertJsonPath('data.sla.level', 'verde')->assertJsonPath('data.sla.zone_label', 'Extraurbana');
    }

    public function test_the_sla_type_can_be_chosen_when_opening_and_editing_a_ticket(): void
    {
        $this->travelTo($this->monday());
        $atm = Atm::factory()->create(); // urbana
        $ticket = $this->actingAs($this->operator)->postJson('/api/v1/tickets', [
            'atm_id' => $atm->id,
            'fault_type_id' => FaultType::firstOrCreate(['name' => 'Falla de Dispensación'])->id,
            'sla_zone' => 'extraurbana',
        ])->assertCreated()
            ->assertJsonPath('data.sla.zone', 'extraurbana')
            ->assertJsonPath('data.sla.zone_from_atm', false)
            ->assertJsonPath('data.sla.orange_at', $this->monday()->addDay()->toIso8601String())
            ->json('data');

        // Lo elegido para el ticket se mantiene aunque cambie la zona del cajero.
        $atm->update(['zone' => 'urbana']);
        $this->getJson("/api/v1/tickets/{$ticket['id']}")->assertJsonPath('data.sla.zone', 'extraurbana');

        // Volver a la zona del cajero: el ticket la sigue de nuevo y queda en el historial.
        $this->patchJson("/api/v1/tickets/{$ticket['id']}", ['sla_zone' => 'urbana'])
            ->assertOk()->assertJsonPath('data.sla.zone', 'urbana')->assertJsonPath('data.sla.zone_from_atm', true);
        $this->assertNull(Ticket::find($ticket['id'])->sla_zone);
        $this->assertStringContainsString('Tipo de SLA: Extraurbana → Urbana', Ticket::find($ticket['id'])->events()->pluck('note')->implode(' | '));

        $this->postJson('/api/v1/tickets', ['atm_id' => $atm->id, 'fault_type_id' => FaultType::first()->id, 'sla_zone' => 'rural'])
            ->assertStatus(422)->assertJsonValidationErrors('sla_zone');
        $this->getJson('/api/v1/catalogs')->assertJsonPath('zones.1.rule', 'verde hasta 1 día hábil, naranja hasta 2 días hábiles');
    }

    public function test_editing_a_ticket_is_logged_in_the_history(): void
    {
        $ticket = $this->open();
        $other = FaultType::create(['name' => 'Comunicación']);

        $this->patchJson("/api/v1/tickets/{$ticket['id']}", ['fault_type_id' => $other->id, 'bank_reference' => 'C-123'])
            ->assertOk()
            ->assertJsonPath('data.fault_type', 'Comunicación');

        $notes = Ticket::find($ticket['id'])->events()->pluck('note')->implode(' | ');
        $this->assertStringContainsString('Falla: Falla de Dispensación → Comunicación', $notes);
        $this->assertStringContainsString('Caso del banco actualizado', $notes);

        $this->actingAs($this->technician)->patchJson("/api/v1/tickets/{$ticket['id']}", ['description' => 'x'])->assertForbidden();
    }

    public function test_comments_notify_people_involved_and_author_follows_the_ticket(): void
    {
        $this->capturePush();
        $ticket = $this->open();
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
        $ticket = $this->open();
        $this->actingAs($quiet)->postJson("/api/v1/tickets/{$ticket['id']}/watch");
        $this->sent = [];

        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$ticket['id']}/status", ['status' => 'en_transito']);

        $this->assertContains('Silencio', $this->sent[0][0]);
        $this->actingAs($quiet)->deleteJson("/api/v1/tickets/{$ticket['id']}/watch")->assertJsonPath('data.watching', false);
    }

    public function test_admins_update_sla_targets_and_banking_hours(): void
    {
        $admin = User::factory()->admin()->create();
        $policies = [['zone' => 'urbana', 'unit' => 'hours', 'green' => 3, 'orange' => 6], ['zone' => 'extraurbana', 'unit' => 'days', 'green' => 1, 'orange' => 2]];

        $this->actingAs($admin)->putJson('/api/v1/sla-policies', ['policies' => $policies, 'schedule' => ['start' => '08:00', 'end' => '15:30', 'days' => [5, 1, 2, 3, 4]]])
            ->assertOk()
            ->assertJsonPath('data.policies.0.green', 3)
            ->assertJsonPath('data.schedule.end', '15:30')
            ->assertJsonPath('data.schedule.days', [1, 2, 3, 4, 5])
            ->assertJsonPath('data.schedule.day_minutes', 450);

        $policies[0]['orange'] = 2;
        $this->actingAs($admin)->putJson('/api/v1/sla-policies', ['policies' => $policies])->assertStatus(422)->assertJsonValidationErrors('policies.0.orange');
        $this->actingAs($this->operator)->putJson('/api/v1/sla-policies', ['policies' => []])->assertForbidden();
        $this->actingAs($this->operator)->postJson('/api/v1/holidays', ['date' => '2026-12-26', 'name' => 'X'])->assertForbidden();
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
    private function open(?Atm $atm = null): array
    {
        return $this->actingAs($this->operator)->postJson('/api/v1/tickets', [
            'atm_id' => ($atm ?? Atm::factory()->create())->id,
            'fault_type_id' => FaultType::firstOrCreate(['name' => 'Falla de Dispensación'])->id,
            'assigned_to' => $this->technician->id,
        ])->assertCreated()->json('data');
    }
}
