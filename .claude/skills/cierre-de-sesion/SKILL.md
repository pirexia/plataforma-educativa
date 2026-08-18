---
name: cierre-de-sesion
description: Protocolo de arranque y cierre de sesión de trabajo. Úsala al empezar cualquier sesión, cuando la cuota se esté agotando, y antes de terminar.
---

# Protocolo de sesión

El plan es Pro, con límite de 5 horas. El trabajo se corta sin aviso. **El estado debe estar siempre guardado**, no solo al final.

## Al arrancar

1. Lee `memory.md`.
2. Lee `PLAN-IMPLEMENTACION.md` y localiza el paso activo.
3. Comprueba rama actual y trabajo sin commitear.
4. Resume en 5 líneas dónde estamos y qué toca. Espera confirmación antes de escribir código.

## Durante

- Commit tras cada unidad de trabajo que compile y pase tests. Nunca acumules horas sin commitear.
- Actualiza `memory.md` tras cada hito, no al final.
- Si detectas que queda poca cuota, **avisa y cierra ordenadamente** en lugar de empezar algo nuevo.

## Cierre automático por límite de cuota

No hay herramienta que consulte el porcentaje de cuota consumida ni la hora de reset. La señal que yo recibo es **reactiva y sin esa hora**: un aviso de sistema inyectado en la conversación (visto literalmente como *"Usage limit approaching. Checkpoint now..."*).

**La hora de reset sí existe, pero no me llega a mí.** Confirmado por el usuario el 2026-08-18: la app cliente (probado en Android) consulta el estado de la cuenta por su cuenta y muestra una tarjeta propia de interfaz ("Cerca del límite · Se restablece el &lt;día&gt; a las &lt;hora&gt;") que **no forma parte de lo que se me inyecta como modelo**. Es una pieza de UI del cliente, no texto de sistema — no asumas que la vas a poder leer ni parsear, no está en tu contexto.

En cuanto aparezca el aviso de sistema, **no esperes a que el usuario lo pida** — actúa igual que en un cierre de sesión normal, con dos añadidos:

1. Termina el paso atómico en curso (el commit o la revisión que tengas entre manos). No empieces uno nuevo.
2. Ejecuta el cierre completo de la sección "Al cerrar" de más abajo: commit, push, `memory.md`, `PLAN-IMPLEMENTACION.md`, repositorio en verde.
3. **Programa la vuelta con `ScheduleWakeup`**:
   - **Pregúntale al usuario la hora de reset**, salvo que ya te la haya dado en la propia conversación (por ejemplo, leyéndotela de la tarjeta de su app) — en ese caso, úsala directamente sin volver a preguntar. No la asumas ni la calcules por tu cuenta en ningún caso: no está en tu contexto.
   - `ScheduleWakeup` admite como máximo 1 hora por llamada. Si el reset queda más lejos, no lo fuerces con un valor mayor: encadena avisos de una hora en una hora (cada aviso, al dispararse, si todavía no ha llegado la hora de reset, programa el siguiente tramo) hasta alcanzarla. No es sondeo — es un único objetivo final repartido en tramos, no una comprobación repetida de "¿ya está listo?".
   - El `prompt` del aviso debe bastar para retomar sin releer nada: qué paso estaba en curso, en qué rama, y el siguiente subpaso concreto.
4. Informa al usuario de que has cerrado por límite de cuota y de cuándo volverás, antes de que la sesión termine de responder.

## Al cerrar

1. Commit y push de todo lo funcional.
2. Actualiza `memory.md`:
   - Qué se completó
   - Qué queda a medias y en qué punto exacto
   - **Siguiente paso concreto**, escrito de forma que se pueda retomar sin recordar nada
   - Decisiones tomadas
   - Problemas e issues abiertos
3. Marca el progreso en `PLAN-IMPLEMENTACION.md`.
4. Deja el repositorio compilable y con tests en verde.

`memory.md` debe permanecer corto. Si supera unas 150 líneas, archiva lo antiguo en `docs/historial/`.
