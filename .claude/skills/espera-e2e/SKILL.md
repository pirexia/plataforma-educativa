---
name: espera-e2e
description: Ejecuta el E2E real contra la API (Playwright, `npm run test:e2e:real` y sus variantes `:prl`, `:alum`) en segundo plano, sin agente, y devuelve un resumen corto y fiable (suites, pasadas, falladas, primeros errores). Úsala SIEMPRE que haya que lanzar el E2E real de un PR o de un paso (la sesión orquestadora), en lugar de delegarlo en `verificador` solo para ejecutar un comando; `verificador` o `implementer` entran solo si hay que diagnosticar un fallo. No para pruebas con mocks (Vitest, `data-table.spec.ts`).
---

# Ejecutar el E2E real y esperar el resultado

**Por qué una skill**: delegar el E2E en un agente cuesta decenas de miles de tokens (medido: 27 000-48 000 por ejecución) para devolver diez líneas. Lanzar el script en segundo plano y resumir su salida cuesta del orden de mil (estimación). El agente solo hace falta si algo falla. Norma de contexto: `CLAUDE.md §3` (E2E contra la API real) y `§10`.

## Antes de lanzar

1. **Una sola ejecución a la vez.** Todos los E2E reales comparten la pila de desarrollo (una base de datos, la API en `:8000`) y el contenedor de la API monta el **checkout principal**: no lances dos E2E a la vez ni cambies de rama o toques ficheros del checkout mientras corre uno.
2. Checkout en la rama del PR con el árbol limpio: `git branch --show-current`, `git status --short` (vacío). Si hay otro proceso (agente) usando el checkout, espera.
3. Pila arriba: `podman ps --format '{{.Names}} {{.Status}}'` con `plataforma-postgres`, `plataforma-redis` y `plataforma-api` en `healthy`. Si no, `podman compose` según `SYSADMIN.md`. Recrear el demo o la base está permitido sin pedir permiso (`CLAUDE.md §3`).
4. Decide el alcance: la verificación final de un PR es la suite **completa** (sin `E2E_ONLY`) más `:prl` si el PR toca PRL, y `:alum` si toca alumnado. `E2E_ONLY=<suite>` solo sirve para iterar durante el desarrollo, nunca para dar un PR por verde.

## Lanzar (una sola orden, en segundo plano)

Nunca `sleep` en primer plano ni sondear el fichero de salida: la sesión se despierta cuando termina. Usa `run_in_background: true`:

```bash
cd apps/web
LOG=<scratchpad>/e2e-real-$(date +%H%M%S).log
npm run test:e2e:real > "$LOG" 2>&1; echo "EXIT=$?" >> "$LOG"
# Variantes (otra orden de la misma ejecución, solo si el alcance las exige):
# npm run test:e2e:real:prl  >> "$LOG" 2>&1; echo "EXIT_PRL=$?" >> "$LOG"
# npm run test:e2e:real:alum >> "$LOG" 2>&1; echo "EXIT_ALUM=$?" >> "$LOG"
echo "== resumen =="; grep -E "^\s+[0-9]+ (passed|failed|skipped|flaky)|EXIT" "$LOG"
echo "== fallos =="; grep -E "^\s+[0-9]+\) |Error:|Expected|Received|Timeout" "$LOG" | head -20
```

## Interpretar

- **Verde**: `EXIT=0` en todas las órdenes lanzadas y `0 failed`. Anota cifras reales por suite (no «todo bien»).
- **Fallo de entorno** (código de salida 2, pila caída, Garage, puerto ocupado, `demo.plataforma.test` sin resolver): lo arregla la sesión (levantar contenedores, recrear demo) y se **reintenta**. Un reintento que pasa solo tras tocar el entorno se cuenta una vez y se dice.
- **Fallo de código**: no lo arregles aquí. Reencarga a `implementer` **solo el fallo**, con el error literal, y vuelve a lanzar la suite completa al terminar.
- **Intermitente** (pasa y falla sin cambios): no lo des por verde; abre issue (`CLAUDE.md §5`) con el log.
- **Parcial no es verde**: si no se ejecutó una suite que el alcance exigía, dilo exactamente.

## Al terminar

1. Deja el checkout en `develop` con el árbol limpio si saliste de ella.
2. Registra en el informe del paso y en `memory.md` **qué se probó y qué no** (p. ej. el S3 real del proveedor, `ADR-076 §2.3`; el scroll en «atrás/adelante»).
3. Si el cambio altera un comportamiento visible y **no hay spec que lo ejercite**, el E2E «verde» no vale como prueba de ese cambio: falta el spec (`CLAUDE.md §10`).
