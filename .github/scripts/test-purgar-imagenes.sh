#!/usr/bin/env bash
# Prueba de purgar-imagenes.sh en modo --dry-run con un JSON de ejemplo (ADR-080 §4.5). Sin red ni borrados.
# Sale con código distinto de 0 si algún caso falla. Uso: .github/scripts/test-purgar-imagenes.sh
set -u
dir="$(cd "$(dirname "$0")" && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# 14 versiones `sha-` recientes (ids 101..114, 114 la más reciente), y debajo, más antiguas:
#   id 50  v0.1.0 (semver) · id 51  sha-aaaaaaa (staging) · id 52  sha-bbbbbbb (main) · id 53 sha-ccccccc (sin protección)
#   id 54  etiqueta móvil `develop` antigua · id 55 sin etiqueta, junto a v0.1.0 (hijo) · id 56 sin etiqueta, aislada
jq -n '
  ([range(101; 115)] | map({id: ., created_at: "2026-11-\(. - 90)T10:00:00Z", metadata: {container: {tags: ["sha-0000\(.)"]}}}))
  + [
    {id: 50, created_at: "2026-10-01T10:00:00Z", metadata: {container: {tags: ["v0.1.0"]}}},
    {id: 51, created_at: "2026-10-02T10:00:00Z", metadata: {container: {tags: ["sha-aaaaaaa"]}}},
    {id: 52, created_at: "2026-10-03T10:00:00Z", metadata: {container: {tags: ["sha-bbbbbbb"]}}},
    {id: 53, created_at: "2026-10-04T10:00:00Z", metadata: {container: {tags: ["sha-ccccccc"]}}},
    {id: 54, created_at: "2026-10-05T10:00:00Z", metadata: {container: {tags: ["develop"]}}},
    {id: 55, created_at: "2026-10-01T10:00:20Z", metadata: {container: {tags: []}}},
    {id: 56, created_at: "2026-10-06T10:00:00Z", metadata: {container: {tags: []}}}
  ]' >"$tmp/versiones.json"

salida="$("$dir/purgar-imagenes.sh" --dry-run --fichero "$tmp/versiones.json" --proteger sha-aaaaaaa --proteger sha-bbbbbbb)"
echo "$salida"
echo "---"

fail=0
total=0
espera() { # <CONSERVAR|BORRAR> <id> <descripción>
  total=$((total + 1))
  if grep -Eq "^$1 $2 " <<<"$salida"; then echo "ok    [$1 $2] $3"; else echo "FALLA [$1 $2] $3"; fail=$((fail + 1)); fi
}
for id in 105 110 114; do espera CONSERVAR "$id" 'una de las 10 más recientes'; done
espera BORRAR 101 'más antigua que las 10 recientes y sin protección'
espera BORRAR 104 'undécima por antigüedad'
espera CONSERVAR 50 'semver sobrevive con más de 10 versiones posteriores'
espera CONSERVAR 51 'sha- de staging sobrevive con más de 10 versiones posteriores'
espera CONSERVAR 52 'sha- de main sobrevive con más de 10 versiones posteriores'
espera BORRAR 53 'sha- sin protección se borra'
espera BORRAR 54 'la etiqueta móvil develop antigua se borra'
espera CONSERVAR 55 'manifiesto sin etiqueta junto a una imagen semver'
espera BORRAR 56 'sin etiqueta y sin imagen conservada cerca'

total=$((total + 1))
if [ "$(grep -c '^CONSERVAR' <<<"$salida")" -eq 14 ]; then echo "ok    14 conservadas (10 recientes, semver, 2 protegidas y 1 hijo)"; else echo "FALLA recuento de conservadas"; fail=$((fail + 1)); fi

# Con --dry-run no hay llamadas a la API: sin --dry-run y sin --ruta debe negarse.
total=$((total + 1))
if "$dir/purgar-imagenes.sh" --fichero "$tmp/versiones.json" >/dev/null 2>&1; then echo "FALLA: sin --dry-run ni --ruta debería fallar"; fail=$((fail + 1)); else echo "ok    sin --dry-run ni --ruta se niega"; fi

echo "$((total - fail))/$total casos correctos"
[ "$fail" -eq 0 ] || exit 1
