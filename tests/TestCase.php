<?php

namespace Tests;

use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** Contraseña con la que la fábrica crea los usuarios de prueba (al azar, nunca escrita en el código). */
    protected function claveDePrueba(): string
    {
        return UserFactory::plainPassword();
    }

    /** Otra contraseña al azar que cumple las reglas del sistema (10+ caracteres, letras y números). */
    protected static function otraClave(): string
    {
        return Str::password(16, symbols: false);
    }
}
