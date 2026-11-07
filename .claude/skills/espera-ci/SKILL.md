---
name: espera-ci
description: Espera a que termine el CI de un PR y lo mezcla (squash, borrando la rama) solo si todos los checks pasan. Úsala tras `gh pr create`. Si algo falla, no mezcla y enseña el fallo.
disable-model-invocation: true
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
for i in $(seq 1 90); do
  gh pr checks "$N" 2>&1 | grep -q -E "pending|queued|in_progress" || break
  sleep 20
done
gh pr checks "$N" 2>&1 | awk -F'\t' '{print $2}' | sort | uniq -c
if gh pr checks "$N" 2>&1 | grep -q -E "fail|cancel"; then
  echo "FALLO"; gh pr checks "$N" | grep -E "fail|cancel"
elif gh pr checks "$N" 2>&1 | grep -q -E "pending|queued|in_progress"; then
  echo "PENDIENTE: tiempo agotado, no se mezcla; volver a esperar"
else
  gh pr merge "$N" --squash --delete-branch 2>&1 | tail -2
  git checkout -q develop && git pull -q && git log --oneline -1
fi
```

Máximo ~30 min. Solo mezcla si no queda ningún check `pending` ni `fail`.

## Al terminar

- **Mezclado**: confirma `git log --oneline -1` en `develop` y `gh pr view N --json state -q .state` = `MERGED`; borra la rama local si queda (`git branch -d`).
- **Fallo**: no mezcles. Muestra el check que falla y las últimas líneas: `gh run view <run-id> --log-failed | tail -40` (el id sale de la URL del check). Diagnostica con la skill `depuracion` antes de tocar nada. **No** uses `--admin`, `--no-verify`, ni relances en bucle hasta que pase: un test intermitente se documenta (issue) y se avisa.
- `skipping` (p. ej. «Purgar imágenes antiguas de develop») es normal en un PR; no cuenta como fallo.
- Si el clasificador de permisos bloquea `gh pr merge`, no lo esquives: pide al usuario que lo autorice.
