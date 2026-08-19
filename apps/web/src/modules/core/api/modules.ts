import { apiFetch } from '@/api/client'
import type { ModuleSubscription, PublicId } from '../types'

export function listModules(): Promise<{ data: ModuleSubscription[] }> {
  return apiFetch<{ data: ModuleSubscription[] }>('/modules')
}

/**
 * Solo `settings` — `enabled` no es modificable por esta vía en 1.1
 * (funcional.md §2, `OPEN-CORE-03`). Enviarlo produce `422`.
 */
export function updateModuleSubscriptionSettings(
  publicId: PublicId,
  settings: Record<string, unknown>,
): Promise<ModuleSubscription> {
  return apiFetch<ModuleSubscription>(`/module-subscriptions/${publicId}`, {
    method: 'PATCH',
    body: JSON.stringify({ settings }),
  })
}
