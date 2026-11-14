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
HEAD=$(gh pr view "$N" --json headRefOid -q .headRefOid)
# Runs de Actions del último commit del PR que no han terminado (en cola o ejecutándose).
runs_pendientes() { gh run list --commit "$HEAD" --json status -q '[.[] | select(.status != "completed")] | length'; }
for i in $(seq 1 90); do
  OUT=$(gh pr checks "$N" 2>&1)
  if ! echo "$OUT" | grep -q -E "pending|queued|in_progress" && [ "$(runs_pendientes)" = "0" ]; then
    # Nada pendiente. Si tampoco hay checks, da unos ciclos de margen por si aún no se han registrado.
    if echo "$OUT" | grep -q "no checks reported" && [ "$i" -lt 4 ]; then sleep 20; continue; fi
    break
  fi
  sleep 20
done
OUT=$(gh pr checks "$N" 2>&1)
echo "$OUT" | awk -F'\t' '{print $2}' | sort | uniq -c
if echo "$OUT" | grep -q "no checks reported" && [ "$(runs_pendientes)" = "0" ]; then
  # SIN CI (sin checks y sin ningún run en cola ni ejecutándose): no es lo mismo que CI en verde. Solo se mezcla un PR cuyo diff es únicamente documentación
  # o configuración de proceso (.md, docs/, .claude/), y se dice en voz alta.
  if gh pr diff "$N" --name-only | grep -q -v -E '\.md$|^docs/|^\.claude/'; then
    echo "SIN CHECKS en un PR que toca algo más que documentación: NO se mezcla; averigua por qué no corre CI"
  else
    echo "SIN CHECKS (PR solo de documentación o .claude/): se mezcla SIN CI; díselo al usuario"
    merge
  fi
elif echo "$OUT" | grep -q -E "fail|cancel"; then
  echo "FALLO"; echo "$OUT" | grep -E "fail|cancel"
elif echo "$OUT" | grep -q -E "pending|queued|in_progress" || [ "$(runs_pendientes)" != "0" ]; then
  echo "PENDIENTE: tiempo agotado (runs en cola o en ejecución), no se mezcla; volver a esperar"
else
  merge
fi
```

Máximo ~30 min. Solo mezcla si hay checks y ninguno está `pending` ni `fail`, o si el PR no tiene checks **ni ningún run en cola o ejecutándose** y solo toca documentación o `.claude/` (en ese caso se avisa de que se mezcla **sin CI**).

**Runs en cola ≠ sin checks.** `gh pr checks` dice «no checks reported» mientras los runs siguen en cola y aún no han registrado sus checks (ocurrió con el PR #615: los cuatro workflows estaban en `queued` y se mezcló antes de que arrancaran). Por eso la espera consulta también `gh run list --commit <sha>`: con algún run sin terminar, el PR no se trata nunca como «sin CI». Si la cola de Actions tarda más de ~30 min, el resultado es `PENDIENTE` y se vuelve a esperar; no se mezcla.

**Checkouts y worktrees.** La skill nunca hace `git checkout develop` ni `git pull`: el checkout puede tener trabajo en curso o ser el de otra rama (y `gh pr merge --delete-branch` falla al borrar la rama local si otro *worktree* la usa). Por eso borra la rama remota con `git push origin --delete` y actualiza `develop` con `git fetch origin develop:develop`. La rama local y el *worktree*, si los hay, los limpia quien los creó (`git worktree remove`, `git branch -D`).

## Al terminar

- **Mezclado**: confirma `git log --oneline -1 develop` y `gh pr view N --json state -q .state` = `MERGED`; borra la rama local si queda y ningún *worktree* la usa (`git worktree list`; `git branch -d`). Si el PR se mezcló **sin CI** (`SIN CHECKS`), dilo explícitamente en el informe: no cuenta como «CI en verde».
- **Sin checks en un PR que toca código o CI**: no mezcles. Un PR de código sin checks suele indicar un filtro de rutas, un workflow roto o checks aún sin registrar; averígualo (`gh run list --branch <rama>`) antes de decidir.
- **Fallo**: no mezcles. Muestra el check que falla y las últimas líneas: `gh run view <run-id> --log-failed | tail -40` (el id sale de la URL del check). Diagnostica con la skill `depuracion` antes de tocar nada. **No** uses `--admin`, `--no-verify`, ni relances en bucle hasta que pase: un test intermitente se documenta (issue) y se avisa.
- `skipping` (p. ej. «Purgar imágenes antiguas de develop») es normal en un PR; no cuenta como fallo.
- Si el clasificador de permisos bloquea `gh pr merge`, no lo esquives: pide al usuario que lo autorice.
