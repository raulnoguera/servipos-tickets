<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_are_saved_in_the_bell_even_without_push_devices(): void
    {
        $admin = User::factory()->admin()->create();
        $technician = User::factory()->technician()->create(['name' => 'Jesus Ramos']);
        $ticket = Ticket::create([
            'number' => 'TK-2026-00009', 'atm_id' => Atm::factory()->create()->id, 'status' => TicketStatus::Assigned,
            'assigned_to' => $technician->id, 'opened_at' => now(),
        ]);

        $this->actingAs($technician)->postJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'en_transito'])->assertOk();

        $this->actingAs($admin)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('data.0.kind', 'moved')
            ->assertJsonPath('data.0.title', 'TK-2026-00009 · En camino')
            ->assertJsonPath('data.0.url', "/tickets/{$ticket->id}")
            ->assertJsonPath('data.0.read', false);
        $this->actingAs($technician)->getJson('/api/v1/notifications/unread')->assertJsonPath('unread', 0);
    }

    public function test_users_mark_their_own_notifications_as_read(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = UserNotification::create(['user_id' => $user->id, 'kind' => 'closed', 'title' => 'A', 'body' => 'a']);
        UserNotification::create(['user_id' => $user->id, 'kind' => 'moved', 'title' => 'B', 'body' => 'b']);
        $foreign = UserNotification::create(['user_id' => $other->id, 'kind' => 'moved', 'title' => 'C', 'body' => 'c']);

        $this->actingAs($user)->postJson("/api/v1/notifications/{$mine->id}/read")->assertJsonPath('unread', 1);
        $this->actingAs($user)->postJson("/api/v1/notifications/{$foreign->id}/read");
        $this->assertNull($foreign->refresh()->read_at, 'no puede marcar avisos de otro usuario');

        $this->actingAs($user)->postJson('/api/v1/notifications/read-all')->assertJsonPath('unread', 0);
        $this->assertSame(1, $other->inbox()->whereNull('read_at')->count());
    }

    public function test_test_push_is_not_stored_in_the_bell(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/push/test')->assertOk();

        $this->assertSame(0, $user->inbox()->count());
    }
}
