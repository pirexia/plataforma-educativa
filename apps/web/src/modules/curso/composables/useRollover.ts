/**
 * `docs/modulos/REQ-CURSO/funcional.md §10.6` (1.12b), patrón de las exportaciones de `ADR-054 §8` y de
 * `useUserImport`. Carga una copia y consulta su estado con **espera creciente** (2 s, 4 s, … tope de 30 s) mientras
 * está en vuelo (`pendiente`, `en_curso`, `revirtiendo`); se detiene al llegar a `completado`, `fallido` o
 * `revertido` y al desmontar la vista.
 *
 * - **Una sola consulta en vuelo**; se deja de consultar a los 10 min desde que empezó el seguimiento y se ofrece
 *   «Comprobar de nuevo», que reinicia el ciclo con la misma espera creciente (cubre un *worker* caído o atascado).
 *   Pasado el tope no hay error: `stalled`.
 * - Las constantes son las de `src/data-table` (`RN-CORE-49`).
 */
import { onBeforeUnmount, ref, shallowRef } from 'vue'
import { EXPORT_POLL_INITIAL_MS, EXPORT_POLL_MAX_MS, EXPORT_POLL_TIMEOUT_MS } from '@/data-table'
import { getRollover } from '../api'
import type { PublicId, Rollover } from '../types'

const IN_FLIGHT = ['pendiente', 'en_curso', 'revirtiendo']

export function isRolloverInFlight(status: string): boolean {
  return IN_FLIGHT.includes(status)
}

export function useRollover(getPublicId: () => PublicId) {
  const data = shallowRef<Rollover | null>(null)
  const loading = ref(true)
  /** Error de la carga inicial: lo interpreta la vista (`resolveErrorState`). */
  const error = ref<unknown>(null)
  /** Se agotó la duración máxima del seguimiento (10 min) o falló una consulta de estado. */
  const stalled = ref(false)

  let timer: ReturnType<typeof setTimeout> | null = null
  let disposed = false
  let inFlight = false
  let startedAt = 0
  let delay = EXPORT_POLL_INITIAL_MS

  function clearTimer(): void {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  function schedule(): void {
    const remaining = EXPORT_POLL_TIMEOUT_MS - (Date.now() - startedAt)

    if (remaining <= 0) {
      stalled.value = true

      return
    }

    timer = setTimeout(() => void poll(), Math.min(delay, remaining))
    delay = Math.min(delay * 2, EXPORT_POLL_MAX_MS)
  }

  /** Empieza (o reinicia) el seguimiento si la copia está en vuelo; lo detiene si no. */
  function followIfInFlight(): void {
    clearTimer()

    if (data.value && isRolloverInFlight(data.value.status)) {
      startedAt = Date.now()
      delay = EXPORT_POLL_INITIAL_MS
      stalled.value = false
      schedule()
    } else {
      stalled.value = false
    }
  }

  async function poll(): Promise<void> {
    timer = null

    if (disposed || inFlight) {
      return
    }

    if (Date.now() - startedAt >= EXPORT_POLL_TIMEOUT_MS) {
      stalled.value = true

      return
    }

    inFlight = true

    try {
      const next = await getRollover(getPublicId())

      if (disposed) {
        return
      }

      data.value = next

      if (isRolloverInFlight(next.status)) {
        schedule()
      }
    } catch {
      if (!disposed) {
        stalled.value = true
      }
    } finally {
      inFlight = false
    }
  }

  /** Carga inicial: los errores van a la vista; si la copia está en vuelo, empieza el seguimiento. */
  async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
      const loaded = await getRollover(getPublicId())

      if (disposed) {
        return
      }

      data.value = loaded
      followIfInFlight()
    } catch (err) {
      if (!disposed) {
        data.value = null
        error.value = err
      }
    } finally {
      loading.value = false
    }
  }

  /** Respuesta de una acción (revertir, cancelar): sustituye el estado y reanuda el seguimiento si procede. */
  function replace(next: Rollover): void {
    data.value = next
    followIfInFlight()
  }

  /** «Comprobar de nuevo»: reinicia el ciclo y consulta ya. */
  function checkAgain(): void {
    clearTimer()
    startedAt = Date.now()
    delay = EXPORT_POLL_INITIAL_MS
    stalled.value = false
    void poll()
  }

  onBeforeUnmount(() => {
    disposed = true
    clearTimer()
  })

  return { data, loading, error, stalled, load, replace, checkAgain }
}
