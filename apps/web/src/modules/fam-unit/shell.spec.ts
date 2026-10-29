/**
 * `docs/adr/ADR-067-registro-de-muros-de-sesion.md §5.11`, `§6` (L2), `CA-067-09`, `CA-FAM-UNIT-076` (parte de SPA):
 * registro del módulo `fam-unit` (`AR-11`, `ADR-053 §2`: lo vigila además `src/modules/architecture.spec.ts`) y su muro
 * de sesión. `REQ-FAM-UNIT-003`.
 */
import { describe, expect, it } from 'vitest'
import router from '@/router'
import { moduleShells } from '@/navigation/modules'
import {
  allSessionWalls,
  findSessionWallByCode,
  findSessionWallByErrorType,
} from '@/navigation/registry'
import { shell } from './shell'

describe('CA-FAM-UNIT-084 (1.14-d): rutas de personal y entradas de navegación', () => {
  function routeOf(name: string) {
    const route = router.getRoutes().find((candidate) => candidate.name === name)

    expect(route, `no existe la ruta ${name}`).toBeDefined()

    return route!
  }

  it.each([
    ['fam-unit-list', '/familias', ['familia.leer']],
    ['fam-unit-consents', '/familias/consentimientos', ['consentimiento_imagen.leer']],
    ['fam-unit-detail', '/familias/:publicId', ['familia.leer']],
    ['fam-unit-terms', '/configuracion/condiciones-de-uso', ['texto_legal.leer']],
  ])('%s es %s, layout app y exige exactamente %j (nunca [])', (name, path, permissions) => {
    const route = routeOf(name)

    expect(route.path).toBe(path)
    expect(route.meta.layout).toBe('app')
    expect(route.meta.permissions).toEqual(permissions)
  })

  it('/familias/consentimientos se resuelve a la pantalla de consentimientos, no a la ficha', () => {
    expect(router.resolve('/familias/consentimientos').name).toBe('fam-unit-consents')
    expect(router.resolve('/familias/01JB0000000000000000000001').name).toBe('fam-unit-detail')
  })

  it('declara solo las entradas «Familias» y «Condiciones de uso», ligadas a rutas con permiso', () => {
    expect(shell.navigation.map((entry) => entry.route)).toEqual([
      'fam-unit-list',
      'fam-unit-terms',
    ])
    expect(shell.navigation.map((entry) => entry.labelKey)).toEqual([
      'famUnit.nav.units',
      'famUnit.nav.terms',
    ])
  })

  it('no amplía la lista cerrada de rutas con permissions [] (siguen siendo 8, con el muro)', () => {
    const empty = router
      .getRoutes()
      .filter((route) => route.name !== undefined && route.meta.permissions?.length === 0)
      .filter((route) => String(route.name).startsWith('fam-unit'))

    expect(empty.map((route) => route.name)).toEqual(['fam-unit-terms-wall'])
  })
})

describe('CA-067-09: registro del módulo fam-unit y de su muro', () => {
  it('fam-unit está registrado en el registro de módulos de la navegación', () => {
    expect(moduleShells).toContain(shell)
  })

  it('fam-unit-terms-wall es /condiciones-de-uso, BareLayout y permissions [] (L2)', () => {
    const route = router.getRoutes().find((candidate) => candidate.name === 'fam-unit-terms-wall')

    expect(route).toBeDefined()
    expect(route!.path).toBe('/condiciones-de-uso')
    expect(route!.meta.layout).toBe('bare')
    expect(route!.meta.permissions).toEqual([])
  })

  it('el módulo no declara bloques de panel (funcional.md §10)', () => {
    expect(shell.dashboardBlocks).toEqual([])
  })

  it('el registro de muros ensamblado tiene los dos muros, con ruta existente y de layout bare', () => {
    expect(allSessionWalls().map((wall) => wall.code)).toEqual([
      'mfa-enrollment',
      'terms-acceptance',
    ])

    for (const wall of allSessionWalls()) {
      const route = router.getRoutes().find((candidate) => candidate.name === wall.route)

      expect(route, `${wall.code}: la ruta ${wall.route} no existe`).toBeDefined()
      expect(route!.meta.layout).toBe('bare')
    }
  })

  it('se resuelve un muro por su código y por el type de su 403', () => {
    expect(findSessionWallByCode('terms-acceptance')?.route).toBe('fam-unit-terms-wall')
    expect(findSessionWallByErrorType('urn:pge:error:terms-acceptance-required')?.code).toBe(
      'terms-acceptance',
    )
    expect(findSessionWallByErrorType('urn:pge:error:mfa-enrollment-required')?.code).toBe(
      'mfa-enrollment',
    )
    expect(findSessionWallByErrorType('urn:pge:error:forbidden')).toBeUndefined()
    expect(findSessionWallByErrorType('terms-acceptance-required')).toBeUndefined()
    expect(findSessionWallByCode('otro')).toBeUndefined()
  })
})
