<?php

namespace App\Services\Inbound;

/**
 * Extrae los datos de un reporte de falla escrito por el banco. Los correos
 * siguen una plantilla informal:
 *
 *     ATM A1918189 BARCELONA CENTRO
 *     Serial: F3301065
 *     Falla : NO ENTRA EN SERVICIO
 *     Observación: Verificar el ATM ya que no quiere entrar en servicio…
 *
 * con variantes: el número abreviado ("ATM 6524"), la falla sin la palabra
 * "Falla" ("Monitor") o un hilo interno del banco citado debajo.
 */
class BankReportParser
{
    /** Líneas que no son la falla aunque vengan después del serial. */
    private const NOT_A_FAULT = '/^(gracias|saludos|buen[oa]s?\b|atentamente|cordial|observaci|sin m[aá]s|\[)/iu';

    /**
     * @return array{atm_number: ?string, agency: ?string, serial: ?string, fault: ?string, observation: ?string}
     */
    public function parse(?string $subject, ?string $body): array
    {
        $text = $this->ownText((string) $body);
        $lines = array_map(fn (string $line) => trim($line, " \t>"), explode("\n", $text));

        $number = $agency = $serial = $fault = null;
        $after = null; // posición desde la que puede venir la falla "suelta"

        foreach ($lines as $i => $line) {
            if ($number === null && preg_match('/^ATM\s*[:#]?\s*([A-Za-z]{0,2}\d{4,8})\b\s*(.*)$/iu', $line, $m)) {
                $number = strtoupper($m[1]);
                $agency = $this->clean($m[2]);
                $after = $i;
            } elseif ($serial === null && preg_match('/^Serial\s*[:#]?\s*([A-Za-z0-9-]{4,30})\b/iu', $line, $m)) {
                $serial = strtoupper($m[1]);
                $after = $i;
            } elseif ($fault === null && preg_match('/^Falla\s*:?\s*(\S.*)$/iu', $line, $m)) {
                $fault = $this->clean($m[1]);
            }
        }

        // Sin la palabra "Falla": la primera línea con contenido después del serial.
        if ($fault === null && $after !== null) {
            foreach (array_slice($lines, $after + 1) as $line) {
                if ($line === '') {
                    continue;
                }
                if (! preg_match(self::NOT_A_FAULT, $line) && mb_strlen($line) <= 80) {
                    $fault = $this->clean($line);
                }
                break;
            }
        }

        if ($number === null && preg_match('/\bATM\W{0,3}([A-Za-z]{0,2}\d{4,8})\b/iu', (string) $subject, $m)) {
            $number = strtoupper($m[1]);
        }

        return [
            'atm_number' => $number,
            'agency' => $agency,
            'serial' => $serial,
            'fault' => $fault ? mb_strimwidth($fault, 0, 120, '…') : null,
            'observation' => $this->observation($text),
        ];
    }

    /** El texto que escribió quien envía: sin el hilo citado ni el aviso legal del banco. */
    public function ownText(string $body): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $body);
        $cuts = [
            '/^[ \t>]*(De|From)[ \t]*:[ \t]*\S.*$/miu',
            '/^[ \t>]*-{2,}[ \t]*(Mensaje original|Original Message)/miu',
            '/^[ \t>]*El .{5,80} escribi[oó]:[ \t]*$/miu',
            '/Este correo electr[oó]nico y cualquier archivo adjunto/iu',
        ];
        foreach ($cuts as $cut) {
            if (preg_match($cut, $text, $m, PREG_OFFSET_CAPTURE)) {
                $text = substr($text, 0, $m[0][1]);
            }
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private function observation(string $text): ?string
    {
        if (! preg_match('/^[ \t>]*Observaci[oó]n(?:es)?[ \t]*:?[ \t]*(\S.*?)(?=\n[ \t>]*\n|\n[ \t>]*(?:Gracias|Saludos|Atentamente)|\z)/smiu', $text, $m)) {
            return null;
        }

        return mb_strimwidth($this->clean($m[1]), 0, 1000, '…');
    }

    private function clean(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value), " \t.:-");

        return $value === '' ? null : $value;
    }
}
