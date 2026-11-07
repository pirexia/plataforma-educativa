#!/usr/bin/env bash
# PreToolUse(Bash): CLAUDE.md §4 — nunca commit ni push directo a `develop` o `main`.
# Bloquea (exit 2) `git commit` / `git push` si la rama actual es protegida o si el push apunta a una de ellas.
# Se permite el commit si el mismo comando crea antes una rama (`checkout -b`, `switch -c`).
set -u

input="$(cat)"
command="$(printf '%s' "$input" | jq -r '.tool_input.command // empty')"
cwd="$(printf '%s' "$input" | jq -r '.cwd // empty')"
[ -n "$command" ] || exit 0

segment='(^|&&|;|\|\||\|)[[:space:]]*git[[:space:]]+(-C[[:space:]]+[^[:space:]]+[[:space:]]+)?'
# Línea a línea: solo cuenta `git commit|push` al inicio de un segmento (no texto dentro de un mensaje o cuerpo de PR).
is_commit=0
is_push=0
while IFS= read -r line; do
  printf '%s' "$line" | grep -Eq "${segment}commit([[:space:]]|$)" && is_commit=1
  printf '%s' "$line" | grep -Eq "${segment}push([[:space:]]|$)" && is_push=1
done <<<"$command"
[ "$is_commit" -eq 1 ] || [ "$is_push" -eq 1 ] || exit 0

# Directorio de trabajo efectivo: `cd X &&` inicial o `git -C X`.
dir="$cwd"
if [[ "$command" =~ ^[[:space:]]*cd[[:space:]]+([^[:space:]\&\;]+) ]]; then dir="${BASH_REMATCH[1]}"; fi
if [[ "$command" =~ git[[:space:]]+-C[[:space:]]+([^[:space:]]+) ]]; then dir="${BASH_REMATCH[1]}"; fi
[ -n "$dir" ] || dir="."

branch="$(git -C "$dir" symbolic-ref --short HEAD 2>/dev/null || true)"

block() {
  {
    echo "BLOQUEADO por .claude/hooks/block-protected-branch.sh (CLAUDE.md §4): $1"
    echo "Crea una rama (feature/…, fix/…, chore/…, docs/…) desde develop, commitea ahí y abre un PR."
  } >&2
  exit 2
}

if [ "$is_commit" -eq 1 ]; then
  if grep -Eq '(checkout|switch)[[:space:]]+(-[a-zA-Z]+[[:space:]]+)*-[bBcC][[:space:]]' <<<"$command"; then
    : # el comando crea la rama antes de commitear
  elif [ "$branch" = "develop" ] || [ "$branch" = "main" ]; then
    block "commit directo en '$branch'."
  fi
fi

if [ "$is_push" -eq 1 ]; then
  if grep -Eq "git[[:space:]]+(-C[[:space:]]+[^[:space:]]+[[:space:]]+)?push([[:space:]]+[^;&|]*)?[[:space:]:](develop|main)([[:space:]]|\$|;|&|\|)" <<<"$command"; then
    block "push con destino 'develop' o 'main'."
  fi
  if grep -Eq '(checkout|switch)[[:space:]]+(-[a-zA-Z]+[[:space:]]+)*-[bBcC][[:space:]]' <<<"$command"; then
    :
  elif [ "$branch" = "develop" ] || [ "$branch" = "main" ]; then
    block "push desde la rama '$branch'."
  fi
fi

exit 0
