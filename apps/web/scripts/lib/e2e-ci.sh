#!/usr/bin/env bash
# Se carga con `source` desde los scripts de E2E real (docs/proceso/e2e-real-ci.md §12.1, ADR-091 §2.1).
#
# `e2e_enmascarar <valor...>`: en GitHub Actions (`GITHUB_ACTIONS=true`) emite `::add-mask::<valor>` para que el valor no aparezca
# en el registro del job. Fuera de Actions no hace nada: el comportamiento en local no cambia. Se usa con las contraseñas aleatorias
# que devuelve `e2e-real-tenant.php setup` (ADR-080 §3.6).
e2e_enmascarar() {
  [[ "${GITHUB_ACTIONS:-}" == "true" ]] || return 0
  local valor
  for valor in "$@"; do
    [[ -n "$valor" ]] && echo "::add-mask::${valor}"
  done
  return 0
}
