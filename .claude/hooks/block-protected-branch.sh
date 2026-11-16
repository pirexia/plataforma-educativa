#!/usr/bin/env bash
# PreToolUse(Bash): CLAUDE.md §4 y ADR-080 §4 — nunca commit ni push directo a `develop`, `staging` o `main`.
# Bloquea (exit 2):
#   - `git commit` si la rama actual es protegida (develop, staging, main);
#   - `git push` desde una rama protegida;
#   - `git push` con destino develop (en cualquier forma);
#   - `git push` con destino staging o main salvo en la única forma permitida, `<sha de 40 hex>:refs/heads/<rama>`
#     (promoción por avance rápido, ADR-080 §4.3), que además solo se admite con HEAD desacoplado o en una rama de trabajo;
#   - `git push` forzado (`--force`, `-f`, `--force-with-lease`, `--force-if-includes`, refspec con `+`) con destino protegido.
# Se permite el commit/push desde la rama actual si el mismo comando crea antes una rama (`checkout -b`, `switch -c`).
set -u

input="$(cat)"
command="$(printf '%s' "$input" | jq -r '.tool_input.command // empty')"
cwd="$(printf '%s' "$input" | jq -r '.cwd // empty')"
[ -n "$command" ] || exit 0

protected='develop|staging|main'
segment='(^|&&|;|\|\||\|)[[:space:]]*git[[:space:]]+(-C[[:space:]]+[^[:space:]]+[[:space:]]+)?'
# Línea a línea: solo cuenta `git commit|push` al inicio de un segmento (no texto dentro de un mensaje o cuerpo de PR).
is_commit=0
is_push=0
pushes=""
while IFS= read -r line; do
  printf '%s' "$line" | grep -Eq "${segment}commit([[:space:]]|$)" && is_commit=1
  if printf '%s' "$line" | grep -Eq "${segment}push([[:space:]]|$)"; then
    is_push=1
    # Cada segmento `git … push …` (hasta el siguiente separador), uno por línea.
    pushes+="$(printf '%s' "$line" | grep -Eo "${segment}push([[:space:]][^;&|]*)?" | sed -E "s/^.*[[:space:]]push//")"$'\n'
  fi
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
    echo "BLOQUEADO por .claude/hooks/block-protected-branch.sh (CLAUDE.md §4, ADR-080 §4): $1"
    echo "Crea una rama (feature/…, fix/…, chore/…, docs/…) desde develop, commitea ahí y abre un PR."
    echo "Promoción a staging/main: solo 'git push origin <sha de 40 hex>:refs/heads/<rama>' sin forzar, desde HEAD desacoplado."
  } >&2
  exit 2
}

crea_rama=0
if grep -Eq '(checkout|switch)[[:space:]]+(-[a-zA-Z]+[[:space:]]+)*-[bBcC][[:space:]]' <<<"$command"; then crea_rama=1; fi

if [ "$is_commit" -eq 1 ] && [ "$crea_rama" -eq 0 ]; then
  case "$branch" in
    develop | staging | main) block "commit directo en '$branch'." ;;
  esac
fi

if [ "$is_push" -eq 1 ]; then
  while IFS= read -r args; do
    [ -n "$args" ] || continue
    force=0
    destino_protegido=0
    protegido=""   # destino protegido que no cumple la forma permitida
    read -ra tokens <<<"$args"
    for tok in "${tokens[@]}"; do
      tok="${tok//[\"\']/}"
      case "$tok" in
        --force | --force=* | --force-with-lease | --force-with-lease=* | --force-if-includes) force=1; continue ;;
        --*) continue ;;
        -*f*) [[ "$tok" =~ ^-[a-zA-Z]+$ ]] && force=1; continue ;;
        -*) continue ;;
      esac
      # Refspec: [+]<origen>:<destino> o [+]<rama>. El destino es lo que hay tras el último ':'.
      plus=0
      [[ "$tok" == +* ]] && { plus=1; tok="${tok#+}"; }
      dst="${tok##*:}"
      dst="${dst#refs/heads/}"
      if [[ "$dst" =~ ^(${protected})$ ]]; then
        [ "$plus" -eq 1 ] && force=1
        if [[ "$dst" != "develop" ]] && [[ "$tok" =~ ^[0-9a-f]{40}:refs/heads/${dst}$ ]]; then
          :  # forma de promoción permitida
        else
          protegido="$dst"
        fi
        destino_protegido=1
      fi
    done
    if [ -n "$protegido" ]; then
      if [ "$protegido" = "develop" ]; then
        block "push con destino 'develop'."
      fi
      block "push con destino '$protegido' fuera de la forma '<sha de 40 hex>:refs/heads/$protegido'."
    fi
    if [ "$force" -eq 1 ] && [ "$destino_protegido" -eq 1 ]; then
      block "push forzado (--force, -f, --force-with-lease o '+') con destino protegido."
    fi
  done <<<"$pushes"

  if [ "$crea_rama" -eq 0 ]; then
    case "$branch" in
      develop | staging | main) block "push desde la rama '$branch'." ;;
    esac
  fi
fi

exit 0
