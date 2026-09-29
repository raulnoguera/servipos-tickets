<?php

namespace Tests\Feature;

use App\Services\TicketNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbers_are_correlative_and_restart_each_year(): void
    {
        $numbers = app(TicketNumberGenerator::class);

        $this->assertSame('TK-2026-00001', $numbers->next(2026));
        $this->assertSame('TK-2026-00002', $numbers->next(2026));
        $this->assertSame('TK-2027-00001', $numbers->next(2027));
        $this->assertSame('TK-2026-00003', $numbers->next(2026));
    }
}
