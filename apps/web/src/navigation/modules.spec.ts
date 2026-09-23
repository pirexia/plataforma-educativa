/**
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md` §2,
 * `docs/modulos/REQ-CORE/funcional.md §12.11`
 * (`CA-CORE-103`, `CA-CORE-106`).
 *
 * Nota sobre `mfa-enrollment-wall` y `not-found`: la prosa de
 * `RN-CORE-24`/`CA-CORE-103` enumera **cuatro** rutas con
 * `meta.permissions` vacía (Inicio, Contraseña, Sesiones, Seguridad).
 * Esta suite verifica **seis**: las cuatro de la prosa más
 * `mfa-enrollment-wall` y `not-found`, cuyo motivo está documentado en el
 * comentario de cabecera de `src/modules/auth/shell.ts` y en
 * `src/router/index.ts`. Es una discrepancia conocida y reportada
 * (issue de este paso, severidad Media): ninguna de las dos rutas tiene
 * un permiso real que declarar sin inventarlo (`INV-002`).
 */
import { describe, expect, it } from 'vitest'
import router from '@/router'
import { allDashboardBlocks, allNavigationEntries } from './registry'
import { SECTION_IDS } from './sections'
import type { NavigationEntry } from './types'

const EMPTY_PERMISSIONS_CLOSED_LIST = [
  'home',
  'password-change',
  'sessions',
  'mfa-security',
  'mfa-enrollment-wall',
  'not-found',
].sort()

describe('registro de navegación ensamblado — CA-CORE-103', () => {
  it('toda entrada apunta a un nombre de ruta registrado con meta.layout === "app"', () => {
    for (const entry of allNavigationEntries()) {
      const route = router.getRoutes().find((r) => r.name === entry.route)

      expect(
        route,
        `entrada ${entry.id} apunta a una ruta inexistente (${entry.route})`,
      ).toBeDefined()
      expect(route?.meta.layout, `entrada ${entry.id} no apunta a una ruta app`).toBe('app')
    }
  })

  it('toda ruta app/bare declara meta.permissions explícitamente (nunca ausente)', () => {
    for (const route of router.getRoutes()) {
      if (route.meta.layout === 'public') {
        continue
      }

      expect(
        Array.isArray(route.meta.permissions),
        `${String(route.name)} (${route.meta.layout}) no declara meta.permissions`,
      ).toBe(true)
    }
  })

  it('las únicas rutas app/bare con meta.permissions vacía son la lista cerrada', () => {
    const empty = router
      .getRoutes()
      .filter((route) => route.meta.layout !== 'public' && route.meta.permissions?.length === 0)
      .map((route) => String(route.name))
      .sort()

    expect(empty).toEqual(EMPTY_PERMISSIONS_CLOSED_LIST)
  })
})

describe('registro de navegación ensamblado — CA-CORE-106', () => {
  it('(a) todo id de entrada y bloque de panel es único y lleva prefijo de módulo', () => {
    const ids = [...allNavigationEntries(), ...allDashboardBlocks()].map((item) => item.id)

    expect(new Set(ids).size, 'hay ids duplicados en el registro').toBe(ids.length)

    for (const id of ids) {
      expect(id, `${id} no lleva prefijo <módulo>.`).toMatch(/^[a-z]+\.[a-zA-Z0-9]+$/)
    }
  })

  it('(a, caso fijo) un id duplicado se detecta', () => {
    const fixture: NavigationEntry[] = [
      { id: 'auth.sessions', route: 'sessions', labelKey: 'x', icon: {}, section: 'cuenta' },
      { id: 'auth.sessions', route: 'password-change', labelKey: 'y', icon: {}, section: 'cuenta' },
    ]

    const ids = fixture.map((entry) => entry.id)
    expect(new Set(ids).size).not.toBe(ids.length)
  })

  it('(b) todo nombre de ruta es único entre todos los módulos', () => {
    const names = router.getRoutes().map((route) => String(route.name))

    expect(new Set(names).size, 'hay nombres de ruta duplicados').toBe(names.length)
  })

  it('(c) toda section de toda entrada existe en el catálogo de sections.ts', () => {
    for (const entry of allNavigationEntries()) {
      expect(SECTION_IDS, `sección desconocida en ${entry.id}: ${entry.section}`).toContain(
        entry.section,
      )
    }
  })

  it('(c, caso fijo) una sección inexistente se detecta', () => {
    const fixtureSection = 'inexistente' as unknown as (typeof SECTION_IDS)[number]

    expect(SECTION_IDS).not.toContain(fixtureSection)
  })
})

describe('src/modules/core/shell.ts y src/modules/auth/shell.ts (ADR-053 §1)', () => {
  it('ningún bloque de panel de 1.8 tiene permissions vacía (ADR-053 §5.1)', () => {
    for (const block of allDashboardBlocks()) {
      expect(
        block.permissions.length,
        `bloque ${block.id} tiene permissions vacía`,
      ).toBeGreaterThan(0)
    }
  })
})
