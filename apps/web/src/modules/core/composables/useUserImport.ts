/**
 * `docs/modulos/REQ-CORE/funcional.md §14.6.2`, `RN-CORE-72`. Carga de un
 * lote de importación y seguimiento de su estado con **la misma política que
 * `RN-CORE-49`** y las constantes exportadas de `src/data-table`:
 *
 * - **Una sola consulta en vuelo**; espera inicial de 2 s que se duplica
 *   hasta 30 s; se deja de consultar a los 10 min desde que empezó el
 *   seguimiento y se ofrece «Comprobar de nuevo», que reinicia el ciclo. Sin
 *   *worker* de colas (#128) un lote se queda en `subido` para siempre: la
 *   interfaz no consulta sin fin.
 * - Solo se consulta mientras el lote está en `subido`, `validando` o
 *   `ejecutando`. Se detiene al desmontar la vista.
 * - A diferencia de una exportación, **salir de la vista no pierde nada**: el
 *   lote aparece en el listado.
 * - `lastResponseAt` es el instante de la última respuesta correcta: la vista
 *   renueva el detalle si el enlace firmado del informe puede haber caducado
 *   (`report_url` vale 15 min).
 */
import { onBeforeUnmount, ref, shallowRef } from 'vue'
import { EXPORT_POLL_INITIAL_MS, EXPORT_POLL_MAX_MS, EXPORT_POLL_TIMEOUT_MS } from '@/data-table'
import { getUserImport } from '../api'
import type { PublicId, UserImport, UserImportStatus } from '../types'

const IN_PROGRESS: readonly UserImportStatus[] = ['subido', 'validando', 'ejecutando']

export function isImportInProgress(status: UserImportStatus): boolean {
  return IN_PROGRESS.includes(status)
}

export function useUserImport(getPublicId: () => PublicId) {
  const data = shallowRef<UserImport | null>(null)
  const loading = ref(true)
  /** Error de la carga inicial o de una renovación: lo interpreta la vista (`resolveErrorState`). */
  const error = ref<unknown>(null)
  /** Se agotó la duración máxima del seguimiento o falló una consulta de estado. */
  const stalled = ref(false)
  const lastResponseAt = ref(0)

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

  /** Empieza (o reinicia) el seguimiento si el lote sigue en curso; lo detiene si no. */
  function followIfInProgress(): void {
    clearTimer()

    if (data.value && isImportInProgress(data.value.status)) {
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
      const next = await getUserImport(getPublicId())

      if (disposed) {
        return
      }

      data.value = next
      lastResponseAt.value = Date.now()

      if (isImportInProgress(next.status)) {
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

  /** Carga inicial: errores a la vista; si el lote está en curso, empieza el seguimiento. */
  async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
      const loaded = await getUserImport(getPublicId())

      if (disposed) {
        return
      }

      data.value = loaded
      lastResponseAt.value = Date.now()
      followIfInProgress()
    } catch (err) {
      if (!disposed) {
        data.value = null
        error.value = err
      }
    } finally {
      loading.value = false
    }
  }

  /** Renueva el detalle sin pantalla de carga (acciones, enlace del informe caducado). */
  async function refresh(): Promise<void> {
    if (inFlight) {
      return
    }

    inFlight = true

    try {
      const next = await getUserImport(getPublicId())

      if (disposed) {
        return
      }

      data.value = next
      lastResponseAt.value = Date.now()
      error.value = null

      // Un seguimiento ya agotado (`stalled`) solo se reanuda con «Comprobar de
      // nuevo»: renovar el detalle (p. ej. el enlace del informe) no lo reinicia.
      if (!stalled.value) {
        followIfInProgress()
      }
    } catch (err) {
      if (!disposed) {
        error.value = err
      }
    } finally {
      inFlight = false
    }
  }

  /** Respuesta de una acción (p. ej. `execute` devuelve el recurso `ejecutando`). */
  function replace(next: UserImport): void {
    data.value = next
    lastResponseAt.value = Date.now()
    followIfInProgress()
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

  return { data, loading, error, stalled, lastResponseAt, load, refresh, replace, checkAgain }
}
