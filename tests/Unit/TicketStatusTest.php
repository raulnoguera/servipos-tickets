<?php

namespace Tests\Unit;

use App\Enums\TicketStatus;
use PHPUnit\Framework\TestCase;

class TicketStatusTest extends TestCase
{
    public function test_follows_the_agreed_flow(): void
    {
        $this->assertTrue(TicketStatus::Open->canTransitionTo(TicketStatus::Assigned));
        $this->assertTrue(TicketStatus::Assigned->canTransitionTo(TicketStatus::InTransit));
        $this->assertTrue(TicketStatus::InTransit->canTransitionTo(TicketStatus::OnSite));
        $this->assertTrue(TicketStatus::OnSite->canTransitionTo(TicketStatus::PendingPart));
        $this->assertTrue(TicketStatus::OnSite->canTransitionTo(TicketStatus::Closed));
    }

    public function test_pending_tickets_can_be_reopened_but_closed_ones_cannot(): void
    {
        $this->assertTrue(TicketStatus::PendingPart->canTransitionTo(TicketStatus::Assigned));
        $this->assertTrue(TicketStatus::PendingValidation->canTransitionTo(TicketStatus::Closed));
        $this->assertSame([], TicketStatus::Closed->allowedNext());
        $this->assertFalse(TicketStatus::Open->canTransitionTo(TicketStatus::Closed));
    }
}
