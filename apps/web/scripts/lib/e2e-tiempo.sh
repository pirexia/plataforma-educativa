#!/usr/bin/env bash
# Se carga con `source` desde los scripts de E2E real (docs/proceso/e2e-real-ci.md §5.2, ADR-091 §2.6).
#
# `e2e_tiempo_emitir <suite> <fase> <inicio>`: emite una línea parseable `E2E_TIEMPO suite=<suite> fase=<setup|playwright|teardown> segundos=<n>`
# (segundos desde `inicio`, un valor de `$SECONDS`). La línea va a stdout y, si `E2E_TIEMPOS_LOG` está definida (el CI la fija), se
# añade también a ese fichero para convertirla en el resumen y en `e2e-tiempos.json`. Sin esa variable, solo la línea.
e2e_tiempo_emitir() {
  local linea="E2E_TIEMPO suite=${1} fase=${2} segundos=$((SECONDS - ${3}))"
  echo "$linea"
  if [[ -n "${E2E_TIEMPOS_LOG:-}" ]]; then
    echo "$linea" >> "$E2E_TIEMPOS_LOG" || true
  fi
}

# `e2e_tiempo_medir <suite> <fase> <orden...>`: ejecuta la orden, emite su línea y devuelve su código de salida sin alterarlo.
# Solo para órdenes externas (Playwright) o funciones con su propio manejo de errores: una función llamada así corre dentro de
# un contexto `||` y `set -e` no actúa dentro de ella. Para `prepare_tenant`/`prepare` (que dependen de `set -e`) se usa
# `inicio=$SECONDS; <función>; e2e_tiempo_emitir <suite> <fase> "$inicio"`.
e2e_tiempo_medir() {
  local suite="$1" fase="$2" inicio="$SECONDS" codigo=0
  shift 2
  "$@" || codigo=$?
  e2e_tiempo_emitir "$suite" "$fase" "$inicio"
  return "$codigo"
}
