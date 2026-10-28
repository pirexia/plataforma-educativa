/**
 * REQ-FAM-UNIT (paso 1.14-c, `api.md §2.8`, `ADR-067`). Superficie pública del cliente del módulo: un componente importa
 * desde aquí, nunca de `@/api/client` con rutas escritas a mano. Solo el autoservicio del muro de condiciones; las
 * pantallas de personal son de `1.14-d`.
 */
import { apiFetch } from '@/api/client'
import type { MyTerms, PublicId, TermsAcceptance } from '../types'

/** `GET /me/terms`: exento del muro de condiciones (L3). Sin permiso: por identidad. */
export function getMyTerms(): Promise<MyTerms> {
  return apiFetch<MyTerms>('/me/terms')
}

/** `POST /me/terms-acceptances` (`201`, o `200` si ya existía, `RN-FAM-UNIT-64`). */
export function acceptMyTerms(termsVersion: PublicId): Promise<TermsAcceptance> {
  return apiFetch<TermsAcceptance>('/me/terms-acceptances', {
    method: 'POST',
    body: JSON.stringify({ terms_version: termsVersion }),
  })
}
