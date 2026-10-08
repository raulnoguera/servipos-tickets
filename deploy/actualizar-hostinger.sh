#!/usr/bin/env bash
# Publica la versión actual en Hostinger: empaqueta, sube, extrae sobre la
# instalación existente (sin tocar .env ni storage), migra y verifica.
# Requiere deploy/hostinger.conf (ver hostinger.conf.example) y acceso SSH con llave.
set -euo pipefail
cd "$(dirname "$0")/.."
source deploy/hostinger.conf
KEY="${SSH_KEY/#\~/$HOME}"
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
    php artisan down --retry=15 >/dev/null || true
    unzip -qo ~/servipos-deploy/$(basename "$ZIP")
    chmod +x deploy/*.sh
    chmod 600 .env
    rm -f 'u863678701@5.183.10.122' .env.example
    php artisan optimize:clear -q
    php artisan migrate --force
    php artisan config:cache -q && php artisan route:cache -q && php artisan view:cache -q
    php artisan up
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
