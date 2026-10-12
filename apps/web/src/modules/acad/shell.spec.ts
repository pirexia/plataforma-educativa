/**
 * `docs/modulos/REQ-ACAD/funcional.md §10.1`, `§13.9` (1.11-b): `CA-ACAD-044`
 * (registro del módulo web, `AR-11`: lo vigila además
 * `src/modules/architecture.spec.ts` sin tocarlo) y `CA-ACAD-045` (entrada de
 * menú y ruta por permiso, `ADR-053 §3`, `RMOD-008`).
 */
import { describe, expect, it } from 'vitest'
import router from '@/router'
import { moduleShells } from '@/navigation/modules'
import { visibleNavigationEntries } from '@/navigation/registry'
import { shell } from './shell'

/** Columna 3 de `funcional.md §10.1`. */
const EXPECTED: Record<string, string> = {
  'acad-structure': '/academico/estructura',
  'acad-level-detail': '/academico/etapas/:publicId',
  'acad-course-detail': '/academico/cursos/:publicId',
  'acad-group-detail': '/academico/grupos/:publicId',
  'acad-subject-detail': '/academico/asignaturas/:publicId',
}

describe('CA-ACAD-044 (AR-11, ADR-053 §2): registro del módulo', () => {
  it('acad está registrado en el registro de módulos de la navegación', () => {
    expect(moduleShells).toContain(shell)
  })

  it.each(Object.entries(EXPECTED))(
    '%s existe con layout app y estructura_academica.leer',
    (name, path) => {
      const route = router.getRoutes().find((candidate) => candidate.name === name)

      expect(route, name).toBeDefined()
      expect(route!.path).toBe(path)
      expect(route!.meta.layout).toBe('app')
      expect(route!.meta.permissions).toEqual(['estructura_academica.leer'])
      expect(route!.meta.academicYearScoped).toBe(true)
    },
  )

  it('ninguna ruta de acad usa permissions: [] (la lista cerrada de RN-CORE-24 no cambia)', () => {
    const empty = shell.routes.filter(
      (route) => (route.meta as { permissions?: string[] }).permissions?.length === 0,
    )

    expect(empty).toEqual([])
    expect(shell.routes).toHaveLength(5)
  })

  it('las fichas cuelgan de la estructura en la miga de pan y sus títulos están en acad.*', () => {
    for (const route of shell.routes) {
      expect(route.meta!.titleKey, String(route.name)).toMatch(/^acad\./)

      if (route.name !== 'acad-structure') {
        expect(route.meta!.breadcrumbParent, String(route.name)).toBe('acad-structure')
      }
    }
  })

  it('una sola entrada de menú, en administración, con icono y sin acceso directo (OPEN-ACAD-23)', () => {
    expect(shell.navigation).toHaveLength(1)
    expect(shell.navigation[0]).toMatchObject({
      id: 'acad.structure',
      route: 'acad-structure',
      labelKey: 'acad.nav.structure',
      section: 'administracion',
      shortcut: false,
    })
    expect(shell.navigation[0]!.icon).toBeTruthy()
    expect(shell.dashboardBlocks).toEqual([])
  })
})

describe('CA-ACAD-045 (ADR-053 §3, RMOD-008): la entrada aparece solo con estructura_academica.leer', () => {
  function ids(permissions: string[]): string[] {
    return visibleNavigationEntries(router, permissions)
      .filter((entry) => entry.section === 'administracion')
      .map((entry) => entry.id)
  }

  it('sin estructura_academica.leer no aparece (ni con los otros permisos del módulo)', () => {
    expect(ids([])).not.toContain('acad.structure')
    expect(
      ids([
        'estructura_academica.crear',
        'estructura_academica.actualizar',
        'estructura_academica.eliminar',
      ]),
    ).not.toContain('acad.structure')
  })

  it('con estructura_academica.leer aparece', () => {
    expect(ids(['estructura_academica.leer'])).toContain('acad.structure')
  })

  it('con acad descontratado /me no trae sus permisos (inertes): la entrada desaparece', () => {
    // RMOD-008: los permisos de un módulo no contratado no llegan a `/me.permissions`.
    expect(ids(['curso_academico.leer'])).not.toContain('acad.structure')
  })
})
