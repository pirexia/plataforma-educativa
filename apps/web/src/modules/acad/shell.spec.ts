/**
 * `docs/modulos/REQ-ACAD/funcional.md §10.1`, `§13.9` (1.11-b): `CA-ACAD-044`
 * (registro del módulo web, `AR-11`: lo vigila además
 * `src/modules/architecture.spec.ts` sin tocarlo) y `CA-ACAD-045` (entrada de
 * menú y ruta por permiso, `ADR-053 §3`, `RMOD-008`). `1.11d-b`
 * (`§10.5.1`, `§13.16`): `CA-ACAD-098` — las cuatro rutas de escalas declaran
 * `configuracion_academica.<acción>` y la entrada «Escalas de calificación»
 * aparece solo con `configuracion_academica.leer`. `REQ-ACAD-001`.
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

/** `§10.5.1`: las cuatro rutas de escalas y su permiso (`RN-ACAD-50`). */
const EXPECTED_SCALES: Record<string, [string, string, boolean]> = {
  'acad-grading-scales': ['/academico/escalas', 'configuracion_academica.leer', true],
  'acad-grading-scale-new': ['/academico/escalas/nueva', 'configuracion_academica.crear', true],
  'acad-grading-scale-detail': [
    '/academico/escalas/:publicId',
    'configuracion_academica.leer',
    true,
  ],
  'acad-grading-scale-edit': [
    '/academico/escalas/:publicId/editar',
    'configuracion_academica.actualizar',
    true,
  ],
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
    expect(shell.routes).toHaveLength(13)
  })

  it('las fichas cuelgan de la estructura en la miga de pan y sus títulos están en acad.*', () => {
    for (const route of shell.routes) {
      expect(route.meta!.titleKey, String(route.name)).toMatch(/^acad\./)

      if (String(route.name).startsWith('acad-grading-scale-')) {
        expect(route.meta!.breadcrumbParent, String(route.name)).toBe('acad-grading-scales')
      } else if (
        route.name !== 'acad-structure' &&
        route.name !== 'acad-grading-scales' &&
        route.name !== 'acad-facilities' &&
        route.name !== 'acad-timetable' &&
        route.name !== 'acad-bookings'
      ) {
        expect(route.meta!.breadcrumbParent, String(route.name)).toBe('acad-structure')
      }
    }
  })

  it('cinco entradas de menú, en administración, con icono y sin acceso directo (OPEN-ACAD-23)', () => {
    expect(shell.navigation).toHaveLength(5)
    expect(shell.navigation[0]).toMatchObject({
      id: 'acad.structure',
      route: 'acad-structure',
      labelKey: 'acad.nav.structure',
      section: 'administracion',
      shortcut: false,
    })
    // `§10.5.1`: «Escalas de calificación», tras «Estructura académica».
    expect(shell.navigation[1]).toMatchObject({
      id: 'acad.scales',
      route: 'acad-grading-scales',
      labelKey: 'acad.nav.gradingScales',
      section: 'administracion',
      shortcut: false,
    })
    // `§18.8` (1.12-a): «Horarios», tras «Escalas de calificación».
    expect(shell.navigation[2]).toMatchObject({
      id: 'acad.timetable',
      route: 'acad-timetable',
      labelKey: 'acad.nav.timetable',
      section: 'administracion',
      shortcut: false,
    })
    // `§18.8` (1.12-a): «Espacios», tras «Horarios».
    expect(shell.navigation[3]).toMatchObject({
      id: 'acad.facilities',
      route: 'acad-facilities',
      labelKey: 'acad.nav.facilities',
      section: 'administracion',
      shortcut: false,
    })
    // `§18.8` (1.12-b): «Reservas», tras «Espacios».
    expect(shell.navigation[4]).toMatchObject({
      id: 'acad.bookings',
      route: 'acad-bookings',
      labelKey: 'acad.nav.bookings',
      section: 'administracion',
      shortcut: false,
    })
    expect(shell.navigation[4]!.icon).toBeTruthy()
    expect(shell.navigation[0]!.icon).toBeTruthy()
    expect(shell.navigation[1]!.icon).toBeTruthy()
    expect(shell.navigation[2]!.icon).toBeTruthy()
    expect(shell.navigation[3]!.icon).toBeTruthy()
    expect(shell.dashboardBlocks).toEqual([])
  })
})

describe('CA-ACAD-098 (RN-ACAD-50, §10.5.1, AR-11): las rutas de escalas', () => {
  it.each(Object.entries(EXPECTED_SCALES))(
    '%s existe con layout app, su permiso configuracion_academica y selector de curso',
    (name, [path, permission, scoped]) => {
      const route = router.getRoutes().find((candidate) => candidate.name === name)

      expect(route, name).toBeDefined()
      expect(route!.path).toBe(path)
      expect(route!.meta.layout).toBe('app')
      expect(route!.meta.permissions).toEqual([permission])
      expect(route!.meta.academicYearScoped).toBe(scoped)
    },
  )

  it('ninguna ruta de escalas usa el recurso estructura_academica (recurso distinto)', () => {
    for (const route of shell.routes.filter((item) => String(item.name).includes('scale'))) {
      const permissions = (route.meta as { permissions?: string[] }).permissions ?? []

      expect(permissions.length, String(route.name)).toBeGreaterThan(0)
      expect(permissions.every((code) => code.startsWith('configuracion_academica.'))).toBe(true)
    }
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

  it('CA-ACAD-098: «Escalas de calificación» aparece solo con configuracion_academica.leer', () => {
    expect(ids([])).not.toContain('acad.scales')
    expect(ids(['estructura_academica.leer'])).not.toContain('acad.scales')
    expect(
      ids([
        'configuracion_academica.crear',
        'configuracion_academica.actualizar',
        'configuracion_academica.eliminar',
      ]),
    ).not.toContain('acad.scales')
    expect(ids(['configuracion_academica.leer'])).toContain('acad.scales')
    // Recurso distinto: tener uno no da el otro.
    expect(ids(['configuracion_academica.leer'])).not.toContain('acad.structure')
  })

  it('con acad descontratado /me no trae sus permisos (inertes): la entrada desaparece', () => {
    // RMOD-008: los permisos de un módulo no contratado no llegan a `/me.permissions`.
    expect(ids(['curso_academico.leer'])).not.toContain('acad.structure')
  })
})

describe('CA-ACAD-138 (§18.8, 1.12-b, AR-11): la ruta de reservas', () => {
  it('acad-bookings existe con layout app, reservas.leer y selector de curso', () => {
    const route = router.getRoutes().find((candidate) => candidate.name === 'acad-bookings')

    expect(route).toBeDefined()
    expect(route!.path).toBe('/academico/reservas')
    expect(route!.meta.layout).toBe('app')
    expect(route!.meta.permissions).toEqual(['reservas.leer'])
    expect(route!.meta.academicYearScoped).toBe(true)
  })

  it('«Reservas» aparece solo con reservas.leer; otro permiso de acad no la da', () => {
    const ids = (permissions: string[]): string[] =>
      visibleNavigationEntries(router, permissions)
        .filter((entry) => entry.section === 'administracion')
        .map((entry) => entry.id)

    expect(ids([])).not.toContain('acad.bookings')
    expect(ids(['reservas.crear', 'reservas.eliminar'])).not.toContain('acad.bookings')
    expect(ids(['horarios.leer', 'espacios.leer'])).not.toContain('acad.bookings')
    expect(ids(['reservas.leer'])).toContain('acad.bookings')
  })
})
