/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.8`, `§18.9` (1.12-a, parte web):
 * `CA-ACAD-115` (alta, edición con el tipo inmutable, `422` junto a su campo),
 * `CA-ACAD-116` (`409 has_dependents` junto a la acción) y `CA-ACAD-134`
 * (acciones por permiso; los espacios no dependen del curso académico, así que
 * siguen editables con el curso cerrado). `REQ-ACAD-002`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as acadApi from '../api'
import * as cursoApi from '@/modules/curso/api'
import { buttonByText, facility, fill, mountView, page, stubMatchMedia, year } from '../testSupport'

vi.mock('../api')
vi.mock('@/modules/curso/api')

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
const { resetSelectableYears } = await import('@/academic-year')
const { default: FacilitiesView } = await import('./FacilitiesView.vue')

const wrappers: VueWrapper[] = []

const ALL = ['espacios.leer', 'espacios.crear', 'espacios.actualizar', 'espacios.eliminar']

async function open(url = '/academico/espacios'): Promise<VueWrapper> {
  const wrapper = await mountView('acad-facilities', FacilitiesView, url)

  wrappers.push(wrapper)

  return wrapper
}

function problem(status: number, errors: Record<string, unknown[]>, detail = 'x'): ApiError {
  return new ApiError('x', status, { type: 'urn:pge:error:validation', status, detail, errors })
}

async function submit(): Promise<void> {
  document.body
    .querySelector<HTMLFormElement>('[data-slot="acad-facility-form"] form')!
    .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
  await flushPromises()
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  Element.prototype.scrollIntoView = () => undefined
  window.localStorage.clear()
  resetSelectableYears()
  vi.mocked(cursoApi.listSelectableAcademicYears)
    .mockReset()
    .mockResolvedValue({ data: [year('Y1', '2024-2025', 'cerrado')] })
  for (const fn of Object.values(acadApi)) {
    if (typeof fn === 'function' && 'mockReset' in fn) (fn as ReturnType<typeof vi.fn>).mockReset()
  }
  vi.mocked(acadApi.listFacilities).mockResolvedValue(
    page([
      facility(),
      facility({
        public_id: 'F2',
        facility_type: 'recurso',
        code: 'CARRO1',
        name: 'Carro de portátiles',
      }),
      facility({ public_id: 'F3', facility_type: 'laboratorio', code: 'LAB', name: 'Laboratorio' }),
    ]) as never,
  )
  session.__setPermissions(ALL)
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ACAD-115: listado de espacios', () => {
  it('pinta código, nombre y tipo con su etiqueta; un tipo sin clave cae al código en crudo', async () => {
    const wrapper = await open()
    const rows = wrapper.findAll('tbody tr').map((row) => row.text())

    expect(rows).toHaveLength(3)
    expect(rows[0]).toContain('A21')
    expect(rows[0]).toContain('Aula 2.1')
    expect(rows[0]).toContain('Aula')
    expect(rows[1]).toContain('Recurso')
    expect(rows[2]).toContain('laboratorio')
  })

  it('no hay selector de curso ni aviso de solo lectura: los espacios son del centro (OPEN-ACAD-33)', async () => {
    const wrapper = await open()

    expect(wrapper.find('[data-slot="acad-read-only"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('solo lectura')
  })

  it('el listado se pide con el filtro de la URL (tipo múltiple) y el orden', async () => {
    await open('/academico/espacios?facility_type=aula,recurso&sort=-code')

    const params = vi.mocked(acadApi.listFacilities).mock.calls.at(-1)![0]

    expect(params).toMatchObject({ facility_type: ['aula', 'recurso'], sort: '-code' })
  })
})

describe('CA-ACAD-134: acciones por permiso', () => {
  it('con solo espacios.leer no hay alta, edición ni borrado', async () => {
    session.__setPermissions(['espacios.leer'])

    const wrapper = await open()

    expect(buttonByText('Nuevo espacio')).toBeUndefined()
    expect(wrapper.find('button[aria-label="Editar el espacio A21"]').exists()).toBe(false)
    expect(wrapper.find('button[aria-label="Borrar el espacio A21"]').exists()).toBe(false)
  })

  it('con los cuatro permisos hay alta y, por fila, editar y borrar con su nombre accesible', async () => {
    const wrapper = await open()

    expect(buttonByText('Nuevo espacio')).toBeDefined()
    expect(wrapper.find('button[aria-label="Editar el espacio A21"]').exists()).toBe(true)
    expect(wrapper.find('button[aria-label="Borrar el espacio A21"]').exists()).toBe(true)
  })
})

describe('CA-ACAD-115: alta y edición', () => {
  it('el alta envía tipo, código y nombre, refresca el listado y avisa', async () => {
    vi.mocked(acadApi.createFacility).mockResolvedValue(facility() as never)

    const wrapper = await open()

    buttonByText('Nuevo espacio')!.click()
    await flushPromises()

    // Tipo: el Select de Reka UI se abre con teclado.
    const trigger = document.body.querySelector<HTMLElement>('#acad-facility-type')!

    trigger.focus()
    trigger.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
    await flushPromises()
    const option = [...document.body.querySelectorAll<HTMLElement>('[role="option"]')].find(
      (item) => item.textContent?.trim() === 'Recurso',
    )!

    option.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerType: 'mouse' }))
    option.click()
    await flushPromises()

    await fill('#acad-facility-code', ' CARRO2 ')
    await fill('#acad-facility-name', 'Carro 2')
    await submit()

    expect(acadApi.createFacility).toHaveBeenCalledWith({
      facility_type: 'recurso',
      code: 'CARRO2',
      name: 'Carro 2',
    })
    expect(vi.mocked(acadApi.listFacilities).mock.calls.length).toBeGreaterThan(1)
    expect(wrapper.text()).toContain('Espacio creado.')
  })

  it('la edición manda solo lo modificado, con el tipo inmutable (deshabilitado y explicado)', async () => {
    vi.mocked(acadApi.updateFacility).mockResolvedValue(facility({ name: 'Aula 2.1 B' }) as never)

    const wrapper = await open()

    wrapper.find<HTMLButtonElement>('button[aria-label="Editar el espacio A21"]').element.click()
    await flushPromises()

    expect(document.body.querySelector('#acad-facility-type')!.hasAttribute('disabled')).toBe(true)
    expect(document.body.textContent).toContain('no se puede cambiar después del alta')

    await fill('#acad-facility-name', 'Aula 2.1 B')
    await submit()

    expect(acadApi.updateFacility).toHaveBeenCalledWith('F1', { name: 'Aula 2.1 B' })
    expect(wrapper.text()).toContain('Espacio actualizado.')
  })

  it('sin cambios no se envía nada y se cierra el diálogo', async () => {
    const wrapper = await open()

    wrapper.find<HTMLButtonElement>('button[aria-label="Editar el espacio A21"]').element.click()
    await flushPromises()
    await submit()

    expect(acadApi.updateFacility).not.toHaveBeenCalled()
    expect(document.body.querySelector('[data-slot="acad-facility-form"]')).toBeNull()
  })

  it('un 422 code_taken se muestra junto al campo, ya traducido, y el diálogo sigue abierto', async () => {
    vi.mocked(acadApi.createFacility).mockRejectedValue(
      problem(422, {
        code: [
          {
            code: 'acad.validation.code_taken',
            message: 'Ya existe un elemento con ese código entre sus hermanos.',
            params: {},
          },
        ],
      }),
    )

    await open()
    buttonByText('Nuevo espacio')!.click()
    await flushPromises()
    await fill('#acad-facility-code', 'A21')
    await fill('#acad-facility-name', 'Otra')
    await submit()

    expect(document.body.querySelector('#acad-facility-code-errors')!.textContent).toContain(
      'Ya existe',
    )
    expect(document.body.querySelector('#acad-facility-code')!.getAttribute('aria-invalid')).toBe(
      'true',
    )
    expect(document.body.querySelector('[data-slot="acad-facility-form"]')).not.toBeNull()
  })
})

describe('CA-ACAD-116: borrado', () => {
  it('pide confirmación, borra y refresca', async () => {
    vi.mocked(acadApi.deleteFacility).mockResolvedValue(undefined as never)

    const wrapper = await open()

    wrapper.find<HTMLButtonElement>('button[aria-label="Borrar el espacio A21"]').element.click()
    await flushPromises()
    buttonByText('Borrar A21')!.click()
    await flushPromises()

    expect(acadApi.deleteFacility).toHaveBeenCalledWith('F1')
    expect(wrapper.text()).toContain('Espacio borrado.')
  })

  it('un 409 has_dependents se muestra con el mensaje del servidor y los dependientes nombrados', async () => {
    vi.mocked(acadApi.deleteFacility).mockRejectedValue(
      new ApiError('x', 409, {
        type: 'urn:pge:error:conflict',
        status: 409,
        detail: 'No se puede borrar «A21» (espacio): tiene elementos dependientes.',
        errors: {
          dependents: [
            {
              code: 'acad.conflict.has_dependents',
              message: 'x',
              params: { dependents: ['timetable_sessions'] },
            },
          ],
        },
      }),
    )

    const wrapper = await open()

    wrapper.find<HTMLButtonElement>('button[aria-label="Borrar el espacio A21"]').element.click()
    await flushPromises()
    buttonByText('Borrar A21')!.click()
    await flushPromises()

    const alert = wrapper.find('[role="alert"]')

    expect(alert.text()).toContain('No se puede borrar «A21»')
    expect(alert.text()).toContain('Sesiones de horario')
  })
})
