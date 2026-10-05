/**
 * `docs/modulos/REQ-PERM/funcional.md §20.17.4`, `CA-PERM-136` (§20.8.1,
 * `RN-CORE-44`, `RN-CORE-53` modificada): la rejilla de edición de concesiones —
 * una matriz por módulo con `caption`, columnas = acciones presentes en el módulo
 * en el orden de `RPERM-003`, `th scope="col"`/`th scope="row"`, celda inexistente =
 * valor vacío común sin control, una celda con permiso = un único botón con
 * nombre accesible, sin `role="grid"` ni tabla HTML cruda. Más el modo de solo
 * lectura de un rol del sistema (`CA-PERM-117`) y la construcción de la matriz a
 * partir del catálogo (`buildGrid`).
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'
import { buildGrid } from '../../rolePermissionGrid'
import type { Permission } from '../../types'
import RolePermissionMatrix, {
  type MatrixCellView,
  type MatrixModuleView,
} from './RolePermissionMatrix.vue'

const wrappers: VueWrapper[] = []

function cell(code: string, stateText: string, marks: string[] = [], error: string | null = null) {
  return {
    code,
    name: `${code}: ${stateText}. Modificar`,
    stateText,
    marks,
    error,
  } satisfies MatrixCellView
}

const MODULES: MatrixModuleView[] = [
  {
    code: 'core',
    title: 'core',
    caption: 'Concesiones de core para Coordinación',
    scrollLabel: 'Matriz de concesiones de core, desplazable',
    notice: null,
    actions: [
      { id: 'crear', label: 'Crear' },
      { id: 'leer', label: 'Leer' },
      { id: 'exportar', label: 'Exportar' },
    ],
    rows: [
      {
        resource: 'auditoria',
        label: 'Auditoría',
        special: false,
        cells: {
          leer: cell('auditoria.leer', 'Permitir · Propios', ['Modificado']),
          exportar: cell('auditoria.exportar', 'Sin conceder'),
        },
      },
      {
        resource: 'salud',
        label: 'Salud',
        special: true,
        cells: { leer: cell('salud.leer', 'Denegar', [], 'No puedes') },
      },
    ],
  },
  {
    code: 'auth',
    title: 'Autenticación',
    caption: 'Concesiones de Autenticación para Coordinación',
    scrollLabel: 'Matriz de concesiones de Autenticación, desplazable',
    notice: 'Módulo no contratado: estas concesiones no surten efecto mientras no se contrate',
    actions: [{ id: 'leer', label: 'Leer' }],
    rows: [
      {
        resource: 'mfa',
        label: 'MFA',
        special: false,
        cells: { leer: cell('mfa.leer', 'Sin conceder') },
      },
    ],
  },
]

function mountMatrix(props: { readonly?: boolean } = {}) {
  const wrapper = mount(RolePermissionMatrix, {
    props: { modules: MODULES, ...props },
    global: { plugins: [i18n] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)

  return wrapper
}

beforeEach(() => {
  setLocale('es')
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-PERM-136 (§20.8.1, RN-CORE-44): estructura de la rejilla', () => {
  it('hay una tabla por módulo, cada una con su caption, y no hay role="grid"', () => {
    const wrapper = mountMatrix()

    expect(wrapper.findAll('table')).toHaveLength(2)
    expect(wrapper.findAll('caption').map((caption) => caption.text())).toEqual([
      'Concesiones de core para Coordinación',
      'Concesiones de Autenticación para Coordinación',
    ])
    expect(wrapper.find('[role="grid"]').exists()).toBe(false)
  })

  it('las columnas son las acciones del módulo, con th scope="col"; el recurso es th scope="row"', () => {
    const wrapper = mountMatrix()
    const first = wrapper.findAll('table')[0]!

    const headers = first.findAll('thead th')

    expect(headers.map((th) => th.text())).toEqual(['Recurso', 'Crear', 'Leer', 'Exportar'])
    expect(headers.every((th) => th.attributes('scope') === 'col')).toBe(true)

    const rowHeaders = first.findAll('tbody th')

    expect(rowHeaders[0]!.text()).toBe('Auditoría')
    expect(rowHeaders[1]!.text()).toContain('Salud')
    expect(rowHeaders[1]!.text()).toContain('Categoría especial')
    expect(rowHeaders.every((th) => th.attributes('scope') === 'row')).toBe(true)
    expect(
      wrapper
        .findAll('table')[1]!
        .findAll('thead th')
        .map((th) => th.text()),
    ).toEqual(['Recurso', 'Leer'])
  })

  it('un par recurso × acción inexistente lleva el valor vacío común y ningún control', () => {
    const wrapper = mountMatrix()
    const auditoria = wrapper.findAll('table')[0]!.findAll('tbody tr')[0]!
    const cells = auditoria.findAll('td')

    // crear no existe para auditoría
    expect(cells[0]!.find('[data-slot="data-table-empty-value"]').exists()).toBe(true)
    expect(cells[0]!.find('[data-slot="data-table-empty-value"] .sr-only').text()).toBe('Sin valor')
    expect(cells[0]!.find('button').exists()).toBe(false)
    expect(cells[1]!.find('[data-slot="data-table-empty-value"]').exists()).toBe(false)
  })

  it('cada celda con permiso tiene un único botón cuyo nombre incluye recurso, acción y estado, con marcas en texto', () => {
    const wrapper = mountMatrix()
    const buttons = wrapper.findAll('tbody button')

    expect(buttons).toHaveLength(4)

    const read = wrapper.get('button[data-code="auditoria.leer"]')

    expect(read.attributes('aria-label')).toBe('auditoria.leer: Permitir · Propios. Modificar')
    expect(read.text()).toContain('Permitir · Propios')
    expect(read.text()).toContain('Modificado')

    for (const td of wrapper.findAll('tbody td')) {
      expect(td.findAll('button').length).toBeLessThanOrEqual(1)
    }
  })

  it('el error de una celda es texto asociado al botón con aria-describedby', () => {
    const wrapper = mountMatrix()
    const button = wrapper.get('button[data-code="salud.leer"]')
    const id = button.attributes('aria-describedby')!

    expect(id).toBe('matrix-cell-error-salud.leer')
    expect(document.getElementById(id)!.textContent).toContain('No puedes')
  })

  it('el contenedor de cada matriz es una región enfocable con nombre accesible, y el recurso queda fijo', () => {
    const wrapper = mountMatrix()
    const regions = wrapper.findAll('[role="region"]')

    expect(regions).toHaveLength(2)
    expect(regions.map((region) => region.attributes('aria-label'))).toEqual([
      'Matriz de concesiones de core, desplazable',
      'Matriz de concesiones de Autenticación, desplazable',
    ])
    expect(regions.every((region) => region.attributes('tabindex') === '0')).toBe(true)
    expect(wrapper.get('tbody th').classes()).toContain('sticky')
  })

  it('el aviso de módulo no contratado se pinta como texto encima de la matriz', () => {
    const wrapper = mountMatrix()

    expect(wrapper.findAll('[data-slot="role-permission-matrix-notice"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Módulo no contratado')
  })

  it('un clic en el botón de una celda emite edit con su código', async () => {
    const wrapper = mountMatrix()

    await wrapper.get('button[data-code="mfa.leer"]').trigger('click')

    expect(wrapper.emitted('edit')).toEqual([['mfa.leer']])
  })
})

describe('CA-PERM-117 (OPEN-PERM-10 = B): rol del sistema, solo lectura', () => {
  it('las celdas son texto: ningún botón y el mismo contenido', () => {
    const wrapper = mountMatrix({ readonly: true })

    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.text()).toContain('Permitir · Propios')
    expect(wrapper.find('[data-code="auditoria.leer"]').exists()).toBe(true)
  })
})

describe('buildGrid (§20.8.1): una matriz por módulo, filas por etiqueta, acciones presentes', () => {
  function perm(
    module_code: string,
    resource: string,
    action: string,
    extra: Partial<Permission> = {},
  ): Permission {
    return {
      code: `${resource}.${action}`,
      resource,
      action,
      module_code,
      is_special_category: false,
      applicable_scopes: ['todos'],
      grantable_scopes: ['todos'],
      retired_at: null,
      ...extra,
    }
  }

  const catalog = [
    perm('core', 'usuario', 'eliminar', { resource_label: 'Usuarios' }),
    perm('core', 'auditoria', 'leer', { resource_label: 'Auditoría' }),
    perm('core', 'usuario', 'crear', { resource_label: 'Usuarios' }),
    perm('core', 'salud', 'leer', { is_special_category: true }),
    perm('auth', 'mfa', 'publicar'),
    perm('auth', 'mfa', 'archivar'),
  ]

  it('agrupa por módulo en el orden del catálogo y ordena las acciones por RPERM-003 (las desconocidas, al final)', () => {
    const grid = buildGrid(catalog, 'es')

    expect(grid.map((module) => module.code)).toEqual(['core', 'auth'])
    expect(grid[0]!.actions).toEqual(['crear', 'leer', 'eliminar'])
    expect(grid[1]!.actions).toEqual(['publicar', 'archivar'])
  })

  it('ordena las filas por etiqueta con el idioma activo; sin resource_label, el código en crudo; y marca la categoría especial', () => {
    const rows = buildGrid(catalog, 'es')[0]!.rows

    expect(rows.map((row) => row.label)).toEqual(['Auditoría', 'salud', 'Usuarios'])
    expect(rows.find((row) => row.resource === 'salud')!.special).toBe(true)
    expect(rows.find((row) => row.resource === 'usuario')!.cells.has('leer')).toBe(false)
  })
})
