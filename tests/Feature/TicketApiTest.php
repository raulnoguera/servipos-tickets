<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\FaultType;
use App\Models\Part;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\AtmConditionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TicketApiTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $technician;

    private Atm $atm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AtmConditionSeeder::class);
        $this->operator = User::factory()->create();
        $this->technician = User::factory()->technician()->create(['phone' => '0414-1234567']);
        $this->atm = Atm::factory()->create(['atm_number' => 'A1918035']);
    }

    public function test_atm_lookup_autofills_the_opening_form(): void
    {
        $this->actingAs($this->operator)
            ->getJson('/api/v1/atms/lookup/a1918035')
            ->assertOk()
            ->assertJsonPath('data.serial', $this->atm->serial)
            ->assertJsonPath('data.agency_name', $this->atm->agency_name);
    }

    public function test_operator_opens_and_assigns_a_ticket_with_whatsapp_link(): void
    {
        $response = $this->actingAs($this->operator)->postJson('/api/v1/tickets', [
            'atm_id' => $this->atm->id,
            'fault_type_id' => FaultType::create(['name' => 'Falla de Dispensación'])->id,
            'description' => 'No dispensa',
            'assigned_to' => $this->technician->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.number', 'TK-'.now()->year.'-00001')
            ->assertJsonPath('data.status', TicketStatus::Assigned->value)
            ->assertJsonPath('data.technician.id', $this->technician->id);
        $this->assertStringStartsWith('https://wa.me/584141234567?text=', $response->json('data.whatsapp_url'));
        $this->assertCount(2, $response->json('data.events'));
    }

    public function test_full_cycle_closes_the_ticket_and_measures_duration_without_stopping_on_pending_part(): void
    {
        $this->travelTo(now()->setTime(8, 0));
        $ticket = $this->openAssignedTicket();

        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'en_transito'])->assertOk();
        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'en_sitio'])->assertOk();

        // Primera visita: queda pendiente por repuesto.
        $this->travel(2)->hours();
        $this->visit($ticket, 'Operativo / Pendiente por Repuesto')
            ->assertJsonPath('data.status', TicketStatus::PendingPart->value);

        // Segunda visita al día siguiente: cierra. El reloj no se detuvo mientras esperaba.
        $this->travel(1)->days();
        Storage::fake();
        $part = Part::create(['name' => 'PICKER']);
        $response = $this->visit($ticket, 'Operativo', [
            'parts_changed' => true,
            'parts' => [['part_id' => $part->id, 'quantity' => 2]],
            'photo' => UploadedFile::fake()->image('constancia.jpg', 2400, 3200),
        ]);

        // El técnico informa el cierre: queda por confirmar y el reloj se detiene ahí.
        $response->assertJsonPath('data.status', TicketStatus::PendingClosure->value)
            ->assertJsonPath('data.is_running', false)
            ->assertJsonPath('data.can_confirm', false)
            ->assertJsonPath('data.duration_ms', 26 * 3600 * 1000)
            ->assertJsonPath('data.visits.1.parts.0.quantity', 2);
        $this->assertCount(2, $response->json('data.visits'));

        // El administrador confirma horas después: el ticket concluye con el tiempo del técnico.
        $this->travel(5)->hours();
        $this->actingAs(User::factory()->admin()->create(['name' => 'Ana Admin']))->postJson("/api/v1/tickets/{$ticket->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', TicketStatus::Closed->value)
            ->assertJsonPath('data.duration_ms', 26 * 3600 * 1000)
            ->assertJsonPath('data.confirmed_by', 'Ana Admin')
            ->assertJsonPath('data.events', fn (array $events) => collect($events)->contains('note', 'Cierre confirmado'));
        $this->assertTrue($ticket->refresh()->confirmed_at->equalTo(now()));
        $this->assertTrue($ticket->closed_at->equalTo(now()->subHours(5)), 'el cierre conserva la hora en que terminó el técnico');

        $path = $ticket->visits()->reorder()->latest('id')->first()->constancia_path;
        Storage::assertExists($path);
        [$width, $height] = getimagesizefromstring(Storage::get($path));
        $this->assertSame([1200, 1600], [$width, $height], 'la foto se reduce a 1600 px');
    }

    public function test_only_an_admin_gives_the_final_confirmation(): void
    {
        $ticket = $this->openAssignedTicket();
        $this->visit($ticket, 'Operativo')->assertJsonPath('data.status', TicketStatus::PendingClosure->value);

        // Ni el técnico ni el operador pueden cerrarlo; tampoco se registra otra atención ni se reasigna.
        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$ticket->id}/confirm")->assertForbidden();
        $this->actingAs($this->operator)->postJson("/api/v1/tickets/{$ticket->id}/confirm")->assertForbidden();
        $this->actingAs($this->technician);
        $this->visit($ticket, 'Operativo', [], 422)->assertJsonValidationErrors('status');
        $this->actingAs($this->operator)->postJson("/api/v1/tickets/{$ticket->id}/assign", ['technician_id' => $this->technician->id])->assertStatus(422);
        $this->actingAs($this->operator)->getJson("/api/v1/tickets/{$ticket->id}")->assertJsonPath('data.can_confirm', false);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson("/api/v1/tickets/{$ticket->id}")->assertJsonPath('data.can_confirm', true);
        $this->actingAs($admin)->postJson("/api/v1/tickets/{$ticket->id}/confirm")->assertOk()->assertJsonPath('data.status', TicketStatus::Closed->value);
        // Ya concluido no hay nada que confirmar.
        $this->actingAs($admin)->postJson("/api/v1/tickets/{$ticket->id}/confirm")->assertStatus(422);
    }

    public function test_admin_can_return_the_ticket_to_the_technician_with_a_reason(): void
    {
        $this->travelTo(now()->setTime(8, 0));
        $ticket = $this->openAssignedTicket();
        $this->travel(1)->hours();
        $this->visit($ticket, 'Operativo');
        $admin = User::factory()->admin()->create();

        // El motivo es obligatorio.
        $this->actingAs($admin)->postJson("/api/v1/tickets/{$ticket->id}/reject", [])->assertJsonValidationErrors('note');
        $this->actingAs($admin)->postJson("/api/v1/tickets/{$ticket->id}/reject", ['note' => 'El banco reporta que sigue sin dispensar'])
            ->assertOk()
            ->assertJsonPath('data.status', TicketStatus::Assigned->value)
            ->assertJsonPath('data.is_running', true)
            ->assertJsonPath('data.closed_at', null)
            ->assertJsonPath('data.events', fn (array $events) => collect($events)->contains('note', 'Cierre devuelto: El banco reporta que sigue sin dispensar'));

        // El reloj sigue desde la apertura: al volver a atenderlo la duración incluye todo el ciclo.
        $this->travel(2)->hours();
        $this->actingAs($this->technician);
        $this->visit($ticket, 'Operativo')
            ->assertJsonPath('data.status', TicketStatus::PendingClosure->value)
            ->assertJsonPath('data.duration_ms', 3 * 3600 * 1000);
        $this->assertSame(2, $ticket->visits()->count());
    }

    public function test_parts_are_mandatory_when_parts_were_changed(): void
    {
        $ticket = $this->openAssignedTicket();

        $this->visit($ticket, 'Operativo', ['parts_changed' => true], 422)
            ->assertJsonValidationErrors('parts');
    }

    public function test_offline_retry_with_same_uuid_does_not_duplicate_the_visit(): void
    {
        $ticket = $this->openAssignedTicket();
        $uuid = (string) Str::uuid();

        $this->visit($ticket, 'Operativo / Pendiente por Repuesto', ['client_uuid' => $uuid]);
        $this->visit($ticket, 'Operativo / Pendiente por Repuesto', ['client_uuid' => $uuid]);

        $this->assertSame(1, $ticket->visits()->count());
    }

    public function test_cannot_register_a_visit_on_an_unassigned_ticket(): void
    {
        $ticket = Ticket::create([
            'number' => 'TK-2026-09999', 'atm_id' => $this->atm->id, 'status' => TicketStatus::Open, 'opened_at' => now(),
        ]);

        $this->actingAs($this->operator);
        $this->visit($ticket, 'Operativo', [], 422)->assertJsonValidationErrors('status');
    }

    private function openAssignedTicket(): Ticket
    {
        $id = $this->actingAs($this->operator)->postJson('/api/v1/tickets', [
            'atm_id' => $this->atm->id,
            'fault_type_id' => FaultType::firstOrCreate(['name' => 'Falla de Dispensación'])->id,
            'assigned_to' => $this->technician->id,
        ])->json('data.id');

        return Ticket::findOrFail($id);
    }

    /** @param array<string, mixed> $extra */
    private function visit(Ticket $ticket, string $condition, array $extra = [], int $status = 200): TestResponse
    {
        $user = auth()->user()?->is($this->operator) ? $this->operator : $this->technician;

        return $this->actingAs($user)->post("/api/v1/tickets/{$ticket->id}/visits", $extra + [
            'constancia_number' => '6251',
            'work_done' => 'Se reemplaza el picker',
            'atm_condition_id' => AtmCondition::firstWhere('name', $condition)->id,
            'parts_changed' => false,
        ], ['Accept' => 'application/json'])->assertStatus($status);
    }
}
