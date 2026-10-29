/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §10` (1.14-d), `§4.7`/`§4.8`, `CA-FAM-UNIT-081`, `CA-FAM-UNIT-086`: estado
 * vigente del consentimiento de imagen por alumno y nivel (el estado, siempre con texto e icono), filtros en la URL,
 * histórico por alumno e informe solo con `consentimiento_imagen.exportar`. `REQ-FAM-UNIT-004`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { setLocale } from '@/i18n'
import * as famApi from '../api'
import {
  buttonByLabel,
  consent,
  cursorPage,
  decision,
  mountView,
  page,
  stubMatchMedia,
} from '../testSupport'

vi.mock('../api')
vi.mock('@/modules/core/api', () => ({ getDataExport: vi.fn() }))
vi.mock('@/session/useSession', async () => {
  const { shallowRef } = await import('vue')
  const user = shallowRef<{ public_id: string; permissions: string[] } | null>(null)

  return {
    useSession: () => ({ user }),
    __setPermissions: (permissions: string[]) => {
      user.value = { public_id: 'ME', permissions }
    },
  }
})

const session = (await import('@/session/useSession')) as unknown as {
  __setPermissions: (permissions: string[]) => void
}
const { default: ConsentsView } = await import('./ConsentsView.vue')

const wrappers: VueWrapper[] = []

async function open(url = '/familias/consentimientos') {
  const mounted = await mountView('fam-unit-consents', ConsentsView, url)

  wrappers.push(mounted.wrapper)

  return mounted
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  window.localStorage.clear()
  for (const fn of Object.values(famApi)) {
    if (typeof fn === 'function' && 'mockReset' in fn) (fn as ReturnType<typeof vi.fn>).mockReset()
  }
  vi.mocked(famApi.listImageConsents).mockResolvedValue(
    page([
      consent(),
      consent({ level: 'redes_sociales', state: 'no_autorizo' }),
      consent({
        student_person: 'P2',
        display_name: 'Sofía Ejemplo Gil',
        family_unit: 'U2',
        state: 'pendiente',
        last_decision_at: null,
      }),
    ]) as never,
  )
  session.__setPermissions(['consentimiento_imagen.leer'])
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-FAM-UNIT-086: estado vigente por alumno y nivel', () => {
  it('cada estado se pinta con su texto y un icono, nunca solo con color; el pendiente incluye al alumno sin decisión', async () => {
    const { wrapper } = await open()
    const rows = wrapper.findAll('tbody tr')

    expect(rows).toHaveLength(3)
    expect(rows[0]!.text()).toContain('Autorizo')
    expect(rows[0]!.text()).toContain('Web del centro')
    expect(rows[1]!.text()).toContain('No autorizo')
    expect(rows[1]!.text()).toContain('Redes sociales')
    expect(rows[2]!.text()).toContain('Sofía Ejemplo Gil')
    expect(rows[2]!.text()).toContain('Pendiente')

    for (const row of rows) {
      expect(
        row.element.querySelector('[data-slot="fam-unit-status"] svg[aria-hidden="true"]'),
      ).not.toBeNull()
    }
  })

  it('la fila no tiene public_id: se identifica por alumno y nivel (sin claves repetidas)', async () => {
    const { wrapper } = await open()

    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
  })

  it('los filtros nivel y estado se piden con la URL', async () => {
    await open(
      '/familias/consentimientos?level=web&state=pendiente,no_autorizo&sort=-last_decision_at',
    )

    expect(vi.mocked(famApi.listImageConsents).mock.calls.at(-1)![0]).toMatchObject({
      level: 'web',
      state: 'pendiente,no_autorizo',
      sort: '-last_decision_at',
    })
  })

  it('cada fila enlaza con su unidad familiar con nombre accesible', async () => {
    const { wrapper } = await open()

    expect(
      wrapper
        .find('a[aria-label="Ver la unidad familiar de Sofía Ejemplo Gil"]')
        .attributes('href'),
    ).toBe('/familias/U2')
  })
})

describe('CA-FAM-UNIT-081 / INV-002: informe por permiso', () => {
  it('sin consentimiento_imagen.exportar no hay botón de informe', async () => {
    const { wrapper } = await open()

    expect(wrapper.text()).not.toContain('Exportar')
  })

  it('con consentimiento_imagen.exportar se ofrece el informe', async () => {
    session.__setPermissions(['consentimiento_imagen.leer', 'consentimiento_imagen.exportar'])

    const { wrapper } = await open()

    expect(wrapper.text()).toContain('Exportar')
  })
})

describe('CA-FAM-UNIT-086: histórico de decisiones por alumno', () => {
  it('abre el histórico del alumno pedido, con decisión en texto e icono, y no ofrece editar ni borrar', async () => {
    vi.mocked(famApi.listImageConsentDecisions).mockResolvedValue(
      cursorPage([
        decision(),
        decision({
          public_id: 'D2',
          decision: 'no_autorizo',
          level: 'redes_sociales',
          consent_text_version: null,
        }),
      ]) as never,
    )

    await open()

    buttonByLabel('Ver el histórico de decisiones de Lucas Ejemplo Ruiz')!.click()
    await flushPromises()

    expect(famApi.listImageConsentDecisions).toHaveBeenCalledWith({
      student_person: 'P1',
      cursor: undefined,
    })

    const dialog = document.body.querySelector('[data-slot="fam-unit-consent-history"]')!
    const rows = [...dialog.querySelectorAll('tbody tr')].map((row) => row.textContent ?? '')

    expect(rows).toHaveLength(2)
    expect(rows[0]).toContain('Autorizo')
    expect(rows[0]).toContain('Presencial')
    expect(rows[0]).toContain('Versión 2')
    expect(rows[1]).toContain('No autorizo')
    expect(dialog.textContent).toContain('No se pueden editar ni borrar')
    expect(dialog.querySelector('tbody button')).toBeNull()
  })

  it('un alumno sin decisiones muestra el estado vacío', async () => {
    vi.mocked(famApi.listImageConsentDecisions).mockResolvedValue(cursorPage([]) as never)

    await open()

    buttonByLabel('Ver el histórico de decisiones de Lucas Ejemplo Ruiz')!.click()
    await flushPromises()

    expect(document.body.textContent).toContain('Este alumno no tiene decisiones registradas.')
  })
})
