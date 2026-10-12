<?php

use App\Enums\Role;
use App\Models\Bank;
use App\Models\User;
use App\Services\AccessLinks;
use App\Services\Export\ConsolidadoExporter;
use App\Services\Import\ExcelHistoryImporter;
use App\Services\Inbound\DkimVerifier;
use App\Services\Inbound\InboundMailProcessor;
use App\Services\Inbound\IncomingMail;
use App\Services\Inbound\Mailbox;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;
use Webklex\PHPIMAP\Message;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('servipos:importar-excel
    {archivo : Ruta del consolidado .xlsx}
    {--banco= : Banco al que pertenecen los cajeros del archivo (opcional)}
    {--simular : Valida y genera el reporte sin guardar nada}', function (ExcelHistoryImporter $importer) {
    $path = $this->argument('archivo');
    $bank = $this->option('banco') ?: null;
    if (! is_file($path)) {
        $this->error("No existe el archivo: {$path}");

        return 1;
    }

    $dryRun = (bool) $this->option('simular');
    $report = $importer->import($path, $bank, $dryRun);

    $file = 'importacion/reporte-'.now()->format('Ymd-His').($dryRun ? '-simulacion' : '').'.txt';
    Storage::put($file, $report->toText());

    $this->table(['Concepto', 'Cantidad'], collect($report->counts)->map(fn ($n, $k) => [$k, $n])->values());
    $notes = collect($report->notes)->countBy('level');
    $this->line('Observaciones: '.$notes->map(fn ($n, $l) => "{$l}: {$n}")->implode(' · '));
    $this->info(($dryRun ? 'SIMULACIÓN (no se guardó nada). ' : 'Importación completada. ').'Reporte: '.Storage::path($file));

    return 0;
})->purpose('Importa el histórico del consolidado Excel (pestañas Tablas y Base General)');

Artisan::command('servipos:exportar-consolidado
    {--desde= : Fecha inicial (AAAA-MM-DD); por defecto, inicio del mes actual}
    {--hasta= : Fecha final (AAAA-MM-DD); por defecto, fin del mes de --desde}
    {--banco= : Nombre del banco (opcional)}
    {--salida= : Ruta del .xlsx a generar}', function (ConsolidadoExporter $exporter) {
    $from = Carbon::parse($this->option('desde') ?? now()->startOfMonth())->startOfDay();
    $to = $this->option('hasta') ? Carbon::parse($this->option('hasta'))->endOfDay() : $from->copy()->endOfMonth();
    $bankId = $this->option('banco') ? Bank::where('name', $this->option('banco'))->value('id') : null;
    if ($this->option('banco') && ! $bankId) {
        $this->error('No existe el banco '.$this->option('banco'));

        return 1;
    }

    $path = $this->option('salida') ?? storage_path('app/private/exportes/Consolidado '.$from->translatedFormat('F Y').'.xlsx');
    @mkdir(dirname($path), 0775, true);
    $exporter->save($exporter->build($from, $to, $bankId), $path);
    $this->info("Consolidado generado: {$path}");

    return 0;
})->purpose('Exporta las visitas al formato del consolidado Excel actual');

Artisan::command('servipos:claves-push {--forzar : Reemplaza claves existentes (los dispositivos deberán activar las notificaciones de nuevo)}', function () {
    $env = base_path('.env');
    $content = (string) file_get_contents($env);
    if (preg_match('/^VAPID_PUBLIC_KEY=.+$/m', $content) && ! $this->option('forzar')) {
        $this->warn('Ya hay claves VAPID en .env. Use --forzar para reemplazarlas.');

        return 0;
    }

    $keys = VAPID::createVapidKeys();
    foreach (['VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_PRIVATE_KEY' => $keys['privateKey']] as $name => $value) {
        $content = preg_match("/^{$name}=.*$/m", $content)
            ? preg_replace("/^{$name}=.*$/m", "{$name}={$value}", $content)
            : rtrim($content).PHP_EOL."{$name}={$value}".PHP_EOL;
    }
    file_put_contents($env, $content);
    $this->info('Claves de notificaciones push guardadas en .env. Ejecute php artisan config:cache si usa caché de configuración.');

    return 0;
})->purpose('Genera las claves VAPID para las notificaciones push y las guarda en .env');

Artisan::command('servipos:crear-admin
    {correo : Correo del administrador (su usuario para ingresar)}
    {--nombre=Administrador : Nombre completo}', function (AccessLinks $links) {
    $email = mb_strtolower(trim($this->argument('correo')));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Escriba un correo válido.');

        return 1;
    }

    // Queda con una contraseña aleatoria que nadie conoce; el administrador crea la suya con el enlace.
    $user = User::firstOrCreate(['email' => $email], [
        'name' => $this->option('nombre'),
        'password' => Str::password(32),
        'role' => Role::Admin,
        'active' => true,
        'email_verified_at' => now(),
    ]);
    if (! $user->wasRecentlyCreated && $user->role !== Role::Admin) {
        $this->error("Ya existe un usuario con ese correo y no es administrador ({$user->role->label()}).");

        return 1;
    }

    $link = $links->issue($user);
    $this->info(($user->wasRecentlyCreated ? 'Administrador creado: ' : 'El administrador ya existía: ').$user->name." <{$email}>");
    $this->line('Enlace para crear su contraseña (un solo uso, vence '.$link['expires_at']->format('d/m/Y h:i a').'):');
    $this->line($link['url']);

    return 0;
})->purpose('Crea el administrador inicial y muestra su enlace de acceso de un solo uso');

Artisan::command('servipos:leer-correo
    {--archivo=* : Procesa archivos .eml en lugar del buzón (para probar con muestras)}
    {--simular : Muestra qué haría con cada correo, sin guardar ni avisar}
    {--probar : Solo prueba la conexión con el buzón}', function (Mailbox $mailbox, InboundMailProcessor $processor) {
    $files = $this->option('archivo');
    $dryRun = (bool) $this->option('simular');
    if (! $files && ! $mailbox->configured()) {
        $this->warn('La lectura del buzón no está configurada (INBOUND_MAIL_* en .env).');

        return 0;
    }

    if ($this->option('probar')) {
        try {
            $this->info('Conexión correcta. Correos en la carpeta desde '.$mailbox->since()->format('d/m/Y H:i').': '.$mailbox->check());

            return 0;
        } catch (Throwable $e) {
            $this->error('No se pudo leer el buzón: '.$e->getMessage());

            return 1;
        }
    }

    $mails = $files
        ? collect($files)->map(fn (string $file) => IncomingMail::fromMessage(Message::fromFile($file), app(DkimVerifier::class)))
        : $mailbox->pending();

    $rows = [];
    foreach ($mails as $mail) {
        if ($dryRun) {
            DB::beginTransaction();
        }
        $email = $processor->process($mail, notify: ! $dryRun)->load(['ticket', 'atm']);
        $rows[] = [
            $mail->sentAt->timezone(config('app.timezone'))->format('d/m H:i'),
            mb_strimwidth((string) $mail->subject, 0, 44, '…'),
            $email->status,
            $email->ticket?->number ?? $email->atm?->atm_number ?? '—',
            mb_strimwidth((string) $email->reason, 0, 70, '…'),
        ];
        if ($dryRun) {
            DB::rollBack();
        }
    }

    if ($rows) {
        $this->table(['Enviado', 'Asunto', 'Resultado', 'Ticket / ATM', 'Motivo'], $rows);
    }
    $this->info(count($rows).' correo(s) '.($dryRun ? 'analizados (simulación, no se guardó nada).' : 'procesados.'));

    return 0;
})->purpose('Lee el buzón de reportes de los bancos y abre los tickets');

// El buzón se revisa cada dos minutos; sin configuración, la tarea no hace nada.
Schedule::command('servipos:leer-correo')->everyTwoMinutes()->withoutOverlapping(10)
    ->when(fn () => app(Mailbox::class)->configured());
