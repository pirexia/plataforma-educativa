/**
 * `docs/modulos/REQ-PERM/funcional.md §20.13`, `RN-PERM-27`/`-28`, `ADR-038 §3.2`/`§7.3`.
 * Vocabulario cerrado de los permisos traducido **en el cliente**
 * (`core.permissions.vocabulary.*`, en `es`, `en`, `de` y `fr`): las nueve
 * acciones de `RPERM-003`, los seis ámbitos de `RPERM-004`, los dos efectos, las
 * dos decisiones y los motivos de inercia. **El valor técnico nunca se traduce**
 * (`ADR-038 §3.2`): se envía y se compara tal cual; solo se pinta traducido.
 *
 * Todo valor desconocido —un enumerado de respuesta es extensible, `ADR-038
 * §7.3`— se pinta **en crudo** (rama por defecto) antes que fallar o desaparecer.
 *
 * La etiqueta de un **recurso** no está aquí: la traduce el módulo dueño en el
 * servidor (`resource_label`, S-PERM-2) y nunca hay en `core` un catálogo de
 * recursos de otros módulos (`INV-007`, `RN-PERM-28`).
 */
import { i18n, useT } from '@/i18n'

/** `RPERM-003`, en el orden del requisito (también el orden de las columnas de la matriz, `§20.8.1`). */
export const ACTIONS = [
  'crear',
  'leer',
  'actualizar',
  'eliminar',
  'exportar',
  'importar',
  'aprobar',
  'firmar',
  'publicar',
] as const

/** `RN-PERM-01`: vocabulario cerrado de ámbitos, en el orden del vocabulario (`§20.8.3`). */
export const SCOPES = [
  'todos',
  'propios',
  'departamento',
  'grupo',
  'clase',
  'unidad_familiar',
] as const

export const EFFECTS = ['allow', 'deny'] as const
export const DECISIONS = ['permitido', 'denegado'] as const

/** `api.md §7.2`: cuatro motivos hoy; enumerado extensible. */
export const INERT_REASONS = [
  'inerte_permiso_retirado',
  'inerte_modulo',
  'inerte_datos_especiales',
  'inerte_sin_resolutor',
] as const

function rank(order: readonly string[], value: string): number {
  const index = order.indexOf(value)

  return index === -1 ? order.length : index
}

/** Posición de una acción en `RPERM-003`; las desconocidas, después de las nueve. */
export function actionRank(action: string): number {
  return rank(ACTIONS, action)
}

/** Posición de un ámbito en el vocabulario; los desconocidos, después de los seis. */
export function scopeRank(scope: string): number {
  return rank(SCOPES, scope)
}

/** Orden de dominio de las acciones (`RN-PERM-44` E3 `compare`): conocidas en orden de `RPERM-003`, el resto alfabético. */
export function compareActions(a: string, b: string): number {
  const byRank = actionRank(a) - actionRank(b)

  return byRank !== 0 ? byRank : a.localeCompare(b)
}

export function usePermissionVocabulary() {
  const t = useT()

  /** Etiqueta traducida o, sin traducción, el valor en crudo (`ADR-038 §7.3`). */
  function labelOf(group: string, value: string): string {
    const key = `core.permissions.vocabulary.${group}.${value}`

    return i18n.global.te(key) ? t(key) : value
  }

  return {
    action: (value: string): string => labelOf('actions', value),
    scope: (value: string): string => labelOf('scopes', value),
    effect: (value: string): string => labelOf('effects', value),
    decision: (value: string): string => labelOf('decisions', value),
    inertReason: (value: string): string => labelOf('inertReasons', value),
    /** `RN-PERM-28`: `resource_label` tal como llega; sin él, el código del recurso en crudo. */
    resource: (resource: string, resourceLabel?: string | null): string =>
      resourceLabel !== undefined && resourceLabel !== null && resourceLabel !== ''
        ? resourceLabel
        : resource,
  }
}
