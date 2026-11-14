#!/usr/bin/env bash
# PostToolUse(Edit|Write): formatea el fichero recién editado para que Pint/ESLint no fallen luego en el PR.
#  - apps/api/**/*.php  → Pint dentro del contenedor `plataforma-api` (el host no tiene PHP, CLAUDE.md §9).
#  - apps/web/**/*.{vue,ts,js,mjs} → `eslint --fix` en el host. Lo que no se pueda arreglar sale por stderr (exit 2).
# Nunca falla por entorno: sin contenedor o sin node_modules no hace nada. Ignora worktrees de agentes
# (su checkout no es el que monta el contenedor).
set -u

input="$(cat)"
file="$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty')"
[ -n "$file" ] && [ -f "$file" ] || exit 0

root="${CLAUDE_PROJECT_DIR:-$(git -C "$(dirname "$file")" rev-parse --show-toplevel 2>/dev/null)}"
[ -n "$root" ] || exit 0
case "$file" in
  "$root"/.claude/worktrees/*) exit 0 ;;
  "$root"/apps/api/vendor/*|"$root"/apps/web/node_modules/*) exit 0 ;;
esac

case "$file" in
  "$root"/apps/api/*.php)
    rel="${file#"$root"/apps/api/}"
    podman ps -q -f name='^plataforma-api$' 2>/dev/null | grep -q . || exit 0
    out="$(podman exec -w /var/www/html plataforma-api vendor/bin/pint --quiet "$rel" 2>&1)"
    rc=$?
    if [ $rc -ne 0 ]; then
      { echo "Pint no pudo formatear $rel:"; echo "$out" | tail -15; } >&2
      exit 2
    fi
    ;;
  "$root"/apps/web/*.vue|"$root"/apps/web/*.ts|"$root"/apps/web/*.js|"$root"/apps/web/*.mjs)
    [ -d "$root/apps/web/node_modules" ] || exit 0
    cd "$root/apps/web" || exit 0
    rel="${file#"$root"/apps/web/}"
    out="$(timeout 80 npx --no-install eslint --fix "$rel" 2>&1)"
    rc=$?
    if [ $rc -ne 0 ] && [ $rc -ne 124 ]; then
      { echo "ESLint no pudo arreglar todo en $rel (corrige a mano):"; echo "$out" | tail -25; } >&2
      exit 2
    fi
    ;;
esac

exit 0
