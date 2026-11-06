---
name: asignacion-de-modelos
description: Qué modelo (Opus, Sonnet, Haiku) y qué agente usar para cada tarea del proyecto. Úsala siempre que vayas a delegar en un subagente, elegir o cambiar el modelo de un agente, crear un agente nuevo, o decidir si una tarea puede pasar a un modelo más barato.
---

# Asignación de modelos y agentes

Plan **Pro con límite de 5 horas**: el gasto de cuota es una restricción de diseño (`CLAUDE.md §2`, «Ahorro de cuota»). Esta skill es la fuente de la asignación; las definiciones de `.claude/agents/*.md` son la fuente de lo que cada agente *hace* y *no puede hacer*. Si una contradice a la otra, es un fallo de documentación: corrígelo en el mismo PR.

## Reglas que no dependen de esta skill (siguen también en `CLAUDE.md §2`)

1. Sesión principal en **Sonnet** (`.claude/settings.json`). Opus se activa a mano (`/model opus`) solo para especificación, arquitectura y planificación, y se vuelve a Sonnet al terminar.
2. Los agentes declaran el modelo con alias (`haiku`, `sonnet`, `opus`), **nunca con un ID de versión**.
3. `security-reviewer`, `db-reviewer` y `doc-reviewer` **nunca** se rebajan a Haiku.
4. Un subagente no lanza subagentes: quien encadena `implementer` → `traductor` → `verificador` → revisores es la sesión orquestadora.

## Tarea → modelo

| Tarea | Modelo |
|-------|--------|
| Especificaciones, arquitectura, planificación, decisiones de diseño, revisión crítica | **Opus** |
| Implementación, refactor, tests, revisión de código y de seguridad, coherencia de documentación, depuración, `memory.md` | **Sonnet** |
| Tareas mecánicas: búsquedas e inventarios, ejecución de tests, linters y E2E real con informe literal, correcciones textuales de documentación con hallazgo cerrado, briefing de tramo previo a `implementer`, traducción de claves de interfaz no legales, trámites de Git y GitHub (issues ya clasificados, PR, borrado de ramas, entrada de `CHANGELOG.md`), comprobaciones de existencia en documentación, formateo, renombrados | **Haiku** |

## Agente → modelo

| Agente | Modelo | Para qué |
|--------|--------|----------|
| `spec-writer`, `architect` | Opus | Especificación y ADR |
| `implementer`, `test-writer` | Sonnet | Código y tests (`implementer` escribe y depura los *specs* de E2E, no ejecuta la pasada final) |
| `security-reviewer`, `db-reviewer`, `doc-reviewer` | Sonnet | Revisiones obligatorias |
| `explorer` | Haiku | Búsquedas, inventarios, historial de Git y de GitHub, briefing de tramo (solo lectura) |
| `verificador` | Haiku | Ejecuta suite, linters y E2E real y devuelve el resultado literal; no corrige ni diagnostica |
| `traductor` | Haiku | Completa en/de/fr a partir de es-ES; nunca textos legales ni de consentimiento |
| `doc-precheck` | Haiku | Pasada mecánica previa a `doc-reviewer`; no lo sustituye en la primera ronda (sí en la segunda, `CLAUDE.md §2` «Ahorro de cuota», punto 8) |
| `janitor` | Haiku | `.gitignore`, formateo, commits rutinarios, issues ya clasificados, PR, ramas, `CHANGELOG.md`, correcciones textuales de documentación con hallazgo cerrado |

## Subagentes que heredan el modelo de la sesión

`fork`, `general-purpose` y `Plan` corren con el modelo de la sesión que los lanza. Para buscar usa `explorer`, no `general-purpose`; para tests o linters, `verificador`. Un `fork` solo si el modelo de la sesión es el adecuado: en una sesión Opus, la ejecución va a un agente con nombre aunque se pierda la caché. No uses Opus en subagentes de ejecución ni para picar código.

## Cómo decidir una tarea nueva (o si una puede bajar de modelo)

- **Haiku** solo si el resultado se **verifica mecánicamente o se copia literal** (salida de una orden, una lista, una sustitución con texto exacto) y no exige juzgar código ni contrastar con una especificación. Si su error pasaría desapercibido hasta producción, no es Haiku.
- **Sonnet** si hay que juzgar o escribir código, tests, revisiones o diagnosticar un fallo.
- **Opus** si la decisión es cara de revertir (modelo de datos núcleo, dependencia nueva, ADR, partición de entregas). Separa siempre la **recopilación** (Haiku/Sonnet) de la **decisión** (Opus).
- **Nunca Haiku**: código de negocio, tests que son la red de seguridad (`INV-015`), aislamiento de tenant, permisos, auditoría, migraciones y textos legales o de consentimiento.
- Una ejecución corta cuesta 50-100 k tokens solo de arranque (`docs/metricas-agentes.md`): no partas un agente en varios si cada trozo es pequeño.
- Para cambiar el modelo de un agente: edita su frontmatter **y** esta skill en el mismo commit, y anota en `docs/metricas-agentes.md` qué se espera ahorrar; con <5 observaciones las cifras son orientativas.
