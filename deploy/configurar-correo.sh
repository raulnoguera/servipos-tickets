#!/usr/bin/env bash
# Conecta el sistema al buzón donde los bancos reportan las fallas. Pide la
# contraseña sin mostrarla, la guarda en .env y prueba la conexión.
# Uso (en el servidor, dentro de la carpeta de la app): bash deploy/configurar-correo.sh
# En SiteGround: PHP_BIN=php83 bash deploy/configurar-correo.sh
#
# Desde que termina bien, el sistema lee el buzón cada dos minutos y abre un
# ticket por cada reporte nuevo. Los correos anteriores a este momento no se tocan.
set -euo pipefail
cd "$(dirname "$0")/.."
PHP="${PHP_BIN:-php}"
[ -f .env ] || { echo "No existe .env en $(pwd)"; exit 1; }
command -v "$PHP" >/dev/null || { echo "No se encontró $PHP en este equipo. Este script se ejecuta en el servidor, entrando por SSH."; exit 1; }

actual() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | tr -d '"' || true; }
preguntar() { # variable, texto, valor por defecto
    local previo; previo="$(actual "$1")"; local defecto="${previo:-$3}"; local valor
    read -rp "$2 [$defecto]: " valor
    printf '%s' "${valor:-$defecto}"
}

HOST="$(preguntar INBOUND_MAIL_HOST 'Servidor de correo entrante (IMAP)' 'gtxm1317.siteground.biz')"
MAIL_USER="$(preguntar INBOUND_MAIL_USERNAME 'Dirección del buzón' 'atmservipos2019@servipos2019.com')"
DOMAINS="$(preguntar INBOUND_MAIL_DOMAINS 'Dominios de los bancos, separados por coma' 'bncenlinea.net')"
read -rsp "Contraseña del buzón $MAIL_USER: " MAIL_PW; echo
[ -n "$MAIL_PW" ] || { echo "No se escribió ninguna contraseña."; exit 1; }
SINCE="$(actual INBOUND_MAIL_SINCE)"
[ -n "$SINCE" ] || SINCE="$($PHP artisan tinker --execute 'echo now()->format("Y-m-d H:i:s");' 2>/dev/null | tail -1)"

HOST="$HOST" MAIL_USER="$MAIL_USER" MAIL_PW="$MAIL_PW" DOMAINS="$DOMAINS" SINCE="$SINCE" $PHP -r '
    $env = file_get_contents(".env");
    $quote = fn (string $v) => "\"" . addcslashes($v, "\\\"\$") . "\"";
    $values = [
        "INBOUND_MAIL_HOST" => getenv("HOST"), "INBOUND_MAIL_PORT" => "993", "INBOUND_MAIL_ENCRYPTION" => "ssl",
        "INBOUND_MAIL_USERNAME" => getenv("MAIL_USER"), "INBOUND_MAIL_PASSWORD" => $quote(getenv("MAIL_PW")),
        "INBOUND_MAIL_DOMAINS" => getenv("DOMAINS"), "INBOUND_MAIL_SINCE" => $quote(getenv("SINCE")),
    ];
    foreach ($values as $key => $value) {
        $env = preg_replace_callback("/^" . $key . "=.*$/m", fn () => $key . "=" . $value, $env, 1, $count);
        if (! $count) { $env = rtrim($env) . PHP_EOL . $key . "=" . $value . PHP_EOL; }
    }
    file_put_contents(".env", $env);
'
unset MAIL_PW
$PHP artisan config:clear -q

if $PHP artisan servipos:leer-correo --probar; then
    echo "✓ Buzón conectado. Se procesan los correos recibidos desde $SINCE."
    echo "  Para ver qué haría con lo que hay ahora, sin guardar nada: $PHP artisan servipos:leer-correo --simular"
else
    echo "✗ No se pudo leer el buzón. Revise el servidor, la dirección y la contraseña, y vuelva a ejecutar este script."
    exit 1
fi
