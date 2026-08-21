/**
 * REQ-CORE (paso 1.1). Tipos de los recursos del módulo, en la forma
 * exacta en que la API los entrega (ADR-038 §3: snake_case, sin mapeo a
 * camelCase — un mapeo más que mantener y una fuente de fallos
 * silenciosos al añadir un campo). Coherentes con
 * apps/api/openapi/{components,paths/core}.yaml, que es la fuente de
 * verdad (INV-006: el contrato existe en OpenAPI antes que aquí).
 *
 * funcional.md §1.11: 1.1 es solo API. Este módulo no tiene `views/` ni
 * `components/` todavía — estos tipos los consumirá el paso 1.8.
 */

/** ADR-029: ULID. Nunca la clave interna `bigint`. */
export type PublicId = string

/** ADR-021. */
export type Locale = 'es-ES' | 'en' | 'de' | 'fr'

/**
 * ADR-038 §6. `errors` ya trae `message` traducido por el servidor: la
 * SPA no mantiene un catálogo propio de mensajes de validación (§6.3).
 */
export interface ApiProblemBody {
  type: string
  title: string
  status: number
  detail?: string
  instance?: string
  request_id?: string
  errors?: Record<string, ProblemErrorEntry[]>
}

export interface ProblemErrorEntry {
  code: string
  message: string
  params?: Record<string, unknown>
}

/** ADR-038 §4.3. Catálogos de entidades. */
export interface PageMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

/** ADR-038 §4.4. Flujos de eventos append-only (p. ej. auditoría). */
export interface CursorMeta {
  next_cursor: string | null
  has_more: boolean
}

export interface Paginated<T> {
  data: T[]
  meta: PageMeta
}

export interface CursorPaginated<T> {
  data: T[]
  meta: CursorMeta
}

export interface Person {
  public_id: PublicId
  given_name: string
  family_name_1: string
  family_name_2: string | null
  contact_email: string | null
  contact_phone: string | null
  document_type: string | null
  document_number: string | null
  birth_date: string | null
  locale: Locale
}

/**
 * ADR-038 §7.3: todo enumerado de respuesta es extensible. Un valor
 * nuevo no debe romper un `switch` — trátese siempre con rama por
 * defecto, mostrando el código en crudo antes que fallar.
 */
export type UserStatus = 'pendiente' | 'activo' | 'inactivo'

export interface RoleSummary {
  public_id: PublicId
  code: string
  name: string
}

export interface User {
  public_id: PublicId
  email: string
  status: UserStatus
  person: Person
  roles: RoleSummary[]
  email_verified_at: string | null
  created_at: string
  updated_at: string
  deleted_at: string | null
}

export interface CreatedUser extends User {
  invitation?: { public_id: PublicId; expires_at: string } | null
}

export type InvitationStatus = 'vigente' | 'caducada' | 'revocada' | 'aceptada'

export interface Invitation {
  public_id: PublicId
  user: { public_id: PublicId; email: string }
  status: InvitationStatus
  expires_at: string
  created_at: string
  accepted_at: string | null
  revoked_at: string | null
}

export interface RolePermission {
  code: string
  resource: string
  action: string
  effect: 'allow' | 'deny'
  scope: string
}

export interface Role {
  public_id: PublicId
  code: string
  name: string
  is_system: boolean
  mfa_required: boolean
  special_data_access: boolean
  users_count?: number
  permissions?: RolePermission[]
}

export interface Permission {
  code: string
  resource: string
  action: string
  module_code: string
  is_special_category: boolean
  retired_at: string | null
}

export interface ModuleSubscription {
  public_id: PublicId | null
  module_code: string
  name: string
  phase: string
  enabled: boolean
  enabled_at: string | null
  disabled_at: string | null
  settings: Record<string, unknown>
}

export interface TenantSettings {
  public_id: PublicId
  regional: {
    default_locale: Locale
    active_locales: Locale[]
    timezone: string
    currency: string
    autonomous_community: string | null
  }
  fiscal: {
    legal_name: string | null
    tax_id: string | null
    address: string | null
    postal_code: string | null
    city: string | null
    province: string | null
    country_code: string | null
  }
  branding: {
    color_primary: string | null
    color_secondary: string | null
    logo_url: string | null
    favicon_url: string | null
    login_background_url: string | null
  }
  updated_at: string
}

/** funcional.md §4.8. Único endpoint sin sesión del módulo — no devuelve nada más que esto. */
export interface TenantBranding {
  name: string
  color_primary: string | null
  color_secondary: string | null
  logo_url: string | null
  favicon_url: string | null
  login_background_url: string | null
  default_locale: Locale
  active_locales: Locale[]
}

export interface Tenant {
  public_id: PublicId
  slug: string
  name: string
  status: string
}

export type AuditActorType = 'user' | 'system' | 'console' | 'import' | 'platform'
export type AuditEvent = 'created' | 'updated' | 'deleted' | 'restored' | 'read' | 'exported'

export interface AuditLog {
  public_id: PublicId
  occurred_at: string
  actor: { public_id: PublicId; display_name: string } | null
  actor_type: AuditActorType
  auditable_type: string
  auditable_public_id: PublicId
  event: AuditEvent
  changes: Record<string, unknown> | null
  ip_address: string | null
  user_agent: string | null
  request_id: string | null
}

export type DataExportStatus = 'pendiente' | 'generando' | 'completada' | 'fallida'

export interface DataExport {
  public_id: PublicId
  kind: string
  status: DataExportStatus
  row_count: number | null
  download_url: string | null
  expires_at: string
}

export type UserImportStatus =
  'subido' | 'validando' | 'validado' | 'fallido' | 'ejecutando' | 'completado'

export interface UserImportErrorEntry {
  line: number
  column: string
  code: string
  message: string
}

export interface UserImport {
  public_id: PublicId
  original_filename: string
  status: UserImportStatus
  row_count: number | null
  error_count: number | null
  created_count: number | null
  error_summary: UserImportErrorEntry[] | null
  report_url: string | null
  validated_at: string | null
  executed_at: string | null
}

export type BrandingAssetKind = 'logo' | 'favicon' | 'login-background'
