#!/usr/bin/env bash
# Publica la versión actual en un servidor: empaqueta, sube, extrae sobre la instalación
# existente (sin tocar .env ni storage), migra y verifica.
# Uso: ./deploy/publicar.sh deploy/<servidor>.conf   (ver hostinger.conf.example y siteground.conf.example)
# Requiere acceso SSH con llave.
set -euo pipefail
cd "$(dirname "$0")/.."
CONF="${1:?Indique el archivo de configuración, por ejemplo: ./deploy/publicar.sh deploy/hostinger.conf}"
[ -f "$CONF" ] || { echo "No existe $CONF"; exit 1; }
source "$CONF"
KEY="${SSH_KEY/#\~/$HOME}"
PHP="${PHP_BIN:-php}"   # en SiteGround el php por defecto de la consola es anterior: PHP_BIN=php83
remote() { ssh -i "$KEY" -p "$SSH_PORT" -o BatchMode=yes "$SSH_USER@$SSH_HOST" "$@"; }

if [ -n "$(git status --porcelain)" ]; then
    echo "Hay cambios sin guardar en git. Haga commit antes de publicar."; exit 1
fi

./deploy/empaquetar-hostinger.sh
ZIP="$(ls -t dist/*.zip | head -1)"
echo "→ Subiendo $(basename "$ZIP")…"
remote 'mkdir -p ~/servipos-deploy'
scp -q -i "$KEY" -P "$SSH_PORT" -o BatchMode=yes "$ZIP" "$SSH_USER@$SSH_HOST:servipos-deploy/"

echo "→ Instalando en el servidor…"
remote "set -e; cd ~/$APP_DIR
    $PHP artisan down --retry=15 >/dev/null || true
    unzip -qo ~/servipos-deploy/$(basename "$ZIP")
    chmod +x deploy/*.sh
    chmod 600 .env
    rm -f 'u863678701@5.183.10.122' .env.example
    # Reglas propias del servidor (versión de PHP, forzar HTTPS): van antes de las de la app.
    if [ -f ../htaccess-servidor ]; then cat ../htaccess-servidor public/.htaccess > public/.htaccess.nuevo && mv public/.htaccess.nuevo public/.htaccess; fi
    $PHP artisan optimize:clear -q
    $PHP artisan migrate --force
    $PHP artisan config:cache -q && $PHP artisan route:cache -q && $PHP artisan view:cache -q
    $PHP artisan up
    ls -t ~/servipos-deploy/*.zip | tail -n +4 | xargs -r rm -f"

echo "→ Verificando…"
for path in / /sw.js /api/v1/auth/me; do
    printf '   %-18s %s\n' "$path" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$SITE_URL$path")"
done
# Nada interno debe poder descargarse: si alguna de estas rutas devuelve el archivo real, se avisa.
exposed=0
while IFS='|' read -r path marker; do
    if curl -s --max-time 20 "$SITE_URL$path" | grep -q "$marker"; then
        echo "   ✗ EXPUESTO: $path"; exposed=1
    fi
done <<'CHECKS'
/.env|APP_KEY=
/composer.json|"require"
/composer.lock|"content-hash"
/vendor/autoload.php|ComposerAutoloader
/storage/logs/laravel.log|production\.
/config/app.php|<?php
/artisan|<?php
/README.md|# ServiPos
CHECKS
[ "$exposed" -eq 0 ] && echo "   archivos internos    protegidos" || { echo "✗ Hay archivos internos accesibles por web. Revise el .htaccess de la raíz."; exit 1; }
echo "✓ Publicado: $SITE_URL ($(git log --oneline -1))"
