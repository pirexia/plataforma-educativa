---
name: revision-con-codex
description: Protocolo de revisión con Codex (openai/codex-plugin-cc) como primer revisor del pipeline (ADR-069), con reparto de la lista de comprobación, clasificación crítico/ordinario y régimen de respaldo de ADR-050. Úsala después de que implementer termine un paso, con la suite en verde y el trabajo commiteado.
---

# Revisión con Codex

`ADR-069` decide el régimen; `ADR-049` y `ADR-050` siguen vigentes en lo que `ADR-069` no sustituye. Esta skill es su protocolo (la «interfaz propia» de `RNF-MANT-007`, `ADR-049 §6`). Si algo de aquí contradice un ADR, manda el ADR.

**Estado de OPEN-069-01: confirmada (2026-11-02)** — por declaración del usuario (desactivó el entrenamiento con sus datos en su cuenta de ChatGPT/OpenAI); la sesión no puede verificarlo. Régimen de `ADR-069` ACTIVO.

> Interruptor. La sesión lo cambia a `confirmada (AAAA-MM-DD)` **solo** cuando el usuario confirme que el entrenamiento con sus datos está desactivado en su cuenta de OpenAI (o que usa una capa de pago). Mientras diga `pendiente`: **no se lanza Codex sobre código del proyecto y rige el régimen de ADR-050** (sección «Régimen de respaldo» de abajo): Codex opcional, `security-reviewer` completo, segunda ronda como antes. Al cambiarlo, se actualiza también el párrafo de `CLAUDE.md §2` si hiciera falta.

## Régimen de `ADR-069` (solo con OPEN-069-01 confirmada)

### Cuándo y cómo se lanza

- En **todo paso que cambie código** (`apps/`, `infra/`), Codex revisa **primero**: después de `implementer`, con la suite en verde y el trabajo **commiteado** (`ADR-049 §7.1`).
- Lo lanza **la sesión orquestadora por `Bash`**, llamando a `codex-companion.mjs` (en `~/.claude/plugins/cache/openai-codex/codex/<versión>/scripts/`) **solo** con los subcomandos `review` y `adversarial-review` (`OPEN-069-03`, 2026-11-02). `task`, `transfer`, `task-worker` y `rescue` están prohibidos y denegados en `.claude/settings.json`. Las reglas `allow` de `review`, `adversarial-review`, `status` y `result` llevan la **versión del plugin fijada** (`codex/1.0.6/`, issue #555): al actualizar el plugin hay que actualizarla en `.claude/settings.json`, o la sesión volverá a pedir aprobación en cada revisión.
- Revisión principal: `adversarial-review --base develop` con, como texto de enfoque, el contenido de `contexto-codex.md` más la ruta de la especificación y de los ADR del paso, y las decisiones aprobadas que no se reabren. `review` (no admite instrucciones propias) es solo pasada complementaria.
- Nunca `--help` ni sintaxis exploratoria: el parser lo toma como enfoque y lanza una revisión real. Nunca sobre trabajo sin commitear.

### Qué se le da y qué no

Rango de diff con nombre del paso (`--base develop`). Lo que sale de la máquina lo filtra la lista `deny` de `.claude/settings.json` (`ADR-049 §5.3`); el sandbox de Codex limita escritura, no lectura. **Datos reales: nunca** (`ADR-030`); los ficheros de prueba del árbol no pueden contener imágenes de personas reales, y antes de lanzar se comprueba que los fixtures sin commitear (p. ej. `apps/web/e2e/fixtures/`) no las tienen.

### Reparto de la lista de comprobación (`ADR-069 §4.2`)

| Codex | `security-reviewer` (modo acotado) |
|---|---|
| Inyección y SQL concatenado (punto 3) | Tenant en el framework **y** RLS; `tenant_id` del cliente (1) |
| Validación de entrada, `500` en vez de `422`, límites de paginación | Permiso, recurso y **ámbito** (`ADR-044`), denegar por defecto (2) |
| Carreras, bloqueos de fila, idempotencia, `after_commit`, fallos a medias | IDOR con `public_id` frente al `bigint` (4) |
| Secretos en código o logs (6) | Ficheros: tipo real, almacenamiento privado, URL firmada, prefijo de tenant (5) |
| Operaciones destructivas sin confirmación, parte técnica (8) | Categoría especial, cifrado y auditoría de lectura (7) |
| Regresiones del diff y calidad de los tests; segunda lectura de migraciones | Sesión, cookie y CSRF (9, 10); auditoría `INV-003`/`ADR-035` (11); respeto de los ADR del paso |

Que Codex no encuentre nada **no prueba nada** en la columna derecha. El ámbito de `security-reviewer` lo fija esa columna, no lo que Codex diga haber cubierto.

### Clasificación del paso

`spec-writer` marca en la especificación `Clasificación del paso: crítico|ordinario`; la sesión la comprueba contra el diff. **Crítico** si el diff toca: migración que crea una tabla o cambia RLS, `GRANT` o un `CHECK` de auditoría; *endpoint*, *policy*, permiso, resolutor de ámbito o *middleware* de autorización, nuevo o modificado; autenticación o sesión; ficheros; datos de categoría especial; un *endpoint* que lee o escribe datos personales de menores o de terceros; dinero; tablas de plataforma o que cruzan tenants. **En caso de duda, crítico.**

- **Crítico**: Codex y después `security-reviewer` con **solo la columna derecha**, sobre el diff, con las decisiones aprobadas y los hallazgos de Codex ya clasificados (no los repite ni los reabre). Si ve un hallazgo de la columna izquierda que Codex no señaló, lo comunica (dato de medición).
- **Ordinario**: solo Codex. `security-reviewer` únicamente si Codex trae un Crítico o un Alto aceptado, o si Codex no está disponible.
- **`db-reviewer` en toda migración, también en pasos ordinarios**, recibiendo los hallazgos de Codex. Codex nunca lo sustituye.
- **`doc-precheck` y `doc-reviewer`** sin cambios; la segunda ronda de documentación la hace `doc-precheck` (`OPEN-069-05`).

### Segunda ronda

Las correcciones de Medios y Bajos las verifica **Codex** (rango de commits de la corrección, lista de hallazgos como enfoque). El revisor de Claude solo vuelve a entrar si se corrigió un Crítico o un Alto, y solo para esos hallazgos. También son de Codex la revisión de los tests del diff y la búsqueda de regresiones.

### Autoridad y registro

Los hallazgos de Codex son candidatos sin autoridad de bloqueo; se tría como en «Cómo se triaja» (abajo). Codex sigue fuera de `CLAUDE.md §10`; la obligación viene del proceso (`ADR-069 §4.1`). Cada paso añade su fila a la tabla «Codex» de `docs/metricas-agentes.md` (`ADR-069 §5`). **Codex no implementa**: `rescue` prohibido (`ADR-069 §4.6`).

### Si Codex no está disponible

Cuota de OpenAI agotada, servicio caído o retirada por `ADR-050 §5.3`: en ese mismo paso se vuelve al «Régimen de respaldo» (`ADR-069 §6`): `security-reviewer` con la lista completa (sus puntos 1-11 no se han recortado) y ronda 2 como antes. Se anota en la fila de métricas que Codex no estuvo disponible.

### Retirada

Las tres condiciones de `ADR-050 §5.3` **siguen vigentes sin cambios** (ver «Retirada» abajo). Además, `ADR-069 §5`: un escape Crítico o Alto de la columna izquierda en un paso ordinario suspende el régimen ordinario en el acto (todos los pasos pasan a críticos) y se avisa al usuario en la misma sesión; revisión al 6.º paso o el 2027-01-31. Abiertas: `OPEN-069-02` (`AGENTS.md`; de momento solo el enfoque) y `OPEN-069-06` (H0).

## Régimen de respaldo: `ADR-050` (vigente mientras OPEN-069-01 esté pendiente o Codex no esté disponible)

Codex es **opcional**: no invocarlo no es incumplimiento ni entra en `CLAUDE.md §10`. Si el usuario decide usarlo igualmente, solo con los comandos y límites de abajo y teniendo en cuenta que `ADR-050 §11` (política de datos) sigue sin resolver. Los tres revisores siguen siendo obligatorios y completos. Lo que sigue es el protocolo de `ADR-049`/`ADR-050`, que también rige los límites del régimen nuevo.

## Comandos permitidos, y solo estos

Con `ADR-069` la sesión usa los subcomandos equivalentes por `Bash` (solo `review` y `adversarial-review`). Comandos: `/codex:review`, `/codex:adversarial-review`, `/codex:status`, `/codex:result`, `/codex:cancel`, `/codex:setup` (solo para comprobar instalación/estado).

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

## La prueba terminó (`ADR-050`)

Los tres puntos de medición de `ADR-049 §8` ya se ejecutaron y evaluaron (calibrado sobre PR #204 + `1.6c` + `1.6d`). El umbral formal no se cumplió (falló el eje de Cobertura, medido una sola vez en el calibrado y sin repetición posible), pero el usuario decidió expresamente mantener la herramienta a la vista del resultado completo — `ADR-050` retira `ADR-049 §8` y conserva `§9` como procedimiento **ordinario** de retirada, no como consecuencia de una prueba. **Ya no hay "prueba abierta" ni "pasos de prueba"**, y ya no se anota en `memory.md` un recuento de propuestos/aceptados/descartados como registro de evaluación.

Lo que sigue vigente, sin ritual nuevo, porque ya lo exige `ADR-049 §7.2` de arriba:
- **Todo hallazgo aceptado** lleva su procedencia (`/codex:review`, `/codex:adversarial-review`) etiquetada en el issue y en la entrada de `CHANGELOG.md` del paso.
- **Todo hallazgo descartado** se anota con su motivo en la nota de cierre del paso.

**En el régimen de respaldo es opcional, no obligatoria** (con `ADR-069` confirmada pasa a ser obligatoria por el proceso, sin entrar en `CLAUDE.md §10`). No invocarla en un paso no es un incumplimiento de proceso, no bloquea un cierre y no forma parte de la definición de terminado (`CLAUDE.md §10`, `ADR-050 §5.5`). Que acierte muchas veces seguidas **no le da autoridad** — sigue siendo un candidato a triar, nunca un veredicto.

## Antes de la primera vez en una máquina nueva

Comprobar la configuración **efectiva**, no el fichero (`codex doctor`, o un `codex exec` de prueba pidiendo escribir un fichero y confirmando que se bloquea). El `.codex/config.toml` de proyecto puede no aplicarse por un *bug* conocido de `openai/codex` (issue #30001) — la protección real puede depender de la réplica en `~/.codex/config.toml` (nivel de usuario, fuera del repositorio). Verificarlo, no suponerlo.

## Retirada (`ADR-050 §5.2`/`§5.3`)

`ADR-049 §9` sigue siendo el procedimiento de retirada, palabra por palabra, pero ya no depende de un umbral de prueba. Se ejecuta **sin discusión y de inmediato** (dejar de invocar cualquier comando `/codex:*` en el acto; la ejecución material de `§9` se le pide al usuario en la misma sesión, porque toca configuración versionada) si ocurre cualquiera de estas:

1. Se detecta **una sola vez** que el plugin ha escrito en el árbol de trabajo, o que se ha ejecutado `/codex:rescue`, `/codex:transfer` o la puerta de revisión `Stop`.
2. **Aparece un dato personal real** en esta máquina o en este repositorio (cierre de `OPEN-11`, llegada del centro piloto, cualquier exportación) — los datos de `REQ-SEED-005` son ficticios y no cuentan. Es la única caducidad automática que queda, y no admite ponderación.
3. El proyecto `openai/codex-plugin-cc` queda archivado, cambia de licencia, o aparece un aviso de seguridad sin corregir.

Fuera de esas tres, **el usuario puede retirarla en cualquier momento, sin evaluación previa y sin tener que justificarlo**.
