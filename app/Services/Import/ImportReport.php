<?php

namespace App\Services\Import;

/** Resultado de la importación: conteos y observaciones por fila. */
class ImportReport
{
    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<array{level: string, where: string, message: string}> */
    public array $notes = [];

    public function count(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    public function warn(string $where, string $message): void
    {
        $this->notes[] = ['level' => 'AVISO', 'where' => $where, 'message' => $message];
    }

    public function fix(string $where, string $message): void
    {
        $this->notes[] = ['level' => 'CORREGIDO', 'where' => $where, 'message' => $message];
    }

    public function error(string $where, string $message): void
    {
        $this->notes[] = ['level' => 'ERROR', 'where' => $where, 'message' => $message];
    }

    public function hasErrors(): bool
    {
        return collect($this->notes)->contains('level', 'ERROR');
    }

    public function toText(): string
    {
        $lines = ['REPORTE DE IMPORTACIÓN — '.now()->format('Y-m-d H:i:s'), '', 'Resumen:'];
        foreach ($this->counts as $key => $n) {
            $lines[] = sprintf('  %-40s %d', $key, $n);
        }
        foreach (['ERROR', 'AVISO', 'CORREGIDO'] as $level) {
            $notes = array_filter($this->notes, fn ($n) => $n['level'] === $level);
            if (! $notes) {
                continue;
            }
            $lines[] = '';
            $lines[] = "{$level} (".count($notes).'):';
            foreach ($notes as $n) {
                $lines[] = "  [{$n['where']}] {$n['message']}";
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
