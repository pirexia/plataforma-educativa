---
name: revision-con-codex
description: Protocolo de uso del plugin Codex (openai/codex-plugin-cc) como segunda opinión sobre código ya implementado. Úsala después de que implementer termine un paso, con la suite en verde y el trabajo commiteado, antes o en paralelo a db-reviewer/security-reviewer/doc-reviewer — nunca en su lugar.
---

# Revisión con Codex

`ADR-049` decide esto. Esta skill es su protocolo de uso — la "interfaz propia" que `RNF-MANT-007` exige para una herramienta que no es una librería (`ADR-049 §6`). Si algo de aquí contradice el ADR, manda el ADR.

## Cuándo se invoca

Después de que `implementer` haya terminado, con la suite ejecutada y en verde y los cambios **commiteados** (`ADR-049 §7.1`). Nunca sobre trabajo sin commitear: la entrada de Codex tiene que ser un *diff* con nombre.

Antes o en paralelo a `db-reviewer`, `security-reviewer` y `doc-reviewer`. **Nunca en su lugar.** Los tres siguen siendo obligatorios en todos los casos en que hoy lo son (`CLAUDE.md §4`, `§6.2`, `§6.7`).

## Comandos permitidos, y solo estos

`/codex:review`, `/codex:adversarial-review`, `/codex:status`, `/codex:result`, `/codex:cancel`, `/codex:setup` (solo para comprobar instalación/estado).

**Prohibidos**: `/codex:rescue` y `/codex:transfer`. **No hay barrera técnica que los contenga si se invocan** (corrección verificada el 2026-09-14, `ADR-049 §5.1`): `rescue` añade `--write` por defecto y eso cambia el *sandbox* a `workspace-write` **por invocación**, sin que importe lo que diga `.codex/config.toml`/`~/.codex/config.toml`. La única protección real es no invocarlo — igual que un subagente sin *worktree* aislado. **Prohibida también la puerta de revisión automática** (`/codex:setup --enable-review-gate`): si alguna vez aparece armada, se desarma inmediatamente y se anota como incumplimiento de proceso (`ADR-049 §5.4`).

**Nunca invocar ningún comando de este plugin con `--help` ni con sintaxis exploratoria "a ver qué hace".** Verificado el 2026-09-14: el *parser* de `codex-companion.mjs` no distingue `--help` de texto de enfoque de revisión — pedir ayuda ejecuta una revisión real contra el estado del árbol, sin confirmación. Usa solo la sintaxis exacta de esta skill.

## Qué se le da

Un rango de *diff* con nombre, acotado al paso que se está cerrando (p. ej. `/codex:review --base develop` sobre la rama del paso, o el número de PR). **Nunca "el árbol entero a ver qué encuentra".**

## Qué no se le da nunca

`.env` (de cualquier nivel), `storage/`, volcados de base de datos, cualquier ruta bajo `secrets/`, certificados y claves (`*.pem`, `*.key`), y en general nada que la lista `deny` de `.claude/settings.json` proteja. Esa lista es, para Codex, el filtro de lo que sale de la máquina — no higiene local (`ADR-049 §5.3`): el *sandbox* de Codex restringe escritura y red, **nunca lectura**, así que si esos ficheros están en el árbol de trabajo, nada externo a nosotros impide que Codex los lea si decide explorar más allá del *diff*. La protección es que no haya ninguna credencial válida fuera de esta máquina en esos ficheros (auditado el 2026-09-14) y que el *sandbox* esté verificado como efectivo antes de cada uso (ver "Antes de la primera vez en una máquina nueva").

## Cómo se triaja el resultado (`ADR-049 §7.2`)

Un hallazgo de Codex es un **candidato**, nunca un veredicto. Contrástalo contra los requisitos, los ADR y la especificación del paso:

- **Aceptado** → entra por la tabla de severidad de `CLAUDE.md §5` exactamente igual que cualquier otro hallazgo, con su issue, su reproducción, sus ficheros y su propuesta. A partir de ahí es un issue del proyecto, no "un issue de Codex".
- **Rechazado** → se anota en una línea, con el motivo, en la nota de cierre del paso (en `memory.md`). Sin ese registro, el mismo hallazgo rechazado vuelve en cada ejecución.
- **Un hallazgo que contradiga un ADR vigente no es un hallazgo, es ruido.** Codex no ha leído ni un solo ADR de este proyecto. Se cierra citando el ADR, sin discutirlo. Ejemplos ya anticipados en `ADR-049 §7.2`: mover `ProvisionTenantDefaults` a `Eloquent*` (`ADR-048 §4.4` lo descartó), renombrar `affected_tenant_id` a `tenant_id` (`ADR-047` lo prohíbe), sustituir un contrato síncrono por un evento (`ADR-048 §3.1`), añadir exenciones a los tests de esquema (`ADR-047` lo prohíbe expresamente).

`CLAUDE.md §0` manda en los dos sentidos: tampoco se acepta un hallazgo solo porque venga de Codex.

## Evaluación de la prueba (`ADR-049 §8`)

Mientras la prueba esté abierta (calibrado sobre PR #204 + dos pasos siguientes), **cada ejecución se registra en `memory.md`**: hallazgos propuestos, aceptados, descartados y el motivo. Sin ese registro no hay con qué decidir si se mantiene o se revierte. El resultado por defecto, si no hay evaluación escrita al cerrar el segundo paso de prueba, es **revertir** (`ADR-049 §9`).

## Antes de la primera vez en una máquina nueva

Comprobar la configuración **efectiva**, no el fichero (`codex doctor`, o un `codex exec` de prueba pidiendo escribir un fichero y confirmando que se bloquea). El `.codex/config.toml` de proyecto puede no aplicarse por un *bug* conocido de `openai/codex` (issue #30001) — la protección real puede depender de la réplica en `~/.codex/config.toml` (nivel de usuario, fuera del repositorio). Verificarlo, no suponerlo.

## Cláusula de caducidad

En el momento en que exista un dato personal real en esta máquina o en este repositorio (cierre de `OPEN-11`, llegada del centro piloto, cualquier exportación), la prueba termina y el plugin se desinstala, con independencia de cómo esté saliendo (`ADR-049 §5.6`). Los datos de `REQ-SEED-005` son ficticios y no cuentan.
