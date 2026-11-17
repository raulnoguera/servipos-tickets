<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\AtmConditionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** El cliente dejó de usar «Pend. validación» (17/11/2026): solo «Pend. repuesto» y «Por confirmar». */
class PendingValidationRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_out_of_service_results_leave_the_ticket_waiting_for_a_part(): void
    {
        $this->seed(AtmConditionSeeder::class);

        $this->assertSame(0, AtmCondition::where('resulting_ticket_status', TicketStatus::PendingValidation)->count());
        foreach (['Fuera de Servicio', 'Fuera de Servicio/Pendiente por 3ero'] as $name) {
            $this->assertSame(TicketStatus::PendingPart, AtmCondition::where('name', $name)->sole()->resulting_ticket_status);
        }
    }

    public function test_the_catalog_no_longer_accepts_the_validation_status(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/catalogs/atm-conditions', ['name' => 'Nuevo', 'resulting_ticket_status' => 'pendiente_validacion'])
            ->assertJsonValidationErrors('resulting_ticket_status');
        $this->postJson('/api/v1/catalogs/atm-conditions', ['name' => 'Nuevo', 'resulting_ticket_status' => 'pendiente_repuesto'])
            ->assertCreated();
    }

    public function test_the_migration_moves_open_tickets_and_conditions_to_pending_part(): void
    {
        $condition = AtmCondition::create(['name' => 'Fuera de Servicio', 'resulting_ticket_status' => 'pendiente_validacion', 'sort' => 3]);
        $ticket = Ticket::create(['number' => 'TK-2026-00203', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::PendingValidation, 'opened_at' => now()]);
        $other = Ticket::create(['number' => 'TK-2026-00204', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::Assigned, 'opened_at' => now()]);

        (require database_path('migrations/2026_11_17_120000_remove_pending_validation_status.php'))->up();

        $this->assertSame(TicketStatus::PendingPart, $condition->refresh()->resulting_ticket_status);
        $this->assertSame(TicketStatus::PendingPart, $ticket->refresh()->status);
        $this->assertSame(TicketStatus::Assigned, $other->refresh()->status);
        $this->assertDatabaseHas('ticket_events', ['ticket_id' => $ticket->id, 'from_status' => 'pendiente_validacion', 'to_status' => 'pendiente_repuesto']);
        $this->assertDatabaseMissing('ticket_events', ['ticket_id' => $other->id]);
    }
}
