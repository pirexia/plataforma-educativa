#!/usr/bin/env bash
# Se carga con `source` desde los scripts de E2E real (docs/proceso/e2e-real-ci.md §3 punto 5 y §12.1.2 paso 7, #737, E2E-CI-c).
#
# `e2e_playwright <clave> <argumentos de playwright test...>`: ejecuta `npx playwright test` y devuelve su código de salida.
# Si `E2E_INFORMES_DIR` está definida (la fija el CI), cada invocación escribe su evidencia con el nombre de su clave, de modo
# que ninguna suite pisa a otra: informe JSON en `$E2E_INFORMES_DIR/<clave>.json` (se verifica por separado con
# `e2e-real-verificar.mjs <json> <clave>`), informe HTML en `$E2E_INFORMES_DIR/playwright-report/<clave>/` y trazas y capturas
# en `$E2E_INFORMES_DIR/test-results/<clave>/`. Sin esa variable no cambia nada respecto a antes (local: `playwright-report` y
# `test-results` por defecto, y `PLAYWRIGHT_JSON_OUTPUT_NAME` si el llamador la fijó).
# La clave es también la entrada de `e2e/e2e-real-esperados.json`: solo letras, dígitos y guion.
e2e_playwright() {
  local clave="$1"
  shift
  if [[ ! "$clave" =~ ^[a-z0-9][a-z0-9-]*$ ]]; then
    echo "e2e_playwright: clave de suite no válida: «${clave}»." >&2
    return 2
  fi
  if [[ -z "${E2E_INFORMES_DIR:-}" ]]; then
    npx playwright test "$@"
    return $?
  fi
  mkdir -p "$E2E_INFORMES_DIR" || return 2
  PLAYWRIGHT_JSON_OUTPUT_NAME="$E2E_INFORMES_DIR/${clave}.json" \
    PLAYWRIGHT_HTML_OUTPUT_DIR="$E2E_INFORMES_DIR/playwright-report/${clave}" \
    npx playwright test --output="$E2E_INFORMES_DIR/test-results/${clave}" "$@"
}
