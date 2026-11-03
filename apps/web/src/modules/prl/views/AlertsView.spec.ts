/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.4`, `§23.7` (`1.14b-c2` T1), `REQ-PRL-004`: `CA-PRL-023`
 * («Abierta»/«Cerrada» con texto e icono) en el listado de alertas. Datos ficticios (`REQ-SEED-005`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { setLocale } from '@/i18n'
import * as prlApi from '../api'
import { useDoorContext } from '../composables/useDoorContext'
import { buttonByText, mountView, page, pickupAlert, stubMatchMedia } from '../testSupport'

vi.mock('../api')
const { default: AlertsView } = await import('./AlertsView.vue')

const wrappers: VueWrapper[] = []

function text(): string {
  return document.body.textContent ?? ''
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  useDoorContext().clear()
  vi.mocked(prlApi.listPickupAlerts).mockReset()
  vi.mocked(prlApi.listPickupAlerts).mockResolvedValue(
    page([
      pickupAlert(),
      pickupAlert({
        public_id: 'A2',
        student_person: 'P2',
        status: 'cerrada',
        resolution: 'no_entregado',
        protocol: undefined,
        contacts: undefined,
      }),
    ]) as never,
  )
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-PRL-023: listado de alertas (WCAG 1.4.1)', () => {
  it('«Abierta» y «Cerrada» llevan texto e icono (aria-hidden), y la resolución su etiqueta', async () => {
    useDoorContext().remember('P1', { displayName: 'Lucas Ejemplo Ruiz', familyUnitCode: null })

    const mounted = await mountView('prl-alerts', AlertsView, '/conserjeria/alertas')

    wrappers.push(mounted.wrapper)

    const badges = [...document.body.querySelectorAll('[data-slot="prl-status"]')]
    const labels = badges.map((badge) => badge.textContent?.trim())

    expect(labels).toContain('Abierta')
    expect(labels).toContain('Cerrada')

    for (const badge of badges) {
      expect(badge.querySelector('svg[aria-hidden="true"]')).not.toBeNull()
    }

    expect(text()).toContain('No entregado')
    expect(text()).toContain('Lucas Ejemplo Ruiz')
    expect(document.body.querySelector('a[href="/conserjeria/alertas/A1"]')).not.toBeNull()
  })

  it('carga una sola vez al entrar, sin teléfonos ni protocolo, con estado vacío propio', async () => {
    vi.mocked(prlApi.listPickupAlerts).mockResolvedValue(page([]) as never)

    const mounted = await mountView('prl-alerts', AlertsView, '/conserjeria/alertas')

    wrappers.push(mounted.wrapper)
    await flushPromises()

    expect(prlApi.listPickupAlerts).toHaveBeenCalledTimes(1)
    expect(text()).toContain('No hay alertas.')
    expect(document.body.querySelector('a[href^="tel:"]')).toBeNull()
  })

  it('muestra los nombres que da la API (alumno y quien abrió la alerta), no los public_id, con fallback si faltan', async () => {
    vi.mocked(prlApi.listPickupAlerts).mockResolvedValue(
      page([
        pickupAlert({
          student_display_name: 'Marta Ejemplo Soler',
          raised_by_display_name: 'Conserje Ejemplo',
        }),
        pickupAlert({ public_id: 'A2', student_person: 'P2', raised_by: 'USR2' }),
      ]) as never,
    )

    const mounted = await mountView('prl-alerts', AlertsView, '/conserjeria/alertas')

    wrappers.push(mounted.wrapper)

    expect(text()).toContain('Marta Ejemplo Soler')
    expect(text()).toContain('Conserje Ejemplo')
    expect(text()).not.toContain('USR1')
    expect(text()).not.toContain('USR2')
    // Fila sin nombres: «Alumno» y «Usuario», nunca el identificador.
    expect(text()).toContain('Usuario')
  })

  it('el campo de fecha (fuera de la tabla) envía date a la API y recarga; «Quitar fecha» lo retira', async () => {
    const mounted = await mountView('prl-alerts', AlertsView, '/conserjeria/alertas')

    wrappers.push(mounted.wrapper)
    expect(vi.mocked(prlApi.listPickupAlerts).mock.calls.at(-1)?.[0]).toMatchObject({
      date: undefined,
    })

    const input = document.body.querySelector<HTMLInputElement>('input#prl-alerts-date')!

    expect(input.type).toBe('date')
    expect(document.body.querySelector('label[for="prl-alerts-date"]')?.textContent).toContain(
      'Fecha de apertura',
    )

    input.value = '2026-11-03'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await flushPromises()

    expect(vi.mocked(prlApi.listPickupAlerts).mock.calls.at(-1)?.[0]).toMatchObject({
      date: '2026-11-03',
    })

    const clear = buttonByText('Quitar fecha')!

    clear.click()
    await flushPromises()

    expect(vi.mocked(prlApi.listPickupAlerts).mock.calls.at(-1)?.[0].date).toBeUndefined()
    expect(buttonByText('Quitar fecha')).toBeUndefined()
  })
})
