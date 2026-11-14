/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.3`, `AR-11` (`1.14b-c2`, `REQ-PRL-004`): rutas de conserjería con su
 * permiso exacto (nunca `[]`), entradas de menú y registro del módulo.
 */
import { describe, expect, it } from 'vitest'
import router from '@/router'
import { moduleShells } from '@/navigation/modules'
import { shell as famUnitShell } from '@/modules/fam-unit/shell'
import { shell } from './shell'

function routeOf(name: string) {
  const route = router.getRoutes().find((candidate) => candidate.name === name)

  expect(route, `no existe la ruta ${name}`).toBeDefined()

  return route!
}

describe('REQ-PRL-004 (§23.3): rutas SPA de prl', () => {
  it.each([
    ['prl-door', '/conserjeria', ['entrega_menor.leer']],
    ['prl-door-student', '/conserjeria/alumnos/:studentPersonPublicId', ['entrega_menor.leer']],
    ['prl-alerts', '/conserjeria/alertas', ['alerta_entrega.leer']],
    ['prl-alert-detail', '/conserjeria/alertas/:publicId', ['alerta_entrega.leer']],
  ])('%s es %s, layout app y exige exactamente %j (nunca [])', (name, path, permissions) => {
    const route = routeOf(name)

    expect(route.path).toBe(path)
    expect(route.meta.layout).toBe('app')
    expect(route.meta.permissions).toEqual(permissions)
  })

  it('las migas y los títulos son fijos (nunca el nombre del alumno) y los hijos cuelgan de su listado', () => {
    expect(routeOf('prl-door-student').meta.breadcrumbParent).toBe('prl-door')
    expect(routeOf('prl-alert-detail').meta.breadcrumbParent).toBe('prl-alerts')
    expect(routeOf('prl-door').meta.titleKey).toBe('prl.door.title')
    expect(routeOf('prl-door-student').meta.titleKey).toBe('prl.door.studentTitle')
    expect(routeOf('prl-alert-detail').meta.titleKey).toBe('prl.alert.title')
  })

  it('el segmento fijo /conserjeria/alertas gana al paramétrico', () => {
    expect(router.resolve('/conserjeria/alertas').name).toBe('prl-alerts')
    expect(router.resolve('/conserjeria/alertas/01JB0000000000000000000001').name).toBe(
      'prl-alert-detail',
    )
    expect(router.resolve('/conserjeria/alumnos/01JB0000000000000000000001').name).toBe(
      'prl-door-student',
    )
  })

  it('no amplía la lista de rutas con permissions []', () => {
    const empty = router
      .getRoutes()
      .filter((route) => route.name !== undefined && route.meta.permissions?.length === 0)
      .filter((route) => String(route.name).startsWith('prl'))

    expect(empty).toEqual([])
  })

  it('declara «Conserjería» (con acceso directo) y «Alertas de entrega» en administracion', () => {
    expect(shell.navigation.map((entry) => [entry.route, entry.section, entry.shortcut])).toEqual([
      ['prl-door', 'administracion', true],
      ['prl-alerts', 'administracion', false],
    ])
    expect(shell.navigation.map((entry) => entry.labelKey)).toEqual([
      'prl.nav.door',
      'prl.nav.alerts',
    ])
    expect(shell.dashboardBlocks).toEqual([])
  })

  it('prl está registrado en el registro de módulos, justo después de fam-unit', () => {
    const index = moduleShells.indexOf(shell)

    expect(index).toBeGreaterThan(0)
    expect(moduleShells[index - 1]).toBe(famUnitShell)
  })
})
