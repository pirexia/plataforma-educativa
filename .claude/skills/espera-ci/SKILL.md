---
name: espera-ci
description: Espera a que termine el CI de un PR y lo mezcla (squash, borrando la rama) solo si todos los checks pasan (un PR sin checks solo se mezcla si es de documentación o `.claude/`, y se avisa de que fue sin CI). Úsala SIEMPRE justo después de `gh pr create` o `gh pr checks` cuando haya que esperar el CI y mezclar (la sesión orquestadora, o `janitor`); no para PR de `develop` a `main`. Si algo falla, no mezcla y enseña el fallo.
---

# Esperar el CI y mezclar

Argumento: número de PR. Sin argumento, el de la rama actual: `gh pr view --json number -q .number`.

**Por qué es una skill y no un hook**: un hook reacciona a un evento de herramienta y debe acabar rápido; esperar el CI son minutos y mezclar es una decisión (no se mezcla si algo falla). Lo lento va en segundo plano, y la sesión recibe el aviso al terminar.

## Antes de esperar

1. Comprueba que el PR no es de `develop` ni de `main` y que el trabajo del paso está completo: si cierra un paso del plan, `memory.md` ya va **dentro** de este PR (`CLAUDE.md §3`; no hay PR aparte de memoria).
2. Un PR de `develop` a `main` (release) no se mezcla con esta skill.

## Esperar

Lanza **una sola** orden con `run_in_background: true` (nunca `sleep` en primer plano ni cadenas de `sleep`: el sistema las bloquea), y no sondees su fichero de salida: la sesión se despierta cuando termina.

```bash
N=<pr>
merge() {
  gh pr merge "$N" --squash 2>&1 | tail -2
  B=$(gh pr view "$N" --json headRefName -q .headRefName)
  git push -q origin --delete "$B" 2>&1 | tail -1
  git fetch -q origin develop:develop 2>&1 | tail -1   # actualiza develop sin cambiar de rama el checkout
  gh pr view "$N" --json state,mergeCommit -q '.state + " " + .mergeCommit.oid'
}
for i in $(seq 1 90); do
  gh pr checks "$N" 2>&1 | grep -q -E "pending|queued|in_progress" || break
  sleep 20
done
OUT=$(gh pr checks "$N" 2>&1)
# «no checks reported» puede ser un PR sin CI o checks que aún no se han registrado: reintenta antes de concluir.
for i in 1 2 3; do
  echo "$OUT" | grep -q "no checks reported" || break
  sleep 20; OUT=$(gh pr checks "$N" 2>&1)
done
echo "$OUT" | awk -F'\t' '{print $2}' | sort | uniq -c
if echo "$OUT" | grep -q "no checks reported"; then
  # SIN CI: no es lo mismo que CI en verde. Solo se mezcla un PR cuyo diff es únicamente documentación
  # o configuración de proceso (.md, docs/, .claude/), y se dice en voz alta.
  if gh pr diff "$N" --name-only | grep -q -v -E '\.md$|^docs/|^\.claude/'; then
    echo "SIN CHECKS en un PR que toca algo más que documentación: NO se mezcla; averigua por qué no corre CI"
  else
    echo "SIN CHECKS (PR solo de documentación o .claude/): se mezcla SIN CI; díselo al usuario"
    merge
  fi
elif echo "$OUT" | grep -q -E "fail|cancel"; then
  echo "FALLO"; echo "$OUT" | grep -E "fail|cancel"
elif echo "$OUT" | grep -q -E "pending|queued|in_progress"; then
  echo "PENDIENTE: tiempo agotado, no se mezcla; volver a esperar"
else
  merge
fi
```

Máximo ~30 min. Solo mezcla si hay checks y ninguno está `pending` ni `fail`, o si el PR no tiene checks y solo toca documentación o `.claude/` (en ese caso se avisa de que se mezcla **sin CI**).

**Checkouts y worktrees.** La skill nunca hace `git checkout develop` ni `git pull`: el checkout puede tener trabajo en curso o ser el de otra rama (y `gh pr merge --delete-branch` falla al borrar la rama local si otro *worktree* la usa). Por eso borra la rama remota con `git push origin --delete` y actualiza `develop` con `git fetch origin develop:develop`. La rama local y el *worktree*, si los hay, los limpia quien los creó (`git worktree remove`, `git branch -D`).

## Al terminar

- **Mezclado**: confirma `git log --oneline -1 develop` y `gh pr view N --json state -q .state` = `MERGED`; borra la rama local si queda y ningún *worktree* la usa (`git worktree list`; `git branch -d`). Si el PR se mezcló **sin CI** (`SIN CHECKS`), dilo explícitamente en el informe: no cuenta como «CI en verde».
- **Sin checks en un PR que toca código o CI**: no mezcles. Un PR de código sin checks suele indicar un filtro de rutas, un workflow roto o checks aún sin registrar; averígualo (`gh run list --branch <rama>`) antes de decidir.
- **Fallo**: no mezcles. Muestra el check que falla y las últimas líneas: `gh run view <run-id> --log-failed | tail -40` (el id sale de la URL del check). Diagnostica con la skill `depuracion` antes de tocar nada. **No** uses `--admin`, `--no-verify`, ni relances en bucle hasta que pase: un test intermitente se documenta (issue) y se avisa.
- `skipping` (p. ej. «Purgar imágenes antiguas de develop») es normal en un PR; no cuenta como fallo.
- Si el clasificador de permisos bloquea `gh pr merge`, no lo esquives: pide al usuario que lo autorice.
