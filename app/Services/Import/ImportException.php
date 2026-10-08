<?php

namespace App\Services\Import;

use RuntimeException;

/** Problema del archivo que se le puede explicar al usuario (pestaña o columna faltante, histórico ya cargado). */
class ImportException extends RuntimeException {}
