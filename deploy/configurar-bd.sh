#!/usr/bin/env bash
# Pide la contraseña de MySQL sin mostrarla, la guarda en .env y prueba la conexión.
# Uso (en el servidor, dentro de la carpeta de la app): bash deploy/configurar-bd.sh
# En SiteGround: PHP_BIN=php83 bash deploy/configurar-bd.sh
set -euo pipefail
cd "$(dirname "$0")/.."
PHP="${PHP_BIN:-php}"
[ -f .env ] || { echo "No existe .env en $(pwd)"; exit 1; }

read -rsp "Contraseña de la base de datos MySQL: " DB_PW; echo
[ -n "$DB_PW" ] || { echo "No se escribió ninguna contraseña."; exit 1; }

DB_PW="$DB_PW" $PHP -r '
    $env = file_get_contents(".env");
    $value = "\"" . addcslashes(getenv("DB_PW"), "\\\"\$") . "\"";
    $env = preg_replace_callback("/^DB_PASSWORD=.*$/m", fn () => "DB_PASSWORD=" . $value, $env, 1, $count);
    if (! $count) { $env .= PHP_EOL . "DB_PASSWORD=" . $value . PHP_EOL; }
    file_put_contents(".env", $env);
'
unset DB_PW
$PHP artisan config:clear -q

if $PHP artisan tinker --execute 'DB::connection()->getPdo(); echo "OK";' 2>/dev/null | grep -q OK; then
    echo "✓ Contraseña guardada y conexión a MySQL correcta."
else
    echo "✗ No se pudo conectar a MySQL. Revise la contraseña y vuelva a ejecutar este script."
    exit 1
fi
