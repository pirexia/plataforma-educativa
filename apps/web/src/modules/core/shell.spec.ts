/**
 * `docs/modulos/REQ-CORE/funcional.md §14.3`, `§14.18`: registro de las
 * pantallas de 1.9b — `CA-CORE-208` (rutas y permisos de la columna 2 de
 * §14.3, sin cambiar la lista cerrada de `RN-CORE-24`) y `CA-CORE-209`
 * (entradas de menú por permiso, derivadas de la ruta, `ADR-053 §3`); desde
 * 1.9c, también las dos rutas de importación de usuarios (`usuario.importar`) y
 * desde 1.9d las de roles (`rol.leer`) y auditoría (`auditoria.leer`).
 */
import { describe, expect, it } from 'vitest'
import router from '@/router'
import { visibleNavigationEntries } from '@/navigation/registry'
import { shell } from './shell'

/** Columna 2 de `funcional.md §14.3`, solo las rutas de 1.9b y 1.9c. */
const EXPECTED: Record<string, { path: string; permissions: string[] }> = {
  'core-users': { path: '/administracion/usuarios', permissions: ['usuario.leer'] },
  'core-user-new': { path: '/administracion/usuarios/nuevo', permissions: ['usuario.crear'] },
  'core-user-detail': { path: '/administracion/usuarios/:publicId', permissions: ['usuario.leer'] },
  'core-user-edit': {
    path: '/administracion/usuarios/:publicId/editar',
    permissions: ['usuario.actualizar'],
  },
  'core-invitations': { path: '/administracion/invitaciones', permissions: ['invitacion.leer'] },
  'core-user-imports': { path: '/administracion/importaciones', permissions: ['usuario.importar'] },
  'core-user-import-detail': {
    path: '/administracion/importaciones/:publicId',
    permissions: ['usuario.importar'],
  },
  // 1.9d
  'core-roles': { path: '/administracion/roles', permissions: ['rol.leer'] },
  'core-audit': { path: '/administracion/auditoria', permissions: ['auditoria.leer'] },
}

describe('CA-CORE-208 (RN-CORE-60, ADR-053 §2): rutas de 1.9b y 1.9c', () => {
  it.each(Object.entries(EXPECTED))(
    '%s existe con layout app y el permiso de §14.3',
    (name, expected) => {
      const route = router.getRoutes().find((candidate) => candidate.name === name)

      expect(route, name).toBeDefined()
      expect(route!.path).toBe(expected.path)
      expect(route!.meta.layout).toBe('app')
      expect(route!.meta.permissions).toEqual(expected.permissions)
    },
  )

  it('ninguna ruta de core declara permissions vacío (la lista cerrada de RN-CORE-24 no cambia en 1.9b)', () => {
    for (const route of shell.routes) {
      expect(
        (route.meta as { permissions?: string[] }).permissions?.length,
        String(route.name),
      ).toBeGreaterThan(0)
    }
  })

  it('las rutas secundarias declaran su padre de miga de pan y títulos en core.*', () => {
    for (const name of ['core-user-new', 'core-user-detail', 'core-user-edit']) {
      const route = router.getRoutes().find((candidate) => candidate.name === name)!

      expect(route.meta.breadcrumbParent, name).toBe('core-users')
      expect(route.meta.titleKey, name).toMatch(/^core\./)
    }
  })
})

describe('1.9c (§14.3): rutas de importación', () => {
  it('el detalle declara como padre de la miga de pan al listado y títulos en core.*', () => {
    const detail = router
      .getRoutes()
      .find((candidate) => candidate.name === 'core-user-import-detail')!
    const list = router.getRoutes().find((candidate) => candidate.name === 'core-user-imports')!

    expect(detail.meta.breadcrumbParent).toBe('core-user-imports')
    expect(detail.meta.titleKey).toMatch(/^core\./)
    expect(list.meta.titleKey).toMatch(/^core\./)
  })
})

describe('1.9d (§14.3, OPEN-CORE-36 = A): roles y auditoría', () => {
  it('no existe core-role-detail ni ninguna ruta de detalle de un rol', () => {
    expect(
      router.getRoutes().find((candidate) => candidate.name === 'core-role-detail'),
    ).toBeUndefined()
    expect(
      router.getRoutes().filter((candidate) => candidate.path.startsWith('/administracion/roles/')),
    ).toEqual([])
  })
})

describe('CA-CORE-209 (RN-CORE-60): entradas de menú por permiso', () => {
  function entries(permissions: string[]): string[] {
    return visibleNavigationEntries(router, permissions)
      .filter((entry) => entry.section === 'administracion')
      .map((entry) => entry.id)
  }

  it('con solo usuario.leer, «Usuarios» y ninguna otra entrada de §14.3', () => {
    expect(entries(['usuario.leer'])).toEqual(['core.users'])
  })

  it('sin ningún permiso de REQ-CORE no aparece ninguna entrada de §14.3', () => {
    expect(entries([])).toEqual([])
  })

  it('con invitacion.leer aparece «Invitaciones»', () => {
    expect(entries(['usuario.leer', 'invitacion.leer'])).toEqual(['core.users', 'core.invitations'])
  })

  it('con usuario.importar aparece «Importación de usuarios» y no es acceso directo (§14.3)', () => {
    expect(entries(['usuario.leer', 'usuario.importar'])).toEqual([
      'core.users',
      'core.userImports',
    ])
    expect(entries(['usuario.importar'])).toEqual(['core.userImports'])

    const byId = Object.fromEntries(shell.navigation.map((entry) => [entry.id, entry.shortcut]))

    expect(byId['core.userImports']).toBe(false)
  })

  it('«Usuarios» es acceso directo y «Invitaciones» no (§14.3)', () => {
    const byId = Object.fromEntries(shell.navigation.map((entry) => [entry.id, entry.shortcut]))

    expect(byId['core.users']).toBe(true)
    expect(byId['core.invitations']).toBe(false)
  })

  it('con rol.leer aparece «Roles» y con auditoria.leer «Auditoría» (acceso directo), cada una solo con su permiso', () => {
    expect(entries(['rol.leer'])).toEqual(['core.roles'])
    expect(entries(['auditoria.leer'])).toEqual(['core.audit'])

    const byId = Object.fromEntries(shell.navigation.map((entry) => [entry.id, entry.shortcut]))

    expect(byId['core.audit']).toBe(true)
    expect(byId['core.roles']).toBe(false)
  })
})
