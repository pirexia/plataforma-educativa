#!/usr/bin/env bash
# PreToolUse(Bash): CLAUDE.md §4 y ADR-080 §4 — nunca commit ni push directo a `develop`, `staging` o `main`.
# Bloquea (exit 2):
#   - `git commit` si la rama efectiva es protegida (develop, staging, main);
#   - `git push` desde una rama protegida;
#   - `git push` con destino develop (en cualquier forma);
#   - `git push` con destino staging o main salvo en la única forma permitida, `<sha de 40 hex>:refs/heads/<rama>`
#     (promoción por avance rápido, ADR-080 §4.3), que además solo se admite con HEAD desacoplado o en una rama de trabajo;
#   - `git push` forzado (`--force`, `-f`, `--force-with-lease`, `--force-if-includes`, refspec con `+`) con destino protegido;
#   - `git push --mirror`, `git push --all` y cualquier destino con comodín (`*`) o expansión (`$`, comilla invertida),
#     porque su destino real no se puede conocer al analizar el texto.
# Rama efectiva: la actual del directorio de trabajo, salvo que un segmento anterior del mismo comando cambie de rama
# (`git checkout|switch <rama>`, `checkout -b`, `switch -c`).
# Límites conocidos (RUNBOOK.md §3c): no cubre `merge`, `reset`, `cherry-pick`, `rebase`, `tag -f`, `branch -f`, ni
# `bash -c`, `env`, `xargs`, alias o `$(…)`, ni remotos distintos del analizado, ni un push manual del propietario.
set -u

input="$(cat)"
command="$(printf '%s' "$input" | jq -r '.tool_input.command // empty')"
cwd="$(printf '%s' "$input" | jq -r '.cwd // empty')"
[ -n "$command" ] || exit 0

# Une las continuaciones de línea (barra invertida + salto de línea) antes de segmentar.
bs=$'\\\n'
command="${command//"$bs"/ }"

protected='develop|staging|main'

block() {
  {
    echo "BLOQUEADO por .claude/hooks/block-protected-branch.sh (CLAUDE.md §4, ADR-080 §4): $1"
    echo "Crea una rama (feature/…, fix/…, chore/…, docs/…) desde develop, commitea ahí y abre un PR."
    echo "Promoción a staging/main: solo 'git push origin <sha de 40 hex>:refs/heads/<rama>' sin forzar, desde HEAD desacoplado."
  } >&2
  exit 2
}

# Directorio de trabajo inicial: `cd X &&` inicial o el cwd.
basedir="$cwd"
if [[ "$command" =~ ^[[:space:]]*cd[[:space:]]+([^[:space:]\&\;]+) ]]; then basedir="${BASH_REMATCH[1]}"; fi
[ -n "$basedir" ] || basedir="."

# Segmentos: se separa por ; && || | ( ) y por llaves de grupo, un segmento por línea.
segments="$(printf '%s' "$command" | sed -E 's/&&|\|\||[;|()]/\n/g; s/(^|[[:space:]])\{[[:space:]]/\n/g; s/[[:space:]]\}($|[[:space:]])/\n/g')"

gitre='^git[[:space:]]+(((-c|-C|--git-dir|--work-tree|--namespace)[[:space:]]+[^[:space:]]+|--[A-Za-z-]+(=[^[:space:]]+)?|-[pP])[[:space:]]+)*(commit|push|checkout|switch)([[:space:]]|$)'

override=""   # "", "work" o "protected": rama efectiva fijada por un checkout/switch anterior del mismo comando

while IFS= read -r seg; do
  seg="${seg#"${seg%%[![:space:]]*}"}"
  while [[ "$seg" =~ ^(then|do|else|if|while|until|!|time)[[:space:]]+(.*)$ ]]; do seg="${BASH_REMATCH[2]}"; done
  [[ "$seg" =~ $gitre ]] || continue
  matched="${BASH_REMATCH[0]}"
  [[ "$matched" =~ (commit|push|checkout|switch)[[:space:]]*$ ]] || continue
  sub="${BASH_REMATCH[1]}"
  args="${seg:${#matched}}"

  segdir="$basedir"
  if [[ "$seg" =~ ^git[[:space:]]+.*-C[[:space:]]+([^[:space:]]+) ]]; then segdir="${BASH_REMATCH[1]}"; fi

  case "$sub" in
    checkout | switch)
      read -ra toks <<<"$args"
      crea=0; destino=""; doble_guion=0
      for t in "${toks[@]}"; do
        t="${t//[\"\']/}"
        case "$t" in
          --) doble_guion=1 ;;
          --orphan | --create | --force-create) crea=1 ;;
          -[a-zA-Z]*[bBcC]*) [[ "$t" =~ ^-[a-zA-Z]+$ ]] && crea=1 ;;
          -*) ;;
          *) [ -z "$destino" ] && destino="$t" ;;
        esac
      done
      if [ "$crea" -eq 1 ]; then
        override="work"
      elif [ "$doble_guion" -eq 0 ] && [ -n "$destino" ]; then
        if [[ "$destino" =~ ^(${protected})$ ]]; then override="protected"; else override="work"; fi
      fi
      ;;
    commit | push)
      if [ "$override" = "protected" ]; then
        branch="(rama protegida tras un checkout/switch del mismo comando)"; efectiva_protegida=1
      elif [ "$override" = "work" ]; then
        branch=""; efectiva_protegida=0
      else
        branch="$(git -C "$segdir" symbolic-ref --short HEAD 2>/dev/null || true)"
        if [[ "$branch" =~ ^(${protected})$ ]]; then efectiva_protegida=1; else efectiva_protegida=0; fi
      fi

      if [ "$sub" = "commit" ]; then
        [ "$efectiva_protegida" -eq 0 ] || block "commit directo en '$branch'."
        continue
      fi

      # push: destinos y forzados
      force=0
      destino_protegido=0
      protegido=""
      read -ra tokens <<<"$args"
      for tok in "${tokens[@]}"; do
        tok="${tok//[\"\']/}"
        case "$tok" in
          --mirror | --all) block "push con '$tok': su destino real no se puede comprobar." ;;
          --force | --force=* | --force-with-lease | --force-with-lease=* | --force-if-includes) force=1; continue ;;
          --*) continue ;;
          -*f*) [[ "$tok" =~ ^-[a-zA-Z]+$ ]] && force=1; continue ;;
          -*) continue ;;
        esac
        plus=0
        if [[ "$tok" == +* ]]; then plus=1; tok="${tok#+}"; fi
        dst="${tok##*:}"
        if [[ "$tok" == *'*'* || "$tok" == *'$'* || "$tok" == *'`'* ]]; then
          block "push con destino con comodín o expansión ('$tok'): no se puede comprobar."
        fi
        dst="${dst#refs/heads/}"
        if [[ "$dst" =~ ^(${protected})$ ]]; then
          [ "$plus" -eq 1 ] && force=1
          destino_protegido=1
          if [ "$dst" != "develop" ] && [[ "$tok" =~ ^[0-9a-f]{40}:refs/heads/${dst}$ ]]; then
            :  # forma de promoción permitida
          else
            protegido="$dst"
          fi
        fi
      done
      if [ -n "$protegido" ]; then
        [ "$protegido" != "develop" ] || block "push con destino 'develop'."
        block "push con destino '$protegido' fuera de la forma '<sha de 40 hex>:refs/heads/$protegido'."
      fi
      if [ "$force" -eq 1 ] && [ "$destino_protegido" -eq 1 ]; then
        block "push forzado (--force, -f, --force-with-lease o '+') con destino protegido."
      fi
      [ "$efectiva_protegida" -eq 0 ] || block "push desde la rama '$branch'."
      ;;
  esac
done <<<"$segments"

exit 0
