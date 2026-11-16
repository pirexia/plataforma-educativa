#!/usr/bin/env bash
# Arnés de pruebas de block-protected-branch.sh (ADR-080 §4, CS-01). Uso: .claude/hooks/test-block-protected-branch.sh
# Crea repos git temporales, alimenta el JSON del hook por stdin y comprueba el código de salida (2 = bloqueado, 0 = permitido).
# Sale con código distinto de 0 si algún caso falla.
set -u
hook="$(cd "$(dirname "$0")" && pwd)/block-protected-branch.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

repo="$tmp/repo"
git init -q -b develop "$repo"
git -C "$repo" -c user.name=t -c user.email=t@example.com commit -q --allow-empty -m base
sha="$(git -C "$repo" rev-parse HEAD)"
short="${sha:0:7}"

# Un repo por estado de HEAD (el hook lee la rama del directorio `cwd`).
make_repo() { # nombre rama|DETACHED
  local d="$tmp/$1"
  git clone -q "$repo" "$d" 2>/dev/null
  if [ "$2" = DETACHED ]; then git -C "$d" checkout -q --detach "$sha"; else git -C "$d" checkout -q -B "$2" "$sha"; fi
  echo "$d"
}
d_develop="$(make_repo r_develop develop)"
d_main="$(make_repo r_main main)"
d_staging="$(make_repo r_staging staging)"
d_work="$(make_repo r_work chore/trabajo)"
d_det="$(make_repo r_det DETACHED)"

fail=0
total=0
# caso <esperado: block|allow> <repo> <comando> <descripción>
caso() {
  local esperado="$1" dir="$2" cmd="$3" desc="$4" rc
  total=$((total + 1))
  jq -n --arg c "$cmd" --arg d "$dir" '{tool_input:{command:$c},cwd:$d}' | "$hook" >/dev/null 2>&1
  rc=$?
  if { [ "$esperado" = block ] && [ "$rc" -eq 2 ]; } || { [ "$esperado" = allow ] && [ "$rc" -eq 0 ]; }; then
    echo "ok    [$esperado] $desc"
  else
    echo "FALLA [$esperado, rc=$rc] $desc :: $cmd"
    fail=$((fail + 1))
  fi
}

# --- commit ---
caso block "$d_develop" 'git commit -m x' 'commit en develop'
caso block "$d_main" 'git commit -m x' 'commit en main'
caso block "$d_staging" 'git commit -m x' 'commit en staging'
caso block "$d_staging" 'git add . && git commit -m x' 'commit encadenado en staging'
caso allow "$d_work" 'git commit -m x' 'commit en rama de trabajo'
caso allow "$d_det" 'git commit -m x' 'commit con HEAD desacoplado'
caso allow "$d_staging" 'git checkout -b chore/x && git commit -m x' 'commit tras crear rama desde staging'

# --- push forzado ---
for r in develop main staging; do
  caso block "$d_work" "git push --force origin $r" "push --force a $r"
  caso block "$d_work" "git push -f origin $r" "push -f a $r"
  caso block "$d_work" "git push origin +$r" "push con refspec +$r"
  caso block "$d_work" "git push origin +HEAD:$r" "push con refspec +HEAD:$r"
  caso block "$d_work" "git push --force-with-lease origin $r" "push --force-with-lease a $r"
  caso block "$d_work" "git push --force-with-lease=$r:$short origin $sha:refs/heads/$r" "push --force-with-lease=ref a $r"
  caso block "$d_det" "git push -f origin $sha:refs/heads/$r" "push -f con forma sha:refs/heads/$r desde HEAD desacoplado"
  caso block "$d_det" "git push origin +$sha:refs/heads/$r" "push +sha:refs/heads/$r desde HEAD desacoplado"
done
caso block "$d_work" 'git push -fu origin staging' 'push -fu (cluster con f) a staging'

# --- destino sin la forma permitida ---
for r in develop main staging; do
  caso block "$d_work" "git push origin $r" "push de la rama $r"
  caso block "$d_work" "git push origin HEAD:$r" "push HEAD:$r"
  caso block "$d_work" "git push origin chore/x:$r" "push chore/x:$r"
  caso block "$d_work" "git push origin :$r" "borrado remoto de $r"
done
caso block "$d_det" "git push origin ${short}:refs/heads/main" 'sha corto:refs/heads/main (no son 40 hex)'
caso block "$d_det" "git push origin $sha:refs/heads/develop" '40hex:refs/heads/develop (develop nunca por promoción)'
caso block "$d_det" "git push origin $sha:main" '40hex:main sin refs/heads'

# --- promoción por la forma permitida ---
for r in staging main; do
  caso block "$d_develop" "git push origin $sha:refs/heads/$r" "40hex:refs/heads/$r con HEAD en develop"
  caso block "$d_main" "git push origin $sha:refs/heads/$r" "40hex:refs/heads/$r con HEAD en main"
  caso block "$d_staging" "git push origin $sha:refs/heads/$r" "40hex:refs/heads/$r con HEAD en staging"
  caso allow "$d_det" "git push origin $sha:refs/heads/$r" "40hex:refs/heads/$r desde HEAD desacoplado"
  caso allow "$d_work" "git push origin $sha:refs/heads/$r" "40hex:refs/heads/$r desde rama de trabajo"
done
caso allow "$d_det" "git push origin $sha:refs/heads/staging && git push origin $sha:refs/heads/main" 'dos promociones encadenadas desde HEAD desacoplado'
caso block "$d_det" "git push origin $sha:refs/heads/staging && git push -f origin main" 'promoción válida seguida de push -f a main'

# --- comportamiento previo ---
caso block "$d_develop" 'git push origin chore/x' 'push con HEAD en develop'
caso block "$d_main" 'git push' 'push con HEAD en main'
caso block "$d_staging" 'git push' 'push con HEAD en staging'
caso allow "$d_work" 'git push -u origin chore/trabajo' 'push de rama de trabajo'
caso allow "$d_work" 'git push origin v0.1.0' 'push de etiqueta desde rama de trabajo'
caso allow "$d_develop" 'git checkout -b chore/x && git push -u origin chore/x' 'push tras crear rama desde develop'
caso block "$d_work" "git -C $d_work push origin develop" 'git -C push a develop'

# --- no git / texto dentro de mensajes ---
caso allow "$d_develop" 'ls -la' 'comando no git'
caso allow "$d_develop" 'git status' 'git status en develop'
caso allow "$d_develop" 'echo "git commit -m x"' 'git commit dentro de un echo'
caso allow "$d_develop" 'gh pr create --body "git push origin main"' 'git push dentro del cuerpo de un PR'
caso allow "$d_work" 'git log origin/staging..HEAD' 'git log sobre staging'

echo "---"
echo "$((total - fail))/$total casos correctos"
[ "$fail" -eq 0 ] || exit 1
