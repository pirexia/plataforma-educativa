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

  it('el módulo no declara entradas de menú ni bloques de panel (1.14-d trae las pantallas de personal)', () => {
    expect(shell.navigation).toEqual([])
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
