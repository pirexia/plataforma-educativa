import { apiFetch } from '@/api/client'
import { buildQuery } from './shared'
import type { Paginated, PublicId, UserImport } from '../types'

export function listUserImports(
  params: { page?: number; per_page?: number } = {},
): Promise<Paginated<UserImport>> {
  const query = buildQuery({ page: params.page, per_page: params.per_page })

  return apiFetch<Paginated<UserImport>>(`/user-imports${query}`)
}

/** api.md §7. Esquema de columnas fijo, sin mapeo visual (funcional.md §1.10). */
export function createUserImport(file: File, sendInvitations = true): Promise<UserImport> {
  const body = new FormData()
  body.set('file', file)
  body.set('send_invitations', String(sendInvitations))

  return apiFetch<UserImport>('/user-imports', { method: 'POST', body })
}

export function getUserImport(publicId: PublicId): Promise<UserImport> {
  return apiFetch<UserImport>(`/user-imports/${publicId}`)
}

/**
 * ADR-038 §8: `Idempotency-Key` obligatoria, ULID generado por el
 * cliente. Este cliente no genera el ULID (`RNF-MANT-007`: sin
 * dependencia nueva) — quien llama lo aporta, p. ej. con
 * `crypto.randomUUID()` normalizado o una utilidad ya presente en la SPA.
 */
export function executeUserImport(publicId: PublicId, idempotencyKey: string): Promise<UserImport> {
  return apiFetch<UserImport>(`/user-imports/${publicId}/execute`, {
    method: 'POST',
    headers: { 'Idempotency-Key': idempotencyKey },
  })
}

/** Descarta un lote no ejecutado; 409 si ya se ejecutó o se está ejecutando. */
export function deleteUserImport(publicId: PublicId): Promise<void> {
  return apiFetch<void>(`/user-imports/${publicId}`, { method: 'DELETE' })
}
