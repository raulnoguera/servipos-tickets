<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\FaultType;
use App\Models\Part;
use App\Models\State;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Datos de demostración para el ambiente de pruebas: usuarios de ejemplo y
 * ~70 tickets de los últimos 30 días recorriendo el flujo real (apertura,
 * asignación, tránsito, visitas y cierre) con tiempos verosímiles.
 *
 * Uso: php artisan db:seed --class=DemoSeeder  (requiere DEMO_PASSWORD en .env)
 * No usar en producción.
 */
class DemoSeeder extends Seeder
{
    private const FAULTS = ['Falla de Dispensación', 'Falla de Impresora', 'Comunicación', 'Software', 'Falla de Lectora', 'Revision General'];

    private const PARTS = ['PICKER', 'CORREAS VERTICALES', 'FEEDSHAFT', 'STACKER', 'PRESENTER', 'KIT DE MANTENIMIENTO', 'LECTORA', 'IMPRESORA DE RECIBO', 'CAJETIN DE DINERO'];

    private const WORK = [
        'Se reemplazan correas verticales desgastadas, se ajusta el EDC y se efectúan pruebas de dispensado.',
        'Limpieza general del dispensador y ajuste de tensión de cassettes; pruebas satisfactorias.',
        'Se reinstala el aplicativo y se configuran direcciones de red; el cajero queda en línea.',
        'Se retira billete atascado en el presenter y se efectúan pruebas generales.',
        'Revisión de la impresora de recibos; se reemplaza el módulo y se imprimen pruebas.',
        'Diagnóstico del picker; se reemplaza y se calibra el sensor de doble billete.',
    ];

    public function run(TicketService $tickets): void
    {
        $password = env('DEMO_PASSWORD') ?: throw new RuntimeException('Defina DEMO_PASSWORD en .env para crear los usuarios de demostración.');
        $this->call(AtmConditionSeeder::class);

        $states = collect(['Distrito Capital', 'Miranda', 'Carabobo', 'Aragua', 'Lara', 'Anzoátegui'])
            ->map(fn (string $name) => State::firstOrCreate(['name' => $name]));
        $faults = collect(self::FAULTS)->map(fn (string $name) => FaultType::firstOrCreate(['name' => $name]));
        $parts = collect(self::PARTS)->map(fn (string $name) => Part::firstOrCreate(['name' => $name]));

        $operator = User::updateOrCreate(['email' => 'operador@servipos.test'], [
            'name' => 'Operador Demo', 'password' => $password, 'role' => Role::Operator, 'email_verified_at' => now(),
        ]);
        $demoTech = User::updateOrCreate(['email' => 'tecnico@servipos.test'], [
            'name' => 'Técnico Demo', 'password' => $password, 'role' => Role::Technician, 'phone' => '0414-0000000', 'email_verified_at' => now(),
        ]);
        $demoTech->states()->syncWithoutDetaching($states->take(2)->pluck('id'));

        // Técnicos existentes (p. ej. importados del Excel) reciben teléfono y cobertura de ejemplo.
        $technicians = User::technicians()->where('active', true)->get()->each(function (User $t, int $i) use ($states) {
            if (! $t->phone) {
                $t->update(['phone' => sprintf('0414-%07d', 1000000 + $i)]);
            }
            if ($t->states()->doesntExist()) {
                $t->states()->sync($states->random(2)->pluck('id'));
            }
        });

        $atms = Atm::where('active', true)->get();
        if ($atms->isEmpty()) {
            $atms = Atm::factory()->count(40)->sequence(fn () => ['state_id' => $states->random()->id])->create();
        }

        $conditions = AtmCondition::all()->keyBy('name');
        $realNow = CarbonImmutable::now();
        $rand = fn (int $min, int $max) => random_int($min, $max);

        try {
            foreach (range(1, 70) as $i) {
                // Más tickets recientes que antiguos; los de hoy quedan en curso.
                $daysAgo = min(29, (int) floor(abs($rand(0, 1000) / 1000) ** 1.6 * 30));
                $openedAt = $realNow->subDays($daysAgo)->setTime($rand(7, 16), $rand(0, 59), $rand(0, 59));
                if ($openedAt->greaterThan($realNow)) {
                    $openedAt = $realNow->subMinutes($rand(20, 240));
                }
                $this->at($openedAt);

                $atm = $atms->random();
                $tech = $technicians->random();
                $ticket = $tickets->open([
                    'atm_id' => $atm->id,
                    'fault_type_id' => $faults->random()->id,
                    'description' => 'Reporte del banco: '.strtolower($faults->random()->name).' en ATM '.$atm->atm_number.'.',
                    'bank_reference' => 'INC'.$rand(100000, 999999),
                ], $operator);

                $cursor = $openedAt->addMinutes($rand(5, 40));
                $stage = $daysAgo === 0 ? $rand(0, 4) : 9; // tickets de hoy quedan en distintas etapas
                if ($stage === 0 || $cursor->greaterThan($realNow)) {
                    continue;
                }
                $this->at($cursor);
                $tickets->assign($ticket, $tech, $operator);

                $cursor = $cursor->addMinutes($rand(10, 45));
                if ($stage === 1 || $cursor->greaterThan($realNow)) {
                    continue;
                }
                $this->at($cursor);
                $tickets->transition($ticket, TicketStatus::InTransit, $tech);

                $cursor = $cursor->addMinutes($rand(25, 150));
                if ($stage === 2 || $cursor->greaterThan($realNow)) {
                    continue;
                }
                $this->at($cursor);
                $tickets->transition($ticket, TicketStatus::OnSite, $tech);

                $cursor = $cursor->addMinutes($rand(30, 160));
                if ($stage === 3 || $cursor->greaterThan($realNow)) {
                    continue;
                }
                $roll = $rand(1, 100);
                $condition = $roll <= 68 ? 'Operativo' : ($roll <= 88 ? 'Operativo / Pendiente por Repuesto' : 'Fuera de Servicio');
                $this->visit($tickets, $ticket, $tech, $conditions[$condition]->id, $cursor, $parts, $condition === 'Operativo' && $rand(1, 100) <= 45);

                // Parte de los pendientes se resuelve con una segunda visita días después.
                $followUp = $cursor->addDays($rand(1, 4))->setTime($rand(8, 16), $rand(0, 59));
                if ($condition !== 'Operativo' && $rand(1, 100) <= 65 && $followUp->lessThan($realNow)) {
                    $this->visit($tickets, $ticket->refresh(), $tech, $conditions['Operativo']->id, $followUp, $parts, true);
                }
            }
        } finally {
            $this->at(null);
        }
    }

    private function visit(TicketService $tickets, Ticket $ticket, User $tech, int $conditionId, CarbonImmutable $at, $parts, bool $withParts): void
    {
        $this->at($at);
        $tickets->registerVisit($ticket, [
            'constancia_number' => (string) random_int(6300, 7999),
            'work_done' => self::WORK[array_rand(self::WORK)],
            'atm_condition_id' => $conditionId,
            'arrived_at' => $at->subMinutes(random_int(30, 120))->toIso8601String(),
            'finished_at' => $at->toIso8601String(),
            'parts' => $withParts
                ? $parts->random(random_int(1, 2))->map(fn (Part $p) => ['part_id' => $p->id, 'quantity' => random_int(1, 3)])->values()->all()
                : [],
        ], $tech);
    }

    private function at(?CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }
}
