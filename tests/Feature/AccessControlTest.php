<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_use_the_api(): void
    {
        $this->getJson('/api/v1/tickets')->assertUnauthorized();
    }

    public function test_login_rejects_inactive_users(): void
    {
        $user = User::factory()->create(['active' => false]);

        $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertJsonValidationErrors('email');
    }

    public function test_technician_only_sees_own_tickets_and_cannot_reach_admin_areas(): void
    {
        $mine = User::factory()->technician()->create();
        $other = User::factory()->technician()->create();
        $atm = Atm::factory()->create();
        $own = Ticket::create(['number' => 'TK-2026-00001', 'atm_id' => $atm->id, 'status' => TicketStatus::Assigned, 'assigned_to' => $mine->id, 'opened_at' => now()]);
        $foreign = Ticket::create(['number' => 'TK-2026-00002', 'atm_id' => $atm->id, 'status' => TicketStatus::Assigned, 'assigned_to' => $other->id, 'opened_at' => now()]);

        $this->actingAs($mine)->getJson('/api/v1/tickets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->actingAs($mine)->getJson("/api/v1/tickets/{$foreign->id}")->assertForbidden();
        $this->actingAs($mine)->postJson('/api/v1/tickets', ['atm_id' => $atm->id])->assertForbidden();
        $this->actingAs($mine)->getJson('/api/v1/dashboard')->assertForbidden();
    }

    public function test_only_admins_manage_users_and_catalogs(): void
    {
        $operator = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($operator)->postJson('/api/v1/catalogs/parts', ['name' => 'stacker'])->assertForbidden();
        $this->actingAs($admin)->postJson('/api/v1/catalogs/parts', ['name' => 'stacker'])->assertCreated()->assertJsonPath('name', 'STACKER');
    }

    public function test_dashboard_returns_kpis(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/dashboard?period=week')
            ->assertOk()
            ->assertJsonStructure(['kpis' => ['active', 'pending_part', 'closed', 'avg_duration_ms'], 'daily', 'top_parts', 'technicians', 'recent']);
    }

    public function test_dashboard_counts_active_tickets_per_technician(): void
    {
        $technician = User::factory()->technician()->create();
        Ticket::create(['number' => 'TK-2026-00001', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::InTransit, 'assigned_to' => $technician->id, 'opened_at' => now()]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/dashboard')
            ->assertJsonPath('technicians.0.active', 1);
    }
}
