#!/usr/bin/env bash
# Retención de imágenes de GHCR (ADR-037 §5.1, ADR-080 §4.5). Lo usa el job `prune-old-images` de build-images.yml.
#
# Entrada: JSON de versiones de un paquete de contenedor (la lista de `gh api …/packages/container/<paquete>/versions`,
# como un único array) por stdin o con --fichero. Cada versión trae `id`, `created_at` y `metadata.container.tags`.
#
# CONSERVA una versión si cumple alguna de estas reglas:
#   1. es una de las N más recientes por `created_at` (N = --conservar, 10 por defecto);
#   2. alguna de sus etiquetas es una versión semántica (`^v?X.Y.Z$`);
#   3. alguna de sus etiquetas está en --proteger (p. ej. `sha-<7>` de los commits de staging y main);
#   4. no tiene etiquetas y se creó a menos de --margen segundos (120 por defecto) de CUALQUIER versión conservada por 1, 2 o 3:
#      son los manifiestos hijos (plataforma, atestaciones) del índice etiquetado, que se suben unos segundos ANTES que él, así que
#      pueden quedar justo fuera de las N más recientes aunque su índice esté dentro; borrarlos rompería la imagen conservada.
#      Heurística por tiempo: no se ha verificado contra la API de GHCR (la API no expone la relación padre-hijo).
# Borra el resto.
#
# Uso:
#   purgar-imagenes.sh --dry-run [--fichero versiones.json] [--conservar 10] [--proteger sha-abc1234]... [--margen 120]
#   purgar-imagenes.sh --ruta /users/<dueño>/packages/container/<paquete> [mismas opciones]   # borra de verdad con `gh api -X DELETE`
# Salida: una línea por versión, `CONSERVAR <id> <etiquetas> (<motivo>)` o `BORRAR <id> <etiquetas>`.
set -euo pipefail

conservar=10
margen=120
dry=0
fichero=""
ruta=""
protegidas=()

while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run) dry=1 ;;
    --fichero) fichero="$2"; shift ;;
    --conservar) conservar="$2"; shift ;;
    --margen) margen="$2"; shift ;;
    --proteger) protegidas+=("$2"); shift ;;
    --ruta) ruta="$2"; shift ;;
    *) echo "Opción desconocida: $1" >&2; exit 64 ;;
  esac
  shift
done

if [ "$dry" -eq 0 ] && [ -z "$ruta" ]; then
  echo "Sin --dry-run hace falta --ruta (p. ej. /users/<dueño>/packages/container/<paquete>)" >&2
  exit 64
fi

if [ -n "$fichero" ]; then entrada="$(cat "$fichero")"; else entrada="$(cat)"; fi

lista_protegidas="$(printf '%s\n' "${protegidas[@]+"${protegidas[@]}"}" | jq -R . | jq -s .)"

decisiones="$(printf '%s' "$entrada" | jq -r \
  --argjson n "$conservar" --argjson margen "$margen" --argjson prot "$lista_protegidas" '
  def etiquetas: (.metadata.container.tags // []);
  def f: (.created_at | fromdateiso8601);
  (sort_by(.created_at) | reverse | to_entries
    | map(.value + {pos: .key})) as $v
  # Motivo directo (reglas 1 a 3)
  | ($v | map(. + {motivo:
      (if .pos < $n then "reciente"
       elif (etiquetas | any(test("^v?[0-9]+\\.[0-9]+\\.[0-9]+$"))) then "semver"
       elif (etiquetas | any(. as $t | $prot | index($t))) then "protegida"
       else null end)})) as $a
  | ($a | map(select(.motivo != null) | f)) as $anclas
  | $a[]
  | . as $x
  | (if $x.motivo != null then $x.motivo
     elif (($x | etiquetas | length) == 0) and ($anclas | any(. as $t | (($t - ($x | f)) | fabs) <= $margen)) then "hijo de una imagen conservada"
     else null end) as $m
  | "\(if $m == null then "BORRAR" else "CONSERVAR" end) \(.id) \((etiquetas | join(",")) | if . == "" then "-" else . end)\(if $m == null then "" else " (\($m))" end)"
')"

printf '%s\n' "$decisiones"

if [ "$dry" -eq 0 ]; then
  printf '%s\n' "$decisiones" | while read -r accion id _; do
    [ "$accion" = "BORRAR" ] || continue
    gh api -X DELETE "${ruta}/versions/${id}"
  done
fi
