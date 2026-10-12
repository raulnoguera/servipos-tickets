#!/usr/bin/env bash
# Genera dist/servipos-tickets-AAAAMMDD-HHMM.zip listo para subir a Hostinger:
# compila la interfaz, instala dependencias de producción y agrega el .htaccess raíz.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
STAMP="$(date +%Y%m%d-%H%M)"
STAGE="$(mktemp -d)/servipos-tickets"
mkdir -p "$STAGE" "$ROOT/dist"

echo "→ Revisando vulnerabilidades conocidas en las dependencias…"
composer audit --locked --no-interaction >/dev/null || { echo "✗ composer audit encontró vulnerabilidades. Ejecute: composer audit"; exit 1; }
npm audit --omit=dev >/dev/null 2>&1 || { echo "✗ npm audit encontró vulnerabilidades. Ejecute: npm audit --omit=dev"; exit 1; }

echo "→ Compilando interfaz (dependencias exactas del archivo de bloqueo)…"
npm ci --silent
npm run build >/dev/null

echo "→ Copiando archivos…"
rsync -a \
    --exclude '.git' --exclude 'node_modules' --exclude 'vendor' --exclude 'tests' --exclude 'dist' \
    --exclude '.env' --exclude '.env.*' --exclude 'auth.json' --exclude '*.sql' --exclude '*.pem' --exclude '*.key' \
    --exclude '.claude' --exclude '.github' --exclude 'public/hot' --exclude 'storage/framework/testing' \
    --exclude 'deploy/*.conf' --exclude 'deploy/brand/logo-original.png' \
    --exclude 'docs' --exclude 'CLAUDE.md' --exclude 'AGENTS.md' --exclude 'boost.json' --exclude '.phpunit.result.cache' \
    --exclude 'database/*.sqlite*' --exclude 'storage/logs/*.log' \
    --exclude 'storage/app/private/*' --exclude 'storage/framework/sessions/*' \
    --exclude 'storage/framework/views/*' --exclude 'storage/framework/cache/data/*' \
    "$ROOT/" "$STAGE/"
mkdir -p "$STAGE/storage/app/private"
cp "$ROOT/storage/app/private/.gitignore" "$STAGE/storage/app/private/" 2>/dev/null || true
cp "$ROOT/deploy/htaccess-raiz" "$STAGE/.htaccess"
# Segunda barrera: ninguna carpeta interna se sirve por web aunque fallara la redirección de la raíz.
for dir in app bootstrap config database deploy lang resources routes storage vendor; do
    [ -d "$STAGE/$dir" ] && printf 'Require all denied\n' > "$STAGE/$dir/.htaccess"
done

echo "→ Instalando dependencias de producción…"
(cd "$STAGE" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
printf 'Require all denied\n' > "$STAGE/vendor/.htaccess"

echo "→ Empaquetando…"
ZIP="$ROOT/dist/servipos-tickets-$STAMP.zip"
(cd "$STAGE" && zip -qr "$ZIP" . -x '*.DS_Store')
rm -rf "$(dirname "$STAGE")"
echo "✓ Listo: $ZIP ($(du -h "$ZIP" | cut -f1))"
