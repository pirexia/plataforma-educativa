/**
 * `docs/modulos/REQ-CORE/funcional.md §12.11` (`CA-CORE-100`,
 * `CA-CORE-101`), `docs/adr/ADR-053 §3`.
 */
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import router from '@/router'
import {
  hasAnyPermission,
  isEntryVisible,
  visibleDashboardBlocks,
  visibleNavigationEntries,
  visibleShortcuts,
} from './registry'
import type { DashboardBlock, NavigationEntry } from './types'

describe('hasAnyPermission', () => {
  it('[] es identidad: siempre visible', () => {
    expect(hasAnyPermission([], [])).toBe(true)
    expect(hasAnyPermission([], ['algo.leer'])).toBe(true)
  })

  it('anyOf: basta con uno de los permisos requeridos', () => {
    expect(hasAnyPermission(['a.leer', 'b.leer'], ['b.leer'])).toBe(true)
    expect(hasAnyPermission(['a.leer', 'b.leer'], ['c.leer'])).toBe(false)
  })

  it('meta.permissions ausente (undefined) se trata como no concedido', () => {
    expect(hasAnyPermission(undefined, ['a.leer'])).toBe(false)
  })
})

describe('registro real ensamblado — CA-CORE-100', () => {
  it('con permissions vacío, la navegación contiene exactamente Inicio y las tres de Mi cuenta', () => {
    const entries = visibleNavigationEntries(router, [])
    const ids = entries.map((e) => e.id).sort()

    expect(ids).toEqual(['auth.mfaSecurity', 'auth.password', 'auth.sessions', 'core.home'].sort())
  })

  it('con proveedor_identidad.leer, aparece además SSO', () => {
    const entries = visibleNavigationEntries(router, ['proveedor_identidad.leer'])
    const ids = entries.map((e) => e.id)

    expect(ids).toContain('auth.ssoAdministration')
  })

  it('con los permisos de administración de MFA, aparece esa entrada', () => {
    const entries = visibleNavigationEntries(router, ['mfa.leer'])

    expect(entries.map((e) => e.id)).toContain('auth.mfaAdministration')
  })
})

describe('CA-CORE-101: una entrada de módulo descontratado no aparece en ningún sitio', () => {
  function makeFixtureRouter() {
    return createRouter({
      history: createMemoryHistory(),
      routes: [
        {
          path: '/fixture',
          name: 'fixture-route',
          component: { template: '<div/>' },
          meta: { layout: 'app', permissions: ['fixture.leer'] },
        },
      ],
    })
  }

  const fixtureEntry: NavigationEntry = {
    id: 'fixture.entry',
    route: 'fixture-route',
    labelKey: 'fixture.nav.entry',
    icon: {},
    section: 'inicio',
    shortcut: true,
  }

  it('sin fixture.leer (permiso inerte de módulo descontratado), la entrada no es visible', () => {
    const fixtureRouter = makeFixtureRouter()

    expect(isEntryVisible(fixtureEntry, fixtureRouter, [])).toBe(false)
  })

  it('con fixture.leer, sí es visible (control: el mecanismo funciona en el sentido contrario)', () => {
    const fixtureRouter = makeFixtureRouter()

    expect(isEntryVisible(fixtureEntry, fixtureRouter, ['fixture.leer'])).toBe(true)
  })

  it('una entrada que apunta a una ruta no registrada nunca es visible', () => {
    const fixtureRouter = makeFixtureRouter()
    const orphanEntry: NavigationEntry = { ...fixtureEntry, route: 'no-existe' }

    expect(isEntryVisible(orphanEntry, fixtureRouter, ['fixture.leer'])).toBe(false)
  })
})

describe('visibleShortcuts', () => {
  it('solo devuelve entradas visibles marcadas shortcut: true', () => {
    const shortcuts = visibleShortcuts(router, [])

    expect(shortcuts.every((entry) => entry.shortcut === true)).toBe(true)
    expect(shortcuts.map((e) => e.id)).not.toContain('core.home')
  })
})

describe('visibleDashboardBlocks', () => {
  it('con el registro de 1.8 (sin bloques de módulo), siempre es una lista vacía', () => {
    expect(visibleDashboardBlocks([])).toEqual([])
    expect(visibleDashboardBlocks(['cualquier.permiso'])).toEqual([])
  })

  it('un bloque de prueba con permissions anyOf se filtra igual que una entrada', () => {
    const block: DashboardBlock = {
      id: 'fixture.block',
      titleKey: 'fixture.block.title',
      permissions: ['fixture.leer'],
      component: () => Promise.resolve({}),
    }

    expect(hasAnyPermission(block.permissions, [])).toBe(false)
    expect(hasAnyPermission(block.permissions, ['fixture.leer'])).toBe(true)
  })
})
