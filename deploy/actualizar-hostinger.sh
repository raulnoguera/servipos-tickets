#!/usr/bin/env bash
# Publica en el sitio de pruebas de Hostinger. Requiere deploy/hostinger.conf (ver hostinger.conf.example).
exec "$(dirname "$0")/publicar.sh" deploy/hostinger.conf
