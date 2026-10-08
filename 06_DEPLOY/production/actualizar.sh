#!/usr/bin/env bash
# 06_DEPLOY/production/actualizar.sh - Actualiza SGS en el servidor de producción (Linux).
# Uso (en el servidor, dentro de la carpeta del sistema):  bash 06_DEPLOY/production/actualizar.sh
#
# Orden seguro:
#   1. Respaldo de la base de datos (si algo falla, se puede volver atrás).
#   2. Descarga del código nuevo desde Git (solo avanza si no hay cambios locales en el servidor).
#   3. Migraciones pendientes de 04_DATABASE/09_MIGRATIONS (cambios de estructura; los datos se conservan).
#   4. Comprobación de salud. Si falla, indica cómo volver a la versión anterior.
set -euo pipefail
cd "$(dirname "$0")/../.."

VERSION_ANTERIOR=$(cat VERSION)
COMMIT_ANTERIOR=$(git rev-parse --short HEAD)
echo "== SGS: actualizando desde v${VERSION_ANTERIOR} (${COMMIT_ANTERIOR})"

echo "== 1/4 Respaldo de la base de datos"
php 07_SCRIPTS/respaldar_bd.php "antes-de-actualizar"

echo "== 2/4 Descargando código"
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "ERROR: hay archivos modificados directamente en el servidor. Revíselos con 'git status' antes de actualizar."
  exit 1
fi
git pull --ff-only

echo "== 3/4 Migraciones de base de datos"
php 07_SCRIPTS/migrar.php

echo "== 4/4 Comprobación"
URL="${SGS_HEALTH_URL:-http://localhost/api/health.php}"
if curl -fsS "$URL" | grep -q '"database":true'; then
  echo "OK: SGS v$(cat VERSION) en línea ($(git rev-parse --short HEAD))."
else
  echo "ATENCIÓN: la comprobación de salud falló."
  echo "Para volver al código anterior:   git reset --hard ${COMMIT_ANTERIOR}"
  echo "Para restaurar la base de datos:  ver 01_DOCUMENTACION/08_DESPLIEGUE/PUBLICAR_Y_ACTUALIZAR.md (Volver atrás)"
  exit 1
fi
