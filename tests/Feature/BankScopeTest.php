<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\AuditLog;
use App\Models\Bank;
use App\Models\FaultType;
use App\Models\InboundEmail;
use App\Models\Part;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Visit;
use App\Services\PushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BankScopeTest extends TestCase
{
    use RefreshDatabase;

    private Bank $bnc;

    private Bank $other;

    private Atm $bncAtm;

    private Atm $otherAtm;

    private Ticket $bncTicket;

    private Ticket $otherTicket;

    private User $bncOperator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bnc = Bank::create(['name' => 'Banco Nacional de Crédito']);
        $this->other = Bank::create(['name' => 'Otro Banco']);
        $this->bncAtm = Atm::factory()->create(['atm_number' => 'A1910001', 'bank_id' => $this->bnc->id]);
        $this->otherAtm = Atm::factory()->create(['atm_number' => 'B2220001', 'bank_id' => $this->other->id]);
        $this->bncTicket = $this->ticket($this->bncAtm);
        $this->otherTicket = $this->ticket($this->otherAtm);

        $this->bncOperator = User::factory()->create();
        $this->bncOperator->banks()->attach($this->bnc);
    }

    private function ticket(Atm $atm): Ticket
    {
        return Ticket::create([
            'number' => 'PRUEBA-'.Str::padLeft((string) (Ticket::withoutGlobalScopes()->count() + 1), 3, '0'),
            'atm_id' => $atm->id,
            'bank_id' => $atm->bank_id,
            'status' => TicketStatus::Open,
            'priority' => 'media',
            'opened_at' => now()->subHour(),
        ]);
    }

    public function test_an_operator_assigned_to_a_bank_only_sees_that_bank(): void
    {
        $this->actingAs($this->bncOperator);

        $this->getJson('/api/v1/tickets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->bncTicket->id);
        $this->getJson("/api/v1/tickets/{$this->bncTicket->id}")->assertOk();
        $this->getJson("/api/v1/tickets/{$this->otherTicket->id}")->assertNotFound();
        $this->postJson("/api/v1/tickets/{$this->otherTicket->id}/comments", ['body' => 'Hola'])->assertNotFound();

        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('kpis.unassigned', 1)->assertJsonPath('kpis.opened', 1);
        $this->getJson('/api/v1/reports/summary')->assertOk()->assertJsonPath('kpis.opened', 1);

        $this->getJson('/api/v1/atms')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.atm_number', 'A1910001');
        $this->getJson('/api/v1/atms/lookup/B2220001')->assertNotFound();
        $this->getJson("/api/v1/reports/atms/{$this->otherAtm->id}")->assertNotFound();

        $this->getJson('/api/v1/catalogs')->assertOk()->assertJsonCount(1, 'banks')->assertJsonPath('banks.0.name', 'Banco Nacional de Crédito');
    }

    public function test_it_cannot_open_tickets_for_another_bank(): void
    {
        $fault = FaultType::create(['name' => 'Falla de Lectora']);

        $this->actingAs($this->bncOperator)->postJson('/api/v1/tickets', ['atm_id' => $this->otherAtm->id, 'fault_type_id' => $fault->id])
            ->assertStatus(422)->assertJsonValidationErrors('atm_id');
        $this->actingAs($this->bncOperator)->postJson('/api/v1/tickets', ['atm_id' => $this->bncAtm->id, 'fault_type_id' => $fault->id])
            ->assertCreated();
    }

    public function test_administrators_and_operators_without_banks_see_everything(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->banks()->attach($this->bnc); // A un administrador no lo limita nada.
        $everyBank = User::factory()->create();

        foreach ([$admin, $everyBank] as $user) {
            $this->actingAs($user)->getJson('/api/v1/tickets')->assertOk()->assertJsonCount(2, 'data');
            $this->actingAs($user)->getJson("/api/v1/tickets/{$this->otherTicket->id}")->assertOk();
        }
    }

    public function test_bank_emails_are_shown_to_the_operators_of_that_bank(): void
    {
        $email = fn (?Atm $atm, string $id) => InboundEmail::create([
            'message_id' => $id, 'from_email' => 'reportes@banco.test', 'sent_at' => now(), 'status' => InboundEmail::REVIEW, 'atm_id' => $atm?->id,
        ]);
        $email($this->bncAtm, 'a@test');
        $email($this->otherAtm, 'b@test');
        $email(null, 'c@test');

        // Los del banco propio y los que no se pudieron asociar a ningún cajero.
        $this->actingAs($this->bncOperator)->getJson('/api/v1/inbound-emails?status=revisar')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs(User::factory()->create())->getJson('/api/v1/inbound-emails?status=revisar')->assertJsonCount(3, 'data');
    }

    public function test_notifications_only_reach_operators_who_can_see_the_ticket(): void
    {
        $otherOperator = User::factory()->create();
        $otherOperator->banks()->attach($this->other);
        $everyBank = User::factory()->create();

        app(PushNotifier::class)->emailTicket($this->otherTicket);

        $this->assertSame(0, UserNotification::where('user_id', $this->bncOperator->id)->count());
        $this->assertSame(1, UserNotification::where('user_id', $otherOperator->id)->count());
        $this->assertSame(1, UserNotification::where('user_id', $everyBank->id)->count());
    }

    public function test_the_administrator_assigns_banks_to_operators_only(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->create(['name' => 'Operadora Caracas']);

        $this->actingAs($admin)->putJson("/api/v1/users/{$operator->id}", [
            'name' => $operator->name, 'email' => $operator->email, 'role' => 'operator', 'active' => true, 'bank_ids' => [$this->bnc->id],
        ])->assertOk()->assertJsonPath('data.banks', ['Banco Nacional de Crédito']);
        $this->assertSame([$this->bnc->id], $operator->banks()->pluck('banks.id')->all());
        $this->assertSame(['antes' => '', 'ahora' => 'Banco Nacional de Crédito'], AuditLog::where('action', 'user.updated')->latest('id')->value('meta')['bancos']);

        // Un técnico no se limita por banco: lo que se envíe se ignora.
        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Técnico', 'role' => 'technician', 'phone' => '0414-7654321', 'active' => true, 'bank_ids' => [$this->bnc->id],
        ])->assertCreated()->assertJsonPath('data.banks', []);
    }

    public function test_the_visual_report_counts_reports_reasons_parts_and_zones_of_the_operators_bank(): void
    {
        $operativo = AtmCondition::create(['name' => 'Operativo', 'resulting_ticket_status' => 'concluido', 'sort' => 1]);
        $repuesto = AtmCondition::create(['name' => 'Operativo / Pendiente por Repuesto', 'resulting_ticket_status' => 'pendiente_repuesto', 'sort' => 2]);
        $dispensacion = FaultType::create(['name' => 'Falla de Dispensación']);
        $lectora = FaultType::create(['name' => 'Falla de Lectora']);
        $feedshaft = Part::create(['name' => 'FEEDSHAFT']);
        $visit = function (Ticket $ticket, $condition, $fault, int $parts = 0) use ($feedshaft) {
            $v = Visit::create(['ticket_id' => $ticket->id, 'visit_date' => now()->toDateString(), 'atm_condition_id' => $condition->id, 'fault_type_id' => $fault->id, 'parts_changed' => $parts > 0]);
            if ($parts) {
                $v->parts()->attach($feedshaft->id, ['quantity' => $parts]);
            }
        };
        $visit($this->bncTicket, $operativo, $dispensacion, 2);
        $visit($this->bncTicket, $operativo, $dispensacion);
        $visit($this->bncTicket, $repuesto, $lectora, 1);
        $visit($this->otherTicket, $operativo, $lectora, 5); // de otro banco: no cuenta para el operador del BNC

        $report = $this->actingAs($this->bncOperator)->getJson('/api/v1/reports/visual')->assertOk()->json();

        $this->assertSame(3, $report['reports']);
        $this->assertSame([['name' => 'Operativo', 'total' => 2, 'pct' => 66.7], ['name' => 'Operativo / Pendiente por Repuesto', 'total' => 1, 'pct' => 33.3]], $report['statuses']);
        $this->assertSame(['Falla de Dispensación', 'Falla de Lectora'], array_column($report['faults'], 'name'));
        $this->assertSame(['total' => 3, 'by_part' => [['name' => 'FEEDSHAFT', 'quantity' => 3]]], $report['parts']);
        $this->assertSame(1, $report['zones']['atms']);
        $this->assertSame(1, $report['zones']['attended']);

        // Sin restricción de banco se ven los dos.
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/v1/reports/visual')->assertJsonPath('reports', 4)->assertJsonPath('zones.atms', 2);
    }
}
