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
