/**
 * `docs/modulos/REQ-CORE/funcional.md §13.14`, `RN-CORE-46`/`-49`,
 * `ADR-054 §7`. Disparador de la exportación asíncrona y seguimiento de su
 * estado. **La SPA nunca genera el fichero** (`RN-CORE-46`): solicita al
 * *endpoint* del módulo dueño del recurso (que lo genera en cola) y
 * consulta `GET /data-exports/{id}` con espera creciente. La descarga es
 * un enlace a la URL firmada: sin `fetch` del fichero, sin `Blob`.
 *
 * - **Una sola consulta en vuelo** por exportación; espera inicial de 2 s,
 *   que se duplica hasta 30 s; se deja de consultar a los 10 min desde la
 *   solicitud y se ofrece «Comprobar de nuevo», que reinicia el ciclo
 *   (`RN-CORE-49`). Sin *worker* de colas (issue #128) una exportación
 *   queda `pendiente` para siempre: la interfaz no consulta sin fin.
 * - Se detiene al desmontar la vista (`onBeforeUnmount`).
 * - Sin diálogo de confirmación al salir ni `beforeunload` (`OPEN-CORE-25`,
 *   `ADR-054 §7.7`): la interfaz solo **avisa**, sin bloquear.
 */
import { onBeforeUnmount, ref, type Ref } from 'vue'
import { ApiError } from '@/api/client'
import { EXPORT_POLL_INITIAL_MS, EXPORT_POLL_MAX_MS, EXPORT_POLL_TIMEOUT_MS } from './constants'
import type { DataTableExportConfig } from './types'

export type ExportFlowState =
  | { phase: 'idle' }
  | { phase: 'requesting' }
  /** `pendiente` o `generando`. */
  | { phase: 'preparing' }
  /** Se agotó la duración máxima de la consulta: «Comprobar de nuevo». */
  | { phase: 'timedOut' }
  | { phase: 'completed'; url: string; expiresAt: string | null }
  | { phase: 'failed'; errorCode: string | null }
  /** `410`. */
  | { phase: 'expired' }
  /** `422` de la solicitud (`RNF-LIM-004`) u otro error al solicitar. */
  | { phase: 'requestError'; message: string | null }
  /** Error inesperado al consultar el estado. */
  | { phase: 'statusError' }

/** Solo http(s): Vue no filtra el esquema de `href` (issue #275, CA-CORE-190). */
function isSafeDownloadUrl(url: string | null | undefined): url is string {
  if (!url) {
    return false
  }

  try {
    const { protocol } = new URL(url, window.location.origin)

    return protocol === 'https:' || protocol === 'http:'
  } catch {
    return false
  }
}

function serverMessage(err: unknown): string | null {
  if (!(err instanceof ApiError) || err.status !== 422) {
    return null
  }

  const body = err.body as {
    errors?: Record<string, { message?: unknown }[]>
    detail?: unknown
  } | null
  const first = body?.errors
    ? Object.values(body.errors)
        .flat()
        .find((entry) => typeof entry?.message === 'string')
    : undefined

  if (typeof first?.message === 'string') {
    return first.message
  }

  return typeof body?.detail === 'string' && body.detail !== '' ? body.detail : null
}

export function useExportFlow(getConfig: () => DataTableExportConfig | undefined): {
  state: Ref<ExportFlowState>
  busy: Ref<boolean>
  start: (filters: Record<string, string>) => Promise<void>
  checkAgain: () => void
} {
  const state = ref<ExportFlowState>({ phase: 'idle' })
  const busy = ref(false)

  let timer: ReturnType<typeof setTimeout> | null = null
  let disposed = false
  let inFlight = false
  let publicId: string | null = null
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
      state.value = { phase: 'timedOut' }

      return
    }

    timer = setTimeout(() => void poll(), Math.min(delay, remaining))
    delay = Math.min(delay * 2, EXPORT_POLL_MAX_MS)
  }

  async function poll(): Promise<void> {
    timer = null

    const config = getConfig()

    if (disposed || inFlight || publicId === null || !config) {
      return
    }

    if (Date.now() - startedAt >= EXPORT_POLL_TIMEOUT_MS) {
      state.value = { phase: 'timedOut' }

      return
    }

    inFlight = true

    try {
      const status = await config.status(publicId)

      if (disposed) {
        return
      }

      if (status.status === 'completada') {
        state.value = isSafeDownloadUrl(status.download_url)
          ? { phase: 'completed', url: status.download_url, expiresAt: status.expires_at }
          : { phase: 'statusError' }
      } else if (status.status === 'fallida') {
        state.value = { phase: 'failed', errorCode: status.error_code ?? null }
      } else {
        schedule()
      }
    } catch (err) {
      if (disposed) {
        return
      }

      if (err instanceof ApiError && err.status === 409) {
        // «Aún no está lista»: se sigue esperando.
        schedule()
      } else if (err instanceof ApiError && err.status === 410) {
        state.value = { phase: 'expired' }
      } else {
        state.value = { phase: 'statusError' }
      }
    } finally {
      inFlight = false
    }
  }

  function beginPolling(id: string): void {
    clearTimer()
    publicId = id
    startedAt = Date.now()
    delay = EXPORT_POLL_INITIAL_MS
    state.value = { phase: 'preparing' }
    schedule()
  }

  async function start(filters: Record<string, string>): Promise<void> {
    const config = getConfig()

    if (!config?.canExport || busy.value) {
      return
    }

    busy.value = true
    clearTimer()
    publicId = null
    state.value = { phase: 'requesting' }

    try {
      const created = await config.request(filters)

      if (disposed) {
        return
      }

      beginPolling(created.public_id)
    } catch (err) {
      if (disposed) {
        return
      }

      state.value = { phase: 'requestError', message: serverMessage(err) }
    } finally {
      busy.value = false
    }
  }

  /** «Comprobar de nuevo»: reinicia el ciclo y consulta ya. */
  function checkAgain(): void {
    if (publicId === null) {
      return
    }

    clearTimer()
    startedAt = Date.now()
    delay = EXPORT_POLL_INITIAL_MS
    state.value = { phase: 'preparing' }
    void poll()
  }

  onBeforeUnmount(() => {
    disposed = true
    clearTimer()
  })

  return { state, busy, start, checkAgain }
}
