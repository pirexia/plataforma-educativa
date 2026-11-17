#!/usr/bin/env bash
# Runtime de contenedores de los guiones (ADR-080 §5.3, paso 0.10e-b). Se carga con `source`; no se ejecuta.
#
# `resolve_container_runtime` fija y exporta CONTAINER_RUNTIME:
#   orden de resolución  CONTAINER_RUNTIME -> CONTAINER_CLI (alias de respaldo, expand/contract) -> podman.
#   Lista cerrada: solo `podman` o `docker`; cualquier otro valor (también el que llegue por CONTAINER_CLI) devuelve 2
#   con un mensaje en stderr, para no ejecutar lo que venga en una variable de entorno.
#   Si ambas variables están definidas y difieren, devuelve 2.
# Uso:  source infra/lib/container-runtime.sh; resolve_container_runtime || exit 2;  "$CONTAINER_RUNTIME" exec ...
# Regla hacia delante (ADR-080 §5.3.5): ningún guion nuevo escribe `podman` a mano. Podman es el único runtime verificado;
# con `docker` el soporte es de mejor esfuerzo y sin verificar.
resolve_container_runtime() {
  local primary="${CONTAINER_RUNTIME:-}" alias_value="${CONTAINER_CLI:-}" value
  if [[ -n "$primary" && -n "$alias_value" && "$primary" != "$alias_value" ]]; then
    echo "CONTAINER_RUNTIME («${primary}») y CONTAINER_CLI («${alias_value}») difieren: define solo CONTAINER_RUNTIME." >&2
    return 2
  fi
  value="${primary:-${alias_value:-podman}}"
  case "$value" in
    podman|docker) ;;
    *)
      echo "CONTAINER_RUNTIME no válido: «${value}». Valores admitidos: podman, docker (por defecto podman)." >&2
      return 2
      ;;
  esac
  CONTAINER_RUNTIME="$value"
  export CONTAINER_RUNTIME
}
