/**
 * `docs/modulos/REQ-PERM/funcional.md §20.8.1`. Estructura de la matriz de
 * concesiones a partir del catálogo `GET /permissions`: **una matriz por módulo**
 * (en el orden del catálogo), un recurso por fila (ordenadas por su etiqueta con
 * `Intl.Collator` del idioma activo) y una columna por cada acción de `RPERM-003`
 * **que existe en algún permiso del módulo**. Un par recurso × acción que no
 * existe en el catálogo no tiene celda. Funciones puras.
 */
import { compareActions } from './permissionVocabulary'
import type { Permission } from './types'

export interface GridRow {
  /** Código técnico del recurso (`usuario`). */
  resource: string
  /** `resource_label` del servidor o, sin él, el código en crudo (`RN-PERM-28`). */
  label: string
  /** Algún permiso del recurso es de categoría especial (`RPERM-012`). */
  special: boolean
  /** Permiso por acción; sin entrada = el par no existe en el catálogo. */
  cells: Map<string, Permission>
}

export interface GridModule {
  /** Código del módulo dueño (`core`, `auth`…). */
  moduleCode: string
  /** Acciones presentes en el módulo, en el orden de `RPERM-003`. */
  actions: string[]
  rows: GridRow[]
}

export function buildGrid(catalog: readonly Permission[], locale: string): GridModule[] {
  const collator = new Intl.Collator(locale, { sensitivity: 'base', numeric: true })
  const modules = new Map<string, Map<string, GridRow>>()

  for (const permission of catalog) {
    const rows = modules.get(permission.module_code) ?? new Map<string, GridRow>()

    modules.set(permission.module_code, rows)

    const row = rows.get(permission.resource) ?? {
      resource: permission.resource,
      label:
        permission.resource_label !== undefined && permission.resource_label !== ''
          ? permission.resource_label
          : permission.resource,
      special: false,
      cells: new Map<string, Permission>(),
    }

    row.special = row.special || permission.is_special_category
    row.cells.set(permission.action, permission)
    rows.set(permission.resource, row)
  }

  return [...modules.entries()].map(([code, rows]) => {
    const actions = [...new Set([...rows.values()].flatMap((row) => [...row.cells.keys()]))].sort(
      compareActions,
    )

    return {
      moduleCode: code,
      actions,
      rows: [...rows.values()].sort(
        (a, b) => collator.compare(a.label, b.label) || collator.compare(a.resource, b.resource),
      ),
    }
  })
}
