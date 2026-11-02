# Restauración de la configuración de modelos y agentes del 08/10/2026

> Fichero de carga bajo demanda. Movido desde `CLAUDE.md §2` v2.8.0 (recorte de contexto fijo, 2026-11-02). `CLAUDE.md §2` conserva la referencia.

La configuración anterior a la redistribución de modelos (`CLAUDE.md`, `.claude/agents/`, `.claude/skills/`, `.claude/settings.json` y la tabla de agentes de `PLAN-IMPLEMENTACION.md`) está guardada en la etiqueta de Git **`config-claude-2026-10-08`** (commit `0ecd3a2`, publicada en el remoto). La etiqueta no se mueve ni se borra.

Si el usuario pide «volver a la configuración del 08/10/2026», en una rama `chore/` colgada de `develop`:

```bash
git fetch --tags
git rm -r -q .claude/agents .claude/skills          # quita también los agentes añadidos después
git checkout config-claude-2026-10-08 -- CLAUDE.md .claude/agents .claude/skills .claude/settings.json
```

`PLAN-IMPLEMENTACION.md` **no** se restaura entero, porque perdería el progreso del plan posterior a esa fecha: su tabla de agentes («Anexo A · Subagentes recomendados») se copia a mano desde `git show config-claude-2026-10-08:PLAN-IMPLEMENTACION.md`. Después, PR a `develop` como cualquier otro cambio.
