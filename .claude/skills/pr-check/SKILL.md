---
name: pr-check
description: Lista de comprobación previa a abrir un PR (rama, commits, suite completa, linters, documentación, ficheros sensibles). Úsala justo antes de `gh pr create`, con el trabajo commiteado.
disable-model-invocation: true
---

# Comprobación previa al PR

Las reglas viven en `CLAUDE.md` (`§3` cuándo se ejecuta la suite, `§4` Git, `§6` documentación, `§10` definición de terminado): esta skill las ordena en una pasada y **no las sustituye**. Si algo aquí contradice a `CLAUDE.md`, manda `CLAUDE.md`.

Argumento opcional: el alcance del PR (`api`, `web`, `docs`); si no se da, se deduce del diff.

## 1. Rama y estado

1. `git branch --show-current`: **no** `develop` ni `main`; nombre `feature/REQ-XXX-…`, `fix/…`, `chore/…` o `docs/…`.
2. `git status --short`: sin cambios sin commitear. Si los hay, para y dilo.
3. `git fetch -q && git log --oneline origin/develop..HEAD`: lista de commits del PR.

## 2. Commits y ficheros

1. Cada commit sigue `tipo(ámbito): descripción [REQ-XXX-NNN]` y cita un REQ o un issue.
2. El último commit del lote lleva `Verificado: X/X …` con alcance `(suite completa)` o `(ficheros afectados: …)`, con el número real.
3. `git diff --name-only origin/develop...HEAD`: ninguno de estos: `.env*`, claves o certificados (`*.pem`, `*.key`), volcados o copias de base de datos, `node_modules/`, `vendor/`, logs, ficheros con datos reales de alumnos, familias o personal. Si hay, **para y avisa** (`CLAUDE.md §4`).
4. Revisa `.gitignore` por si falta algo de lo que este PR genera.

## 3. Verificación (delegar en `verificador`, Haiku)

No ejecutes la suite en la sesión principal: lánzala con el agente `verificador` y pega su resultado literal. Alcance según el diff:

| El diff toca | Se ejecuta |
|---|---|
| `apps/api` | `podman exec -w /var/www/html plataforma-api composer test` (**suite completa, una vez**; `CLAUDE.md §3`), `composer lint`, `composer analyse` |
| `apps/web` | `npm run lint`, `npm run lint:i18n`, `npx vue-tsc -b`, `npm test` |
| Un flujo de usuario de extremo a extremo | además, el E2E real (`npm run test:e2e:real[:prl]`), también por `verificador` |
| Solo `docs/`, `*.md`, `.claude/` | nada de lo anterior; basta la sección 4 |

Reglas: antes de la suite, `podman start plataforma-api`; nunca dos `composer test` a la vez, ni con revisores ejecutando Pest. Un resultado parcial **no** se declara verde. Si no se ejecutó algo, el PR lo dice.

## 4. Documentación y estado

1. `CHANGELOG.md`: una entrada por paso (`CLAUDE.md §6.7`).
2. Documentación del módulo en `docs/modulos/REQ-XXX/` al día; si hay endpoints, OpenAPI.
3. Textos visibles en los cuatro idiomas (`INV-009`); `lint:i18n` limpio.
4. `memory.md`: actualizado **en la última rama del paso, antes de mezclar**, no en un PR aparte.
5. Revisiones del paso hechas según `CLAUDE.md §2` punto 3 (skill `revision-con-codex`; `doc-precheck` antes de `doc-reviewer`).

## 5. Resultado

Devuelve una tabla corta `✅ / ❌ / ⏭ no aplica` por sección, con el motivo de cada ❌. **Si hay algún ❌, no abras el PR** salvo orden expresa del usuario.

Si todo está en verde, redacta el cuerpo del PR con: qué cambia, **qué se verificó (comando y resultado) y qué no**, issues enlazados y, al final, las líneas de atribución que pida el sistema. Después, `gh pr create --base develop`, y para esperar el CI y mezclar: skill `espera-ci`.
