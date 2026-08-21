import { apiFetch } from '@/api/client'
import type { BrandingAssetKind, Tenant, TenantBranding, TenantSettings } from '../types'

export function getTenant(): Promise<Tenant> {
  return apiFetch<Tenant>('/tenant')
}

/** funcional.md §4.8. Sin sesión — se llama antes de que exista una. */
export function getTenantBranding(): Promise<TenantBranding> {
  return apiFetch<TenantBranding>('/tenant/branding')
}

export function getTenantSettings(): Promise<TenantSettings> {
  return apiFetch<TenantSettings>('/tenant/settings')
}

export interface UpdateTenantSettingsPayload {
  regional?: Partial<{
    default_locale: string
    active_locales: string[]
    timezone: string
    currency: string
    autonomous_community: string
  }>
  fiscal?: Partial<{
    legal_name: string
    tax_id: string
    address: string
    postal_code: string
    city: string
    province: string
    country_code: string
  }>
  branding?: Partial<{ color_primary: string; color_secondary: string }>
}

/**
 * ADR-038 §9.2: fusión parcial. Una clave ausente en `payload` no toca
 * el campo; para vaciar uno anulable, envía explícitamente `null` en la
 * clave (nunca `""`).
 */
export function updateTenantSettings(
  payload: UpdateTenantSettingsPayload,
): Promise<TenantSettings> {
  return apiFetch<TenantSettings>('/tenant/settings', {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export interface PutBrandingAssetResult {
  kind: BrandingAssetKind
  url: string
}

/** api.md §2.2. El navegador fija el `Content-Type` con su `boundary` — el cliente nunca lo declara a mano. */
export function putTenantSettingsAsset(
  kind: BrandingAssetKind,
  file: File,
): Promise<PutBrandingAssetResult> {
  const body = new FormData()
  body.set('file', file)

  return apiFetch<PutBrandingAssetResult>(`/tenant/settings/assets/${kind}`, {
    method: 'PUT',
    body,
  })
}

export function deleteTenantSettingsAsset(kind: BrandingAssetKind): Promise<void> {
  return apiFetch<void>(`/tenant/settings/assets/${kind}`, { method: 'DELETE' })
}
