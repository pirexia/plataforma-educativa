# Trazabilidad del recorte de contexto fijo (2026-11-02)

> Para revisar que el recorte de `CLAUDE.md` (2.8.0 → 2.9.0) y de `memory.md` no perdió ninguna regla. Original: `git show dd889aa:CLAUDE.md` y `git show dd889aa:memory.md` (el segundo, además, íntegro en `docs/proceso/memoria-archivo.md`). Ninguna regla cambia de sentido; solo se reescribe o se mueve el relato.

## CLAUDE.md

| Original (2.8.0) | Ahora |
|---|---|
| Cabecera de versión | `CLAUDE.md` cabecera, 2.9.0 |
| §0 completo (regla fundamental, frases prohibidas) | `CLAUDE.md §0`, sin cambios |
| §1 tabla de stack (11 filas) | `CLAUDE.md §1`, completa; fila Colas sin enlaces largos, contenido igual |
| §1 párrafo «la tabla dice qué está elegido» + caso `1.6d` | Regla en `§1` (una línea, con `composer.json`/`package.json`); relato en `casos-reales.md#horizon-no-instalado` |
| §1 Prohibido dependencias nuevas / mezclar UI / `design-system.md §22` | `CLAUDE.md §1`, sin cambios |
| §2 «Plan y modelo por defecto» | `§2` «Modelo por defecto», reescrito (Pro 5 h, Sonnet, Opus a mano, alias) |
| §2 tablas de tarea→modelo y de agentes | `§2`, sin cambios |
| §2 viñetas (modo auto, Opus, delegar, heredan modelo, subagente no lanza subagentes) | `§2`, fusionadas dos y reescritas; todas las reglas presentes |
| §2 Codex `ADR-049`/`ADR-050` | `§2`, condensado; relato de «prueba terminada» en `casos-reales.md#codex-prueba-cerrada` |
| §2 «Copia de seguridad de la configuración» + comandos + `PLAN` no se restaura | `docs/proceso/restauracion-configuracion.md`; en `§2` queda etiqueta, commit y enlace |
| §2 «Aprovechamiento de la caché» (4 reglas + explicación) | `§2` «Caché de prompt» (4 reglas); explicación en `casos-reales.md#cache-prompt` |
| §2 «Ahorro de cuota» puntos 1-6 | `§2` puntos 1-6; fórmula de coste en `casos-reales.md#coste-tramos`; justificación del punto 5 en `#cuota-semanal` |
| (nuevo) | `§2` puntos 7 (tamaño de entrega) y 8 (documentación) |
| §3 arranque, durante, cierre, cierre automático, cierre entre pasos | `§3`, reescritos sin perder reglas |
| §3 relanzar subagente no decide alcance + caso `GET /mfa-compliance/users` | Regla en `§3`; caso en `casos-reales.md#relanzamiento-recorta-alcance` |
| §3 constancia de lo verificado + caso `1.4c` | Regla (2 puntos) en `§3`; caso en `#cortes-cuota-reverificacion` |
| §3 «Cuándo se ejecuta la suite» y «E2E contra la API real» | `§3`, sin cambio de contenido |
| §4 Git, §4 `.gitignore` | `§4`, sin cambios |
| §5 Incidencias | `§5`, sin cambios |
| §6 estructura y reglas 1-6 | `§6`, sin cambios |
| §6 regla 7 + caso de documentos raíz | Regla en `§6.7`; caso en `#docs-raiz-desactualizados` |
| §7 invariantes, esquema, idiomas; §8; §9; §10; §11 | Sin cambios |

## memory.md

| Original | Ahora |
|---|---|
| Estado actual: párrafo largo de fase/bloques | Resumen por paso con PR en `memory.md` «Estado actual»; original en `memoria-archivo.md` |
| Detalle de `1.6e`, `1.5`, `1.4*`, `1.3*`, `1.2*`, `1.1`, chores | Una línea cada uno en «Pasos cerrados»; detalle en `memoria-archivo.md` y `docs/historial/` |
| Lecciones de *worktrees* (3) | «Estado actual», condensadas |
| Series `0.10`-`0.10e` | «Estado actual», condensada |
| Configuración de modelos 2026-10-08; normas de proceso | «Estado actual», una línea cada una |
| Tabla de decisiones (ADR) | «Decisiones tomadas» condensada por grupos; texto íntegro en `memoria-archivo.md` y los ADR |
| Trabajo en curso `1.14b-a` | Intacto en «Trabajo en curso» (cabecera de estado: «CERRADO Y MEZCLADO (PR #497, `d30678d`)») |
| `1.14b` especificación | «Trabajo en curso», condensado (entregas, abiertas, condiciones previas) |
| `1.14-d`, `1.14-c`, `1.14-b`, `1.14-a`, `1.12*`, `1.11*`, centro demo | Cerrados: una línea en «Pasos cerrados»; pendientes del usuario y no probados, en «Trabajo en curso»; resto en el archivo |
| Issues abiertos por decisión | «Trabajo en curso», conservada |
| Bloqueantes (6 filas) | Intactos |
| Problemas abiertos (tabla de ~45 filas) | Lista por severidad con todos los issues; #128 retirado (resuelto, PR #456) |
| Siguiente paso concreto 1-5, Codex, lecciones, notas de entorno | «Siguiente paso concreto»; punto 1 actualizado a `1.14b-b`; lecciones y notas de entorno condensadas, más 3 lecciones que estaban en párrafos cerrados |
