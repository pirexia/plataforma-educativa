# Casos reales que motivan normas de `CLAUDE.md`

> Fichero de carga bajo demanda. `CLAUDE.md` conserva la regla y enlaza aquí con `Motivo: docs/proceso/casos-reales.md#id`. Lo que sigue es el relato original (fechas, hashes, issues), movido tal cual desde `CLAUDE.md` v2.8.0 en el recorte de contexto fijo del 2026-11-02. No es normativo: la norma vive en `CLAUDE.md`.

## horizon-no-instalado

Motiva: §1, «la tabla dice qué está elegido, no qué está instalado».

Durante la especificación de `1.6d` (2026-09-16), la fila de colas dio a entender que Horizon estaba desplegado y una especificación llegó a escribir que la ficha de salud leía sus datos, cuando ni la dependencia ni su configuración existen.

Dato de la fila de colas: el proceso `worker-long` (cola `long`, conexión `database_long`, `retry_after` 900 s, `infra/quadlet/worker-long.container`) se introdujo en `1.12b` y lo ratificó el usuario el 2026-10-21 porque el `retry_after` es de la conexión con la que se lee la cola. El issue [#128](https://github.com/pirexia/plataforma-educativa/issues/128) (ningún worker desplegado) está resuelto.

## relanzamiento-recorta-alcance

Motiva: §3, «relanzar un subagente no es licencia para decidir alcance».

Un `implementer` relanzado tras un corte de cuota recortó por su cuenta un endpoint entero del alcance ya aprobado (`GET /mfa-compliance/users`, 1.3/`REQ-AUTH-003`, 2026-08-27) agrupándolo por error con algo que sí estaba diferido. El hallazgo solo se detectó en la revisión posterior. Por eso quien relanza (la sesión orquestadora) contrasta lo entregado contra la especificación antes de darlo por bueno: una continuación es el punto donde más fácil es que se cuele una decisión no autorizada.

## cortes-cuota-reverificacion

Motiva: §3, «constancia de lo ya verificado».

`1.4c`, `REQ-AUTH-004`, 2026-09-02/03: tres cortes de cuota consecutivos en el mismo paso obligaron, en cada relanzamiento, a reverificar desde cero trabajo que ya estaba bien, porque no quedaba ningún registro de qué se había probado y confirmado. De ahí el mensaje `Verificado: X/X …` en el último commit del lote y la nota viva de `memory.md`.

## docs-raiz-desactualizados

Motiva: §6 regla 7, vigencia de los documentos raíz.

`SECURITY.md`, `README.md`, `PRIVACY.md` y `docs/REQUISITOS-...md` quedaron desactualizados durante los cinco cierres de fase que van de `1.1` a `1.3b` (2026-08-22/31) sin que ninguna revisión lo detectara, porque el checklist de `doc-reviewer` solo cubría documentación de módulo (issues #111-#114, #125).

## coste-tramos

Motiva: §2 «Ahorro de cuota», punto 1 (tramos de `implementer`).

Razonamiento del coste aproximado: `k·R + c·n²/(2k)`, con k = número de tramos, R = lectura inicial de cada tramo, n = llamadas totales y c = coste por llamada y unidad de contexto. Los pasos grandes piden más tramos; las lecturas iniciales caras piden menos. Se aplica con las métricas de `docs/metricas-agentes.md`.

## cache-prompt

Motiva: §2 «Aprovechamiento de la caché de prompt».

La caché solo acierta si el prefijo de la petición (system prompt, definiciones de herramientas, turnos previos) coincide byte a byte con el de una petición reciente; vigencia por defecto de 5 minutos desde el último acierto. Cada fallo reprocesa el contexto entero. Un `fork` comparte la caché de quien lo lanza; un subagente nuevo empieza siempre en frío. Cada herramienta nueva cargada a mitad de sesión cambia el bloque de definiciones e invalida el prefijo cacheado. Las normas de higiene de contexto (cierre por cuota, cierre entre pasos) priman sobre el ahorro de caché.

## codex-prueba-cerrada

Motiva: §2, segunda opinión externa.

La prueba acotada de `ADR-049 §8` ya terminó (evaluada, umbral formal no cumplido) y `ADR-050` la sustituye: uso permanente y opcional, sin revalidación periódica, con las condiciones de retirada inmediata de `ADR-050 §5.3` siempre armadas. Detalle en `docs/historial/adr-050-cierre-prueba-codex.md`.

## cuota-semanal

Motiva: §2 «Ahorro de cuota».

Norma del usuario (2026-11-02): la cuota semanal del plan Pro se agota hacia el miércoles o el jueves y el proyecto durará meses; el gasto de tokens es una restricción de diseño del proceso, no un detalle. Sobre el punto 5: con un contexto pequeño (≈16 %) arrastrarlo cuesta menos que releer los ficheros de un arranque en frío; la caché caduca tras ~1 h de inactividad (p. ej. mientras un subagente trabaja más de una hora).
