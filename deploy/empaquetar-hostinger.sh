#!/usr/bin/env bash
# Genera dist/servipos-tickets-AAAAMMDD-HHMM.zip listo para subir a Hostinger:
# compila la interfaz, instala dependencias de producción y agrega el .htaccess raíz.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
STAMP="$(date +%Y%m%d-%H%M)"
STAGE="$(mktemp -d)/servipos-tickets"
mkdir -p "$STAGE" "$ROOT/dist"

echo "→ Compilando interfaz…"
npm run build >/dev/null

echo "→ Copiando archivos…"
rsync -a \
    --exclude '.git' --exclude 'node_modules' --exclude 'vendor' --exclude 'tests' --exclude 'dist' \
    --exclude '.env' --exclude '.env.backup' --exclude '.claude' --exclude 'public/hot' \
    --exclude 'database/*.sqlite*' --exclude 'storage/logs/*.log' \
    --exclude 'storage/app/private/*' --exclude 'storage/framework/sessions/*' \
    --exclude 'storage/framework/views/*' --exclude 'storage/framework/cache/data/*' \
    "$ROOT/" "$STAGE/"
mkdir -p "$STAGE/storage/app/private"
cp "$ROOT/storage/app/private/.gitignore" "$STAGE/storage/app/private/" 2>/dev/null || true
cp "$ROOT/deploy/htaccess-raiz" "$STAGE/.htaccess"

echo "→ Instalando dependencias de producción…"
(cd "$STAGE" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)

echo "→ Empaquetando…"
ZIP="$ROOT/dist/servipos-tickets-$STAMP.zip"
(cd "$STAGE" && zip -qr "$ZIP" . -x '*.DS_Store')
rm -rf "$(dirname "$STAGE")"
echo "✓ Listo: $ZIP ($(du -h "$ZIP" | cut -f1))"
