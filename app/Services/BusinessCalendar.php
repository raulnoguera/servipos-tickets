<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Horario bancario: el SLA solo corre en los días hábiles (sin feriados) y
 * dentro del horario de atención. Un ticket que llega a las 2 p. m. suma una
 * hora ese día y sigue sumando desde las 8 a. m. del siguiente día hábil.
 */
class BusinessCalendar
{
    public const DEFAULT_SCHEDULE = ['start' => '08:00', 'end' => '15:00', 'days' => [1, 2, 3, 4, 5]];

    /** Tope de días que se recorren al calcular (evita ciclos sin fin si no hay días hábiles). */
    private const MAX_DAYS = 3660;

    /** @var array<string, true> */
    private array $holidays;

    /**
     * @param  array{start: string, end: string, days: list<int>}  $schedule  días ISO: 1 = lunes … 7 = domingo
     * @param  list<string>  $holidays  fechas Y-m-d
     */
    public function __construct(private array $schedule, array $holidays = [])
    {
        $this->holidays = array_fill_keys($holidays, true);
    }

    public static function fromSettings(): self
    {
        return new self(
            Setting::get('sla.schedule', self::DEFAULT_SCHEDULE),
            Holiday::pluck('date')->map(fn ($d) => $d->toDateString())->all(),
        );
    }

    /** @return array{start: string, end: string, days: list<int>} */
    public function schedule(): array
    {
        return $this->schedule;
    }

    /** Minutos de un día hábil completo (8:00 a 15:00 = 420). */
    public function dayMinutes(): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $this->schedule['start']));
        [$eh, $em] = array_map('intval', explode(':', $this->schedule['end']));

        return max(0, ($eh * 60 + $em) - ($sh * 60 + $sm));
    }

    public function isWorkingDay(CarbonInterface $day): bool
    {
        return in_array($day->dayOfWeekIso, $this->schedule['days'], true) && ! isset($this->holidays[$day->toDateString()]);
    }

    /** El momento en que se cumplen $minutes minutos hábiles contados desde $start. */
    public function addMinutes(CarbonInterface $start, int $minutes): CarbonImmutable
    {
        $at = $this->nextOpen($this->local($start));
        $left = $minutes * 60;
        for ($i = 0; $i < self::MAX_DAYS && $this->dayMinutes() > 0; $i++) {
            $available = $this->closing($at)->getTimestamp() - $at->getTimestamp();
            if ($left <= $available) {
                return $at->addSeconds($left);
            }
            $left -= $available;
            $at = $this->nextOpen($at->addDay()->startOfDay());
        }

        return $at;
    }

    /** Minutos hábiles entre dos momentos (0 si $to no es posterior a $from). */
    public function minutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $from = $this->local($from);
        $to = $this->local($to);
        $seconds = 0;
        $day = $from->startOfDay();
        for ($i = 0; $i < self::MAX_DAYS && $day->lessThanOrEqualTo($to); $i++, $day = $day->addDay()) {
            if (! $this->isWorkingDay($day)) {
                continue;
            }
            $start = max($from->getTimestamp(), $this->opening($day)->getTimestamp());
            $end = min($to->getTimestamp(), $this->closing($day)->getTimestamp());
            $seconds += max(0, $end - $start);
        }

        return intdiv($seconds, 60);
    }

    /** El mismo momento si está dentro del horario; si no, la próxima apertura. */
    private function nextOpen(CarbonImmutable $at): CarbonImmutable
    {
        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            if ($this->isWorkingDay($at)) {
                if ($at->lessThan($this->opening($at))) {
                    return $this->opening($at);
                }
                if ($at->lessThan($this->closing($at))) {
                    return $at;
                }
            }
            $at = $this->opening($at->addDay());
        }

        return $at;
    }

    private function opening(CarbonImmutable $day): CarbonImmutable
    {
        return $day->setTimeFromTimeString($this->schedule['start']);
    }

    private function closing(CarbonImmutable $day): CarbonImmutable
    {
        return $day->setTimeFromTimeString($this->schedule['end']);
    }

    private function local(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(config('app.timezone'));
    }
}
