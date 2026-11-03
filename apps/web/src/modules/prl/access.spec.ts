/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.9`, `CA-PRL-014` (`RMOD-008`, `INV-002`, `CA-PRL-009`), `REQ-PRL-004`:
 * la interfaz oculta, el servidor decide. Sin `entrega_menor.leer` no hay entrada de menú y la ruta se pinta como «sin
 * permiso» (la decide el *layout* con `meta.permissions`) sin montar la vista, así que no sale ninguna petición a
 * `/door/*`; con `prl` descontratado (`/me` sin sus permisos) desaparecen las dos entradas. Los botones de escritura
 * según permiso se prueban en `DoorStudentView.spec.ts` (`CA-PRL-014`, segunda parte).
 */
import { describe, expect, it, vi } from 'vitest'
import router from '@/router'
import { hasAnyPermission, visibleNavigationEntries } from '@/navigation/registry'
import * as prlApi from './api'

vi.mock('./api')

function routeLabels(permissions: string[]): string[] {
  return visibleNavigationEntries(router, permissions)
    .filter((entry) => entry.labelKey.startsWith('prl.'))
    .map((entry) => entry.labelKey)
}

describe('CA-PRL-014: acceso a la conserjería según permisos (RMOD-008, INV-002)', () => {
  it('sin entrega_menor.leer no se ve «Conserjería» y la ruta se pinta como sin permiso', () => {
    const permissions = ['alerta_entrega.leer', 'familia.leer']

    expect(routeLabels(permissions)).toEqual(['prl.nav.alerts'])

    for (const path of ['/conserjeria', '/conserjeria/alumnos/P1']) {
      const meta = router.resolve(path).meta

      expect(hasAnyPermission(meta.permissions, permissions)).toBe(false)
    }

    // Ninguna vista montada ⇒ ninguna petición a /door/*.
    expect(prlApi.searchDoorStudents).not.toHaveBeenCalled()
    expect(prlApi.getDoorPickupList).not.toHaveBeenCalled()
  })

  it('con prl descontratado (sin sus permisos en /me) desaparecen las dos entradas', () => {
    expect(routeLabels(['familia.leer', 'autorizado_recogida.leer'])).toEqual([])
  })

  it('con ambos .leer aparecen las dos entradas', () => {
    expect(routeLabels(['entrega_menor.leer', 'alerta_entrega.leer'])).toEqual([
      'prl.nav.door',
      'prl.nav.alerts',
    ])
  })
})
