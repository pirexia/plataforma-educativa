/**
 * `docs/modulos/REQ-CURSO/funcional.md §10.1`, `§13.5` (1.10): `CA-CURSO-080`
 * (registro del módulo web, `AR-11`: lo vigila además
 * `src/modules/architecture.spec.ts` sin tocarlo) y `CA-CURSO-081` (entrada de
 * menú y ruta por permiso, `ADR-053 §3`).
 */
import { describe, expect, it } from 'vitest'
import router from '@/router'
import { moduleShells } from '@/navigation/modules'
import { visibleNavigationEntries } from '@/navigation/registry'
import { shell } from './shell'

/** Columna 3 de `funcional.md §10.1`. */
const EXPECTED: Record<string, { path: string; permissions: string[] }> = {
  'curso-academic-years': { path: '/administracion/cursos', permissions: ['curso_academico.leer'] },
  'curso-academic-year-new': {
    path: '/administracion/cursos/nuevo',
    permissions: ['curso_academico.crear'],
  },
  'curso-academic-year-detail': {
    path: '/administracion/cursos/:publicId',
    permissions: ['curso_academico.leer'],
  },
  'curso-academic-year-edit': {
    path: '/administracion/cursos/:publicId/editar',
    permissions: ['curso_academico.actualizar'],
  },
}

describe('CA-CURSO-080 (AR-11, ADR-053 §2): registro del módulo', () => {
  it('curso está registrado en el registro de módulos de la navegación', () => {
    expect(moduleShells).toContain(shell)
  })

  it.each(Object.entries(EXPECTED))(
    '%s existe con layout app y el permiso de funcional.md §10.1',
    (name, expected) => {
      const route = router.getRoutes().find((candidate) => candidate.name === name)

      expect(route, name).toBeDefined()
      expect(route!.path).toBe(expected.path)
      expect(route!.meta.layout).toBe('app')
      expect(route!.meta.permissions).toEqual(expected.permissions)
    },
  )

  it('ninguna ruta de curso usa permissions: [] (la lista cerrada de RN-CORE-24 no cambia)', () => {
    const empty = shell.routes.filter(
      (route) => (route.meta as { permissions?: string[] }).permissions?.length === 0,
    )

    expect(empty).toEqual([])
    expect(shell.routes).toHaveLength(4)
  })

  it('las pantallas secundarias cuelgan del listado o de la ficha en la miga de pan y sus títulos están en curso.*', () => {
    const parents: Record<string, string> = {
      'curso-academic-year-new': 'curso-academic-years',
      'curso-academic-year-detail': 'curso-academic-years',
      'curso-academic-year-edit': 'curso-academic-year-detail',
    }

    for (const [name, parent] of Object.entries(parents)) {
      const route = router.getRoutes().find((candidate) => candidate.name === name)!

      expect(route.meta.breadcrumbParent, name).toBe(parent)
      expect(route.meta.titleKey, name).toMatch(/^curso\./)
    }
  })

  it('una sola entrada de menú, en administración, con icono y sin acceso directo', () => {
    expect(shell.navigation).toHaveLength(1)
    expect(shell.navigation[0]).toMatchObject({
      id: 'curso.academicYears',
      route: 'curso-academic-years',
      labelKey: 'curso.nav.academicYears',
      section: 'administracion',
      shortcut: false,
    })
    expect(shell.navigation[0]!.icon).toBeTruthy()
    expect(shell.dashboardBlocks).toEqual([])
  })
})

describe('CA-CURSO-081 (ADR-053 §3): la entrada «Cursos académicos» aparece solo con curso_academico.leer', () => {
  function ids(permissions: string[]): string[] {
    return visibleNavigationEntries(router, permissions)
      .filter((entry) => entry.section === 'administracion')
      .map((entry) => entry.id)
  }

  it('sin curso_academico.leer no aparece (ni con los otros permisos del módulo)', () => {
    expect(ids([])).not.toContain('curso.academicYears')
    expect(
      ids([
        'curso_academico.crear',
        'curso_academico.actualizar',
        'estado_curso_academico.actualizar',
      ]),
    ).not.toContain('curso.academicYears')
  })

  it('con curso_academico.leer aparece', () => {
    expect(ids(['curso_academico.leer'])).toEqual(['curso.academicYears'])
  })
})
