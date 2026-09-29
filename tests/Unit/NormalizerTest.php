<?php

namespace Tests\Unit;

use App\Services\Import\Normalizer;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase
{
    public function test_fixes_state_typos_and_accents(): void
    {
        $this->assertSame('Portuguesa', Normalizer::state('POTUGUESA'));
        $this->assertSame('Anzoátegui', Normalizer::state('ANZOATEGUI'));
        $this->assertSame('Distrito Capital', Normalizer::state(' DISTRITO  CAPITAL '));
        $this->assertNull(Normalizer::state('  '));
    }

    public function test_normalizes_parts_and_models(): void
    {
        $this->assertSame('PICKER', Normalizer::part('Picker'));
        $this->assertSame('CAJETIN DE DINERO', Normalizer::part('Cajetin  de dinero '));
        $this->assertSame('OPTEVA 562', Normalizer::model('OTEVA 562'));
        $this->assertSame('OPTEVA 562', Normalizer::model('OPTEVA562'));
        $this->assertSame('OPTEVA 1522', Normalizer::model('opteva 1522'));
    }

    public function test_reads_yes_no(): void
    {
        $this->assertTrue(Normalizer::yesNo('Sí'));
        $this->assertFalse(Normalizer::yesNo('no'));
        $this->assertNull(Normalizer::yesNo(null));
    }
}
