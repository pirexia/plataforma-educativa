/**
 * `docs/modulos/REQ-CORE/funcional.md §14.3`, `§14.18`: registro de las
 * pantallas de 1.9b — `CA-CORE-208` (rutas y permisos de la columna 2 de
 * §14.3, sin cambiar la lista cerrada de `RN-CORE-24`) y `CA-CORE-209`
 * (entradas de menú por permiso, derivadas de la ruta, `ADR-053 §3`).
 */
import { describe, expect, it } from 'vitest'
import router from '@/router'
import { visibleNavigationEntries } from '@/navigation/registry'
import { shell } from './shell'

/** Columna 2 de `funcional.md §14.3`, solo las rutas de 1.9b. */
const EXPECTED: Record<string, { path: string; permissions: string[] }> = {
  'core-users': { path: '/administracion/usuarios', permissions: ['usuario.leer'] },
  'core-user-new': { path: '/administracion/usuarios/nuevo', permissions: ['usuario.crear'] },
  'core-user-detail': { path: '/administracion/usuarios/:publicId', permissions: ['usuario.leer'] },
  'core-user-edit': {
    path: '/administracion/usuarios/:publicId/editar',
    permissions: ['usuario.actualizar'],
  },
  'core-invitations': { path: '/administracion/invitaciones', permissions: ['invitacion.leer'] },
}

describe('CA-CORE-208 (RN-CORE-60, ADR-053 §2): rutas de 1.9b', () => {
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

  it('«Usuarios» es acceso directo y «Invitaciones» no (§14.3)', () => {
    const byId = Object.fromEntries(shell.navigation.map((entry) => [entry.id, entry.shortcut]))

    expect(byId['core.users']).toBe(true)
    expect(byId['core.invitations']).toBe(false)
  })
})
