#!/usr/bin/env bash
# Publica en producción (SiteGround, hosting del cliente). Requiere deploy/siteground.conf (ver siteground.conf.example).
exec "$(dirname "$0")/publicar.sh" deploy/siteground.conf
