<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\FaultType;
use App\Models\Ticket;
use App\Models\User;
use App\Services\PushNotifier;
use Database\Seeders\AtmConditionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class PushAndOfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_can_register_and_remove_its_push_subscription(): void
    {
        $user = User::factory()->technician()->create();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.Str::random(20);

        $this->actingAs($user)->postJson('/api/v1/push/subscriptions', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'clave-publica', 'auth' => 'secreto'],
        ])->assertCreated();
        $this->assertSame(1, $user->pushSubscriptions()->count());

        // El mismo dispositivo usado por otro usuario pasa a ese usuario.
        $other = User::factory()->technician()->create();
        $this->actingAs($other)->postJson('/api/v1/push/subscriptions', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'clave-publica', 'auth' => 'secreto'],
        ])->assertCreated();
        $this->assertSame(0, $user->pushSubscriptions()->count());

        $this->actingAs($other)->deleteJson('/api/v1/push/subscriptions', ['endpoint' => $endpoint])->assertOk();
        $this->assertSame(0, $other->pushSubscriptions()->count());
    }

    public function test_push_config_exposes_only_the_public_key(): void
    {
        config(['services.webpush.public_key' => 'PUBLICA', 'services.webpush.private_key' => 'PRIVADA']);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/push/config')
            ->assertExactJson(['enabled' => true, 'public_key' => 'PUBLICA']);
    }

    public function test_assigning_a_ticket_notifies_the_technician(): void
    {
        $this->mock(PushNotifier::class, fn (MockInterface $mock) => $mock->shouldReceive('ticketAssigned')->once());
        $technician = User::factory()->technician()->create();

        $this->actingAs(User::factory()->create())->postJson('/api/v1/tickets', [
            'atm_id' => Atm::factory()->create()->id,
            'fault_type_id' => FaultType::create(['name' => 'Comunicación'])->id,
            'assigned_to' => $technician->id,
        ])->assertCreated();
    }

    public function test_actions_done_without_signal_keep_the_time_they_really_happened(): void
    {
        $this->seed(AtmConditionSeeder::class);
        $technician = User::factory()->technician()->create();
        $this->travelTo(now()->setTime(8, 0));
        $ticket = Ticket::create([
            'number' => 'TK-2026-00001', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::Assigned,
            'assigned_to' => $technician->id, 'opened_at' => now(),
        ]);
        $arrived = now()->setTime(9, 30);
        $finished = now()->setTime(10, 45);

        // Todo se envía a las 14:00, cuando el teléfono recupera la señal.
        $this->travelTo(now()->setTime(14, 0));
        $this->actingAs($technician)->postJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'en_transito', 'at' => now()->setTime(9, 0)->toIso8601String(), 'queued' => true])->assertOk();
        $this->actingAs($technician)->postJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'en_sitio', 'at' => $arrived->toIso8601String(), 'queued' => true])->assertOk();
        $this->actingAs($technician)->postJson("/api/v1/tickets/{$ticket->id}/visits", [
            'client_uuid' => (string) Str::uuid(),
            'work_done' => 'Cambio de correas',
            'atm_condition_id' => AtmCondition::firstWhere('name', 'Operativo')->id,
            'parts_changed' => false,
            'finished_at' => $finished->toIso8601String(), 'queued' => true,
        ])->assertOk()
            ->assertJsonPath('data.status', 'por_confirmar')
            ->assertJsonPath('data.duration_ms', (int) (2.75 * 3600 * 1000));

        $visit = $ticket->visits()->first();
        $this->assertTrue($visit->arrived_at->equalTo($arrived), 'la llegada se toma de "Llegué al sitio"');
        $this->assertTrue($ticket->refresh()->closed_at->equalTo($finished));
    }

    public function test_utc_times_from_the_phone_are_stored_in_local_time(): void
    {
        $technician = User::factory()->technician()->create();
        $this->travelTo(now()->setTime(8, 0));
        $ticket = Ticket::create([
            'number' => 'TK-2026-00001', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::Assigned,
            'assigned_to' => $technician->id, 'opened_at' => now(),
        ]);
        $this->travelTo(now()->setTime(11, 0));

        // 13:30 UTC = 09:30 en Caracas (UTC-4), tal como lo envía JavaScript con toISOString().
        $utc = now()->setTime(9, 30)->utc()->format('Y-m-d\TH:i:s.v\Z');
        $this->actingAs($technician)->postJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'en_transito', 'at' => $utc, 'queued' => true])->assertOk();

        $this->assertSame('09:30:00', $ticket->events()->where('to_status', 'en_transito')->first()->created_at->format('H:i:s'));
        $this->assertStringStartsWith(now()->toDateString().' 09:30:00', \DB::table('ticket_events')->where('to_status', 'en_transito')->value('created_at'));
    }

    public function test_client_times_cannot_be_in_the_future_or_before_opening(): void
    {
        $technician = User::factory()->technician()->create();
        $ticket = Ticket::create([
            'number' => 'TK-2026-00001', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::Assigned,
            'assigned_to' => $technician->id, 'opened_at' => now()->subHour(),
        ]);

        $this->actingAs($technician)->postJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'en_transito', 'at' => now()->addDay()->toIso8601String()]);
        $this->actingAs($technician)->postJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'en_sitio', 'at' => now()->subDays(3)->toIso8601String()]);

        $events = $ticket->events()->get()->keyBy('to_status');
        $this->assertTrue($events['en_transito']->created_at->lessThanOrEqualTo(now()), 'no en el futuro');
        $this->assertTrue($events['en_sitio']->created_at->greaterThanOrEqualTo($ticket->refresh()->opened_at), 'no antes de la apertura');
        $this->assertTrue($events['en_sitio']->created_at->greaterThanOrEqualTo($events['en_transito']->created_at), 'no antes del evento anterior');
    }
}
