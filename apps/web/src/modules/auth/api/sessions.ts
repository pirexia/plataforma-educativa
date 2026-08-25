import { apiFetch } from '@/api/client'
import type { PublicId } from '../types'

/**
 * api.md §B.2. `client.device_type` es un enumerado en el idioma del
 * dominio del código (ADR-038 §3.2) — se traduce por catálogo en el
 * cliente, nunca cambiando el valor (api.md §9.5, funcional.md §B.11).
 * `location` es siempre `null` en 1.2b (RN-AUTH-47): tratarlo como
 * "desconocida", nunca como error ni como campo ausente (api.md §B.7.3).
 */
export interface UserSessionSummary {
  public_id: PublicId
  current: boolean
  started_at: string
  last_activity_at: string
  ip_address: string | null
  client: {
    browser: string
    platform: string
    device_type: 'escritorio' | 'movil' | 'tableta' | 'bot' | 'desconocido'
  }
  location: string | null
  device_known: boolean
}

export interface UserSessionsPage {
  data: UserSessionSummary[]
  meta: { current_page: number; per_page: number; total: number; last_page: number }
}

/**
 * api.md §B.2. Sin permiso — por identidad del portador de la cookie.
 * Solo las sesiones activas del usuario autenticado.
 */
export function listSessions(): Promise<UserSessionsPage> {
  return apiFetch<UserSessionsPage>('/auth/sessions')
}

/**
 * api.md §B.3. Revocar la sesión actual está permitido y equivale a un
 * logout (funcional.md §B.4.3 punto 7) — la vista, que conoce cuál es
 * `current`, decide si redirige tras la llamada.
 */
export function revokeSession(publicId: PublicId): Promise<void> {
  return apiFetch<void>(`/auth/sessions/${publicId}`, { method: 'DELETE' })
}

/**
 * api.md §B.4. `scope=others` (por defecto en el servidor, explícito
 * aquí): cierra todas las sesiones salvo la actual. Es el botón de
 * "cerrar las demás sesiones" del panel.
 */
export function revokeOtherSessions(): Promise<void> {
  return apiFetch<void>('/auth/sessions?scope=others', { method: 'DELETE' })
}
