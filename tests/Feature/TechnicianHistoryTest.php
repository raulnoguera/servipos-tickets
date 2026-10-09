<?php

namespace Tests\Feature;

use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\FaultType;
use App\Models\Part;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\AtmConditionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $jesus;

    private User $david;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AtmConditionSeeder::class);
        $this->operator = User::factory()->create();
        $this->jesus = User::factory()->technician()->create();
        $this->david = User::factory()->technician()->create();
    }

    public function test_technician_history_includes_attended_tickets_even_after_reassignment(): void
    {
        // Jesús atiende, queda pendiente por repuesto y el ticket se reasigna a David.
        $reassigned = $this->openFor($this->jesus);
        $this->visit($reassigned, $this->jesus, 'Operativo / Pendiente por Repuesto');
        $this->actingAs($this->operator)->postJson("/api/v1/tickets/{$reassigned->id}/assign", ['technician_id' => $this->david->id])->assertOk();
        // Otro ticket que Jesús cerró.
        $closed = $this->openFor($this->jesus);
        $this->visit($closed, $this->jesus, 'Operativo', parts: true);
        // Ticket ajeno.
        $foreign = $this->openFor($this->david);

        $ids = collect($this->actingAs($this->jesus)->getJson('/api/v1/tickets?history=1')->assertOk()->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$reassigned->id, $closed->id], $ids->all());

        // Su lista de trabajo solo tiene lo asignado a él (nada abierto ahora).
        $this->actingAs($this->jesus)->getJson('/api/v1/tickets?open=1')->assertJsonCount(0, 'data');

        // Puede consultar el reasignado, pero no trabajarlo.
        $this->actingAs($this->jesus)->getJson("/api/v1/tickets/{$reassigned->id}")->assertOk();
        $this->actingAs($this->jesus)->postJson("/api/v1/tickets/{$reassigned->id}/status", ['status' => 'en_transito'])->assertForbidden();
        $this->actingAs($this->jesus)->postJson("/api/v1/tickets/{$reassigned->id}/visits", [
            'work_done' => 'x', 'atm_condition_id' => AtmCondition::first()->id, 'parts_changed' => false,
        ])->assertForbidden();

        // No ve tickets en los que no participó.
        $this->actingAs($this->jesus)->getJson("/api/v1/tickets/{$foreign->id}")->assertForbidden();

        // David, el nuevo responsable, sí puede trabajarlo.
        $this->actingAs($this->david)->postJson("/api/v1/tickets/{$reassigned->id}/status", ['status' => 'en_transito'])->assertOk();
    }

    public function test_technician_summary_counts_this_month(): void
    {
        $ticket = $this->openFor($this->jesus);
        $this->visit($ticket, $this->jesus, 'Operativo', parts: true);

        $this->actingAs($this->jesus)->getJson('/api/v1/me/summary')
            ->assertOk()
            ->assertJsonPath('month.visits', 1)
            ->assertJsonPath('month.closed', 1)
            ->assertJsonPath('month.parts_units', 2)
            ->assertJsonPath('total.tickets', 1);
    }

    private function openFor(User $technician): Ticket
    {
        return Ticket::findOrFail($this->actingAs($this->operator)->postJson('/api/v1/tickets', [
            'atm_id' => Atm::factory()->create()->id,
            'fault_type_id' => FaultType::firstOrCreate(['name' => 'Comunicación'])->id,
            'assigned_to' => $technician->id,
        ])->json('data.id'));
    }

    private function visit(Ticket $ticket, User $technician, string $condition, bool $parts = false): void
    {
        $this->actingAs($technician)->postJson("/api/v1/tickets/{$ticket->id}/visits", [
            'work_done' => 'Trabajo',
            'atm_condition_id' => AtmCondition::firstWhere('name', $condition)->id,
            'parts_changed' => $parts,
            'parts' => $parts ? [['part_id' => Part::firstOrCreate(['name' => 'PICKER'])->id, 'quantity' => 2]] : [],
        ])->assertOk();
        if ($condition === 'Operativo') {
            $this->actingAs(User::factory()->admin()->create())->postJson("/api/v1/tickets/{$ticket->id}/confirm")->assertOk();
        }
    }
}
