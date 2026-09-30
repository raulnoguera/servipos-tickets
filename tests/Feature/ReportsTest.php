<?php

namespace Tests\Feature;

use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\FaultType;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\AtmConditionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $technician;

    private Atm $atm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AtmConditionSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->technician = User::factory()->technician()->create(['name' => 'Jesus Ramos']);
        $this->atm = Atm::factory()->create(['atm_number' => 'A1918035', 'model' => 'OPTEVA 562']);
        $this->travelTo(now()->setDate(2026, 9, 10)->setTime(8, 0));

        // Dos tickets del mismo cajero (reincidencia): uno en 1 visita y 2 h, otro pendiente.
        $this->cycle(hours: 2, parts: [['PICKER', 2]]);
        $this->travel(2)->days();
        $this->cycle(hours: 1, parts: [['PICKER', 1], ['FEEDSHAFT', 1]], condition: 'Operativo / Pendiente por Repuesto');
    }

    public function test_summary_metrics(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/reports/summary?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('kpis.opened', 2)
            ->assertJsonPath('kpis.closed', 1)
            ->assertJsonPath('kpis.backlog', 1)
            ->assertJsonPath('kpis.mttr_ms', 2 * 3600 * 1000)
            ->assertJsonPath('kpis.first_visit_rate', 100)
            ->assertJsonPath('kpis.reincidence_rate', 50);
    }

    public function test_reports_by_atm_technician_part_and_fault(): void
    {
        $this->actingAs($this->admin);
        $query = '?from=2026-09-01&to=2026-09-30';

        $this->getJson("/api/v1/reports/atms{$query}")->assertJsonPath('data.0.atm_number', 'A1918035')
            ->assertJsonPath('data.0.tickets', 2)->assertJsonPath('data.0.reincidences', 1)->assertJsonPath('data.0.parts_units', 4);
        $this->getJson("/api/v1/reports/technicians{$query}")->assertJsonPath('data.0.name', 'Jesus Ramos')
            ->assertJsonPath('data.0.assigned', 2)->assertJsonPath('data.0.closed', 1)->assertJsonPath('data.0.pending_now', 1)->assertJsonPath('data.0.parts_units', 4);
        $this->getJson("/api/v1/reports/parts{$query}")->assertJsonPath('total', 4)
            ->assertJsonPath('by_part.0.name', 'PICKER')->assertJsonPath('by_part.0.quantity', 3)->assertJsonPath('by_model.0.name', 'OPTEVA 562');
        $this->getJson("/api/v1/reports/faults{$query}")->assertJsonPath('data.0.name', 'Falla de Dispensación')->assertJsonPath('data.0.tickets', 2);
        $this->getJson("/api/v1/reports/atms/{$this->atm->id}")->assertJsonPath('stats.tickets', 2)->assertJsonPath('parts.0.name', 'PICKER');
    }

    public function test_reports_download_as_excel_and_are_only_for_staff(): void
    {
        $this->actingAs($this->admin)->get('/api/v1/reports/technicians?from=2026-09-01&to=2026-09-30&format=xlsx')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="Reporte tecnicos 01-09-2026 al 30-09-2026.xlsx"');

        $this->actingAs($this->technician)->getJson('/api/v1/reports/summary')->assertForbidden();
    }

    public function test_summary_cards_open_a_detail_that_matches_their_numbers(): void
    {
        $this->actingAs($this->admin);
        $query = 'from=2026-09-01&to=2026-09-30';
        $kpis = $this->getJson("/api/v1/reports/summary?{$query}")->json('kpis');

        $this->getJson("/api/v1/reports/detail?type=tickets&metric=opened&{$query}")->assertJsonCount($kpis['opened'], 'data');
        $this->getJson("/api/v1/reports/detail?type=tickets&metric=closed&{$query}")->assertJsonCount($kpis['closed'], 'data');
        $this->getJson("/api/v1/reports/detail?type=tickets&metric=backlog&{$query}")->assertJsonCount($kpis['backlog'], 'data');
        $this->getJson("/api/v1/reports/detail?type=tickets&metric=reincidence&{$query}")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/reports/detail?type=tickets&metric=mttr&{$query}")
            ->assertJsonPath('title', 'Tiempo de resolución por ticket')
            ->assertJsonPath('data.0.duration_ms', 2 * 3600 * 1000);
    }

    public function test_technician_fault_and_part_rows_open_their_detail(): void
    {
        $this->actingAs($this->admin);
        $query = 'from=2026-09-01&to=2026-09-30';
        $fault = FaultType::firstWhere('name', 'Falla de Dispensación');
        $picker = Part::firstWhere('name', 'PICKER');

        $this->getJson("/api/v1/reports/detail?type=visits&technician_id={$this->technician->id}&{$query}")
            ->assertJsonPath('title', 'Atenciones de Jesus Ramos')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.parts', 'PICKER x1, FEEDSHAFT x1');
        $this->getJson("/api/v1/reports/detail?type=tickets&fault_type_id={$fault->id}&{$query}")
            ->assertJsonPath('title', 'Tickets por tipo de falla: Falla de Dispensación')
            ->assertJsonCount(2, 'data');
        $rows = $this->getJson("/api/v1/reports/detail?type=visits&part_id={$picker->id}&{$query}")
            ->assertJsonPath('title', 'Atenciones con PICKER')->json('data');
        $this->assertSame(3, collect($rows)->sum('parts_units'), 'unidades de PICKER, igual que el reporte de repuestos');

        $this->get("/api/v1/reports/detail?type=visits&part_id={$picker->id}&{$query}&format=xlsx")
            ->assertOk()->assertHeader('content-disposition', 'attachment; filename="Reporte atenciones 01-09-2026 al 30-09-2026.xlsx"');
        $this->get("/api/v1/reports/detail?type=tickets&metric=sla&{$query}&format=xlsx")->assertOk();
    }

    /** @param list<array{0: string, 1: int}> $parts */
    private function cycle(int $hours, array $parts, string $condition = 'Operativo'): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/tickets', [
            'atm_id' => $this->atm->id,
            'fault_type_id' => FaultType::firstOrCreate(['name' => 'Falla de Dispensación'])->id,
            'assigned_to' => $this->technician->id,
        ])->json('data.id');
        $this->travel($hours)->hours();
        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$id}/visits", [
            'work_done' => 'Trabajo',
            'atm_condition_id' => AtmCondition::firstWhere('name', $condition)->id,
            'parts_changed' => true,
            'parts' => collect($parts)->map(fn ($p) => ['part_id' => Part::firstOrCreate(['name' => $p[0]])->id, 'quantity' => $p[1]])->all(),
        ])->assertOk();
    }
}
