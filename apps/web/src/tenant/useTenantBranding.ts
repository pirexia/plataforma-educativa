/**
 * `docs/design-system.md` §7. Capa B del *design system*: contexto del
 * centro. Conoce el tenant (a diferencia de `@/design-system/**`) y es el
 * **único** llamador de `getTenantBranding()` en la SPA (`RN-DS-22`,
 * `CA-DS-029`). Singleton de ámbito de módulo (`shallowRef`), sin Pinia
 * (`ADR-052`, Alternativas). Sin función de *reset* exportada para
 * tests: se aíslan con `vi.resetModules()`.
 */

import { shallowRef, type Ref } from 'vue'
import { getTenantBranding } from '@/modules/core/api'
import type { TenantBranding } from '@/modules/core/types'
import { isValidHex } from '@/design-system/color/color'
import { applyBrandPalette } from '@/design-system/theme/brandPalette'
import { applyFavicon } from './favicon'

export type TenantBrandingStatus = 'loading' | 'ready' | 'not-found' | 'unavailable'

export const BRANDING_BOOT_TIMEOUT_MS = 1000
export const BRAND_CACHE_KEY = 'plataforma.brand'

const branding = shallowRef<TenantBranding | null>(null)
const status = shallowRef<TenantBrandingStatus>('loading')

let inFlight: Promise<void> | null = null
const reportedAssetErrorUrls = new Set<string>()

interface CachedBrand {
  v: 1
  primary: string
  primaryForeground: string
}

function isCachedBrand(value: unknown): value is CachedBrand {
  return (
    typeof value === 'object' &&
    value !== null &&
    (value as { v?: unknown }).v === 1 &&
    typeof (value as { primary?: unknown }).primary === 'string' &&
    isValidHex((value as { primary: string }).primary) &&
    typeof (value as { primaryForeground?: unknown }).primaryForeground === 'string' &&
    isValidHex((value as { primaryForeground: string }).primaryForeground)
  )
}

function writeCache(primary: string, primaryForeground: string): void {
  try {
    const payload: CachedBrand = { v: 1, primary, primaryForeground }
    localStorage.setItem(BRAND_CACHE_KEY, JSON.stringify(payload))
  } catch {
    // localStorage bloqueado (navegación privada, cuota agotada): la
    // caché es una optimización, no un requisito — se sigue sin ella.
  }
}

function clearCache(): void {
  try {
    localStorage.removeItem(BRAND_CACHE_KEY)
  } catch {
    // Idem.
  }
}

/**
 * Arranque, síncrono: aplica la paleta cacheada de la visita anterior, si
 * la hay y es válida (`RN-DS-08`). No toca `branding`/`status`: solo
 * pinta antes de que llegue la respuesta real, para evitar el destello a
 * neutros en visitas repetidas al mismo centro.
 */
export function primeBrandingFromCache(): void {
  let raw: string | null

  try {
    raw = localStorage.getItem(BRAND_CACHE_KEY)
  } catch {
    // `localStorage` inaccesible (navegación privada, bloqueado): se
    // sigue sin caché, sin más intentos de acceso.
    return
  }

  if (raw === null) {
    return
  }

  let parsed: unknown

  try {
    parsed = JSON.parse(raw)
  } catch {
    // No parsea: se borra y no se aplica (§7.2).
    clearCache()
    return
  }

  if (!isCachedBrand(parsed)) {
    clearCache()
    return
  }

  applyBrandPalette({ primary: parsed.primary, primaryForeground: parsed.primaryForeground })
}

function applySuccess(data: TenantBranding): void {
  branding.value = data
  status.value = 'ready'

  const hasBothColors = data.color_primary !== null && data.color_secondary !== null

  if (hasBothColors) {
    // `RN-DS-07`: los dos colores, o ninguno.
    applyBrandPalette({
      primary: data.color_primary as string,
      primaryForeground: data.color_secondary as string,
    })
    writeCache(data.color_primary as string, data.color_secondary as string)
  } else {
    applyBrandPalette(null)
    clearCache()
  }

  // `applyFavicon(null)` restaura el favicon por defecto: cubre a la vez
  // "favicon_url o el por defecto" de la tabla de §7.2.
  applyFavicon(data.favicon_url)
}

function applyNotFound(): void {
  branding.value = null
  status.value = 'not-found'
  applyBrandPalette(null)
  clearCache()
  applyFavicon(null)
}

function applyUnavailable(): void {
  // `RN-DS-07`/§7.2: se conserva `branding` y la paleta aplicada (la de
  // la caché, si la había); no se toca la caché ni el favicon.
  status.value = 'unavailable'
}

/**
 * Distingue el `404` (host sin tenant) del resto de fallos (red, `429`,
 * `5xx`) por duck-typing sobre `status`, en vez de `instanceof ApiError`:
 * evita depender de la identidad de clase de `@/api/client`, que en test
 * puede recargarse en un registro de módulos distinto (`vi.resetModules()`)
 * del que construyó el error simulado.
 */
function isNotFoundError(err: unknown): boolean {
  return (
    typeof err === 'object' &&
    err !== null &&
    'status' in err &&
    (err as { status: unknown }).status === 404
  )
}

/**
 * Lanza (o reutiliza, si ya hay una en vuelo — `refresh()` deduplicada,
 * `CA-DS-027`) la única petición a `GET /tenant/branding`, y aplica la
 * tabla de §7.2. Nunca rechaza.
 */
function fetchAndApply(): Promise<void> {
  if (inFlight !== null) {
    return inFlight
  }

  const wasReady = status.value === 'ready'

  inFlight = (async () => {
    try {
      const data = await getTenantBranding()
      applySuccess(data)
    } catch (err) {
      if (wasReady) {
        // §7.3: un fallo de la renovación no degrada un estado `ready`
        // ya bueno — el dato que había sigue siendo válido.
        return
      }

      if (isNotFoundError(err)) {
        applyNotFound()
      } else {
        applyUnavailable()
      }
    } finally {
      inFlight = null
    }
  })()

  return inFlight
}

/**
 * Arranque (`main.ts`, §8): lanza la única petición. Resuelve cuando
 * llega la respuesta o al vencer `timeoutMs` (`BRANDING_BOOT_TIMEOUT_MS`
 * por defecto), lo que ocurra antes. Si vence el plazo, la SPA se monta
 * con lo que haya (neutros, o la paleta de la caché ya aplicada por
 * `primeBrandingFromCache()`) y la marca real se aplica en cuanto llegue
 * — la petición sigue en vuelo y `fetchAndApply()` la resuelve igual.
 * Nunca rechaza.
 */
export function bootstrapTenantBranding(options?: { timeoutMs?: number }): Promise<void> {
  const timeoutMs = options?.timeoutMs ?? BRANDING_BOOT_TIMEOUT_MS
  const fetchPromise = fetchAndApply()
  const timeoutPromise = new Promise<void>((resolve) => {
    setTimeout(resolve, timeoutMs)
  })

  return Promise.race([fetchPromise, timeoutPromise])
}

export function useTenantBranding(): {
  branding: Readonly<Ref<TenantBranding | null>>
  status: Readonly<Ref<TenantBrandingStatus>>
  refresh: () => Promise<void>
  reportAssetError: (url: string) => void
} {
  function refresh(): Promise<void> {
    return fetchAndApply()
  }

  /**
   * §7.4: caducidad de una URL firmada en una sesión larga. Lanza
   * `refresh()` una sola vez por URL para no entrar en bucle cuando el
   * fallo no es de caducidad (activo borrado, red caída).
   */
  function reportAssetError(url: string): void {
    if (reportedAssetErrorUrls.has(url)) {
      return
    }

    reportedAssetErrorUrls.add(url)
    void refresh()
  }

  return {
    branding: branding as Readonly<Ref<TenantBranding | null>>,
    status: status as Readonly<Ref<TenantBrandingStatus>>,
    refresh,
    reportAssetError,
  }
}
