/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §10` (1.14-d), `CA-FAM-UNIT-084`, `CA-FAM-UNIT-081`: listado de unidades
 * familiares (tabla de `ADR-054`, estado en la URL, búsqueda y filtro), alta por permiso y enlaces por permiso.
 * `REQ-FAM-UNIT-001`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as famApi from '../api'
import {
  buttonByText,
  familyUnit,
  fill,
  mountView,
  page,
  stubMatchMedia,
  submitForm,
} from '../testSupport'

vi.mock('../api')
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
const { default: FamilyUnitsView } = await import('./FamilyUnitsView.vue')

const wrappers: VueWrapper[] = []

async function open(url = '/familias') {
  const mounted = await mountView('fam-unit-list', FamilyUnitsView, url)

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
  vi.mocked(famApi.listFamilyUnits).mockResolvedValue(
    page([
      familyUnit(),
      familyUnit({
        public_id: 'U2',
        code: 'UF-000124',
        principal_guardian: null,
        guardians_count: 0,
        students_count: 3,
      }),
    ]) as never,
  )
  session.__setPermissions(['familia.leer', 'familia.crear', 'consentimiento_imagen.leer'])
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-FAM-UNIT-084: listado de unidades familiares', () => {
  it('pinta código, tutor principal (con texto), nº de tutores y nº de alumnos', async () => {
    const { wrapper } = await open()
    const rows = wrapper.findAll('tbody tr').map((row) => row.text())

    expect(rows).toHaveLength(2)
    expect(rows[0]).toContain('UF-000123')
    expect(rows[0]).toContain('Definido')
    expect(rows[0]).toContain('2')
    expect(rows[1]).toContain('UF-000124')
    expect(rows[1]).toContain('Sin tutor principal')
    expect(rows[1]).toContain('3')
  })

  it('el código enlaza a la ficha', async () => {
    const { wrapper } = await open()
    const link = wrapper.find('tbody a')

    expect(link.text()).toBe('UF-000123')
    expect(link.attributes('href')).toBe('/familias/U1')
  })

  it('el listado se pide con la búsqueda, el filtro y el orden de la URL', async () => {
    await open('/familias?has_principal=true&sort=-code')

    expect(vi.mocked(famApi.listFamilyUnits).mock.calls.at(-1)![0]).toMatchObject({
      has_principal: 'true',
      sort: '-code',
    })
  })

  it('sin unidades: estado vacío con la acción de alta si hay familia.crear', async () => {
    vi.mocked(famApi.listFamilyUnits).mockResolvedValue(page([]) as never)

    const { wrapper } = await open()

    expect(wrapper.text()).toContain('Todavía no hay unidades familiares')
  })
})

describe('CA-FAM-UNIT-081 / INV-002: acciones por permiso', () => {
  it('con solo familia.leer no hay alta ni enlace a consentimientos', async () => {
    session.__setPermissions(['familia.leer'])

    const { wrapper } = await open()

    expect(buttonByText('Nueva unidad')).toBeUndefined()
    expect(wrapper.text()).not.toContain('Consentimientos de imagen')
  })

  it('con familia.crear y consentimiento_imagen.leer hay alta y enlace a consentimientos', async () => {
    const { wrapper } = await open()

    expect(buttonByText('Nueva unidad')).toBeDefined()
    expect(wrapper.find('a[href="/familias/consentimientos"]').exists()).toBe(true)
  })
})

describe('CA-FAM-UNIT-084: alta de una unidad', () => {
  it('sin código lo deja al sistema (cuerpo vacío) y lleva a la ficha creada', async () => {
    vi.mocked(famApi.createFamilyUnit).mockResolvedValue(familyUnit({ public_id: 'U9' }) as never)

    const { router } = await open()

    buttonByText('Nueva unidad')!.click()
    await flushPromises()
    await submitForm('fam-unit-unit-form')

    expect(famApi.createFamilyUnit).toHaveBeenCalledWith({})
    expect(router.currentRoute.value.name).toBe('fam-unit-detail')
    expect(router.currentRoute.value.params.publicId).toBe('U9')
  })

  it('con código lo envía recortado', async () => {
    vi.mocked(famApi.createFamilyUnit).mockResolvedValue(familyUnit({ public_id: 'U9' }) as never)

    await open()

    buttonByText('Nueva unidad')!.click()
    await flushPromises()
    await fill('#fam-unit-code', '  UF-7  ')
    await submitForm('fam-unit-unit-form')

    expect(famApi.createFamilyUnit).toHaveBeenCalledWith({ code: 'UF-7' })
  })

  it('un 422 code_taken se muestra junto al campo y el diálogo sigue abierto', async () => {
    vi.mocked(famApi.createFamilyUnit).mockRejectedValue(
      new ApiError('x', 422, {
        type: 'urn:pge:error:validation',
        status: 422,
        detail: 'Datos no válidos.',
        errors: {
          code: [{ code: 'fam_unit.family_unit.code_taken', message: 'Ese código ya existe.' }],
        },
      }),
    )

    await open()

    buttonByText('Nueva unidad')!.click()
    await flushPromises()
    await fill('#fam-unit-code', 'UF-1')
    await submitForm('fam-unit-unit-form')

    const errors = document.body.querySelector('#fam-unit-code-errors')

    expect(errors?.textContent).toContain('Ese código ya existe.')
    expect(errors?.getAttribute('role')).toBe('alert')
    expect(document.body.querySelector('#fam-unit-code')!.getAttribute('aria-invalid')).toBe('true')
  })
})
