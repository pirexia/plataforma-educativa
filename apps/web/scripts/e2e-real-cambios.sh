#!/usr/bin/env bash
# Regla de rutas del job `cambios` de `.github/workflows/ci-e2e-real.yml` (docs/proceso/e2e-real-ci.md §3, ADR-091 §2.3).
# Lee de la entrada estándar nombres de fichero separados por NUL (`git diff --name-only -z`) y escribe `true` si alguno obliga a
# ejecutar el E2E real y `false` si ninguno. Sin acción de terceros (CLAUDE.md §1).
#
# Incluidas: apps/web/**, apps/api/**, compose.yaml, infra/containers/**, infra/garage/**, infra/lib/**, `.env.example` de la raíz y el
# propio flujo. Excluidas a propósito (el job no las usa): infra/compose/compose.prodlike.yaml e infra/quadlet/**.
set -euo pipefail

ejecutar=false
while IFS= read -r -d '' fichero; do
  case "$fichero" in
    apps/web/* | apps/api/* | compose.yaml | infra/containers/* | infra/garage/* | infra/lib/* | .env.example | .github/workflows/ci-e2e-real.yml)
      ejecutar=true
      ;;
  esac
done
echo "$ejecutar"
