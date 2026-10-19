/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.8`, `§18.4.6`, `§18.9` (1.12-b, parte
 * web): `CA-ACAD-138` (alta de una reserva y `422 booking_conflict` como texto
 * junto a la acción, nombrando la reserva o la sesión con la que choca),
 * `CA-ACAD-139` (cancelar con confirmación; con `propios` el servidor decide y el
 * `404` se muestra junto a la acción) y `CA-ACAD-140` (curso cerrado: aviso
 * persistente y ninguna acción de escritura). `REQ-ACAD-002`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as acadApi from '../api'
import * as cursoApi from '@/modules/curso/api'
import {
  buttonByText,
  chooseOption,
  facility,
  facilityBooking,
  fill,
  mountView,
  page,
  slot,
  stubMatchMedia,
  timetableSession,
  year,
} from '../testSupport'

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
const { default: BookingsView } = await import('./BookingsView.vue')

const wrappers: VueWrapper[] = []
const ALL = ['reservas.leer', 'reservas.crear', 'reservas.eliminar', 'espacios.leer']

async function open(url = '/academico/reservas'): Promise<VueWrapper> {
  const wrapper = await mountView('acad-bookings', BookingsView, url)

  wrappers.push(wrapper)

  return wrapper
}

function problem(status: number, errors: Record<string, unknown[]>, detail = 'x'): ApiError {
  return new ApiError('x', status, { type: 'urn:pge:error:validation', status, detail, errors })
}

async function submit(): Promise<void> {
  document.body
    .querySelector<HTMLFormElement>('[data-slot="acad-booking-form"] form')!
    .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
  await flushPromises()
}

async function fillBooking(): Promise<void> {
  await chooseOption('#acad-booking-facility', 'CARRO1')
  await fill('#acad-booking-date', '2026-11-12')
  await fill('#acad-booking-starts', '10:00')
  await fill('#acad-booking-ends', '11:00')
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  Element.prototype.scrollIntoView = () => undefined
  window.localStorage.clear()
  resetSelectableYears()
  vi.mocked(cursoApi.listSelectableAcademicYears)
    .mockReset()
    .mockResolvedValue({ data: [year('Y2', '2026-2027', 'activo')] })
  for (const fn of Object.values(acadApi)) {
    if (typeof fn === 'function' && 'mockReset' in fn) (fn as ReturnType<typeof vi.fn>).mockReset()
  }
  vi.mocked(acadApi.listFacilityBookings).mockResolvedValue(
    page([
      facilityBooking(),
      facilityBooking({
        public_id: 'B2',
        facility: {
          public_id: 'F1',
          facility_type: 'aula',
          code: 'A21',
          name: 'Aula 2.1',
          deleted: false,
        },
        booked_on: '2026-11-13',
        starts_at: '09:00',
        ends_at: '09:30',
        booked_by: null,
      }),
    ]) as never,
  )
  vi.mocked(acadApi.listFacilities).mockResolvedValue(
    page([
      facility(),
      facility({
        public_id: 'F2',
        facility_type: 'recurso',
        code: 'CARRO1',
        name: 'Carro de portátiles',
      }),
    ]) as never,
  )
  session.__setPermissions(ALL)
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ACAD-138: listado de reservas', () => {
  it('pinta fecha, horario, espacio con su tipo y quién reservó; sin reservante, una raya', async () => {
    const wrapper = await open()
    const rows = wrapper.findAll('tbody tr').map((row) => row.text())

    expect(rows).toHaveLength(2)
    expect(rows[0]).toContain('10:00–11:00')
    expect(rows[0]).toContain('CARRO1')
    expect(rows[0]).toContain('Recurso')
    expect(rows[0]).toContain('Ana Ejemplo')
    expect(rows[1]).toContain('A21')
    expect(rows[1]).toContain('—')
  })

  it('el listado se pide con el curso, el espacio y el rango de fechas de la URL', async () => {
    await open(
      '/academico/reservas?academic_year=Y2&facility=F2&booked_from=2026-11-01&booked_to=2026-11-30',
    )

    expect(vi.mocked(acadApi.listFacilityBookings).mock.calls.at(-1)![0]).toMatchObject({
      academic_year: 'Y2',
      facility: ['F2'],
      booked_from: '2026-11-01',
      booked_to: '2026-11-30',
    })
  })

  it('sin curso activo, el estado «sin curso activo» (no un error)', async () => {
    vi.mocked(cursoApi.listSelectableAcademicYears).mockResolvedValue({ data: [] })

    const wrapper = await open()

    expect(wrapper.text()).toContain('No hay un curso académico activo')
    expect(buttonByText('Nueva reserva')).toBeUndefined()
  })
})

describe('CA-ACAD-138: nueva reserva', () => {
  it('«Nueva reserva» envía el curso, el espacio, la fecha y las horas, avisa y recarga el listado', async () => {
    vi.mocked(acadApi.createFacilityBooking).mockResolvedValue(facilityBooking() as never)

    const wrapper = await open()
    const loads = vi.mocked(acadApi.listFacilityBookings).mock.calls.length

    buttonByText('Nueva reserva')!.click()
    await flushPromises()
    await fillBooking()
    await submit()

    expect(acadApi.createFacilityBooking).toHaveBeenCalledWith({
      academic_year: 'Y2',
      facility: 'F2',
      booked_on: '2026-11-12',
      starts_at: '10:00',
      ends_at: '11:00',
    })
    expect(wrapper.text()).toContain('Reserva creada.')
    expect(vi.mocked(acadApi.listFacilityBookings).mock.calls.length).toBeGreaterThan(loads)
    // El selector de fecha se acota al curso (el servidor decide igualmente).
    expect(document.body.querySelector('[data-slot="acad-booking-form"]')).toBeNull()
  })

  it('RN-ACAD-141: un 422 booking_conflict nombra como texto la reserva y la sesión con las que choca, junto a la acción', async () => {
    vi.mocked(acadApi.createFacilityBooking).mockRejectedValue(
      problem(422, {
        booked_on: [
          {
            code: 'acad.validation.booking_conflict',
            message:
              'El espacio ya está reservado u ocupado por una sesión de horario en esa fecha y hora.',
            params: {
              conflicts: [
                { kind: 'booking', booking: 'B9' },
                { kind: 'session', session: 'SE9' },
              ],
            },
          },
        ],
      }),
    )
    vi.mocked(acadApi.getFacilityBooking).mockResolvedValue(
      facilityBooking({
        public_id: 'B9',
        booked_by: { public_id: 'U2', name: 'Beto Muestra' },
      }) as never,
    )
    vi.mocked(acadApi.getTimetableSession).mockResolvedValue(
      timetableSession({ public_id: 'SE9', slot: slot({ weekday: 2 }) }) as never,
    )

    await open()
    buttonByText('Nueva reserva')!.click()
    await flushPromises()
    await fillBooking()
    await submit()

    const box = document.body.querySelector('[data-slot="acad-booking-conflicts"]')!

    expect(box.getAttribute('role')).toBe('alert')
    expect(box.textContent).toContain('La reserva choca con')
    expect(box.textContent).toContain('Reserva de CARRO1')
    expect(box.textContent).toContain('de Beto Muestra')
    expect(box.textContent).toContain(
      'Sesión del horario: Matemáticas · A · Ana Ejemplo · Martes 08:30–09:25',
    )
    expect(acadApi.getFacilityBooking).toHaveBeenCalledWith('B9')
    expect(acadApi.getTimetableSession).toHaveBeenCalledWith('SE9')
    expect(document.body.querySelector('[data-slot="acad-booking-form"]')).not.toBeNull()
  })

  it('sin permiso para leer la otra entidad, el conflicto se dice solo por su tipo', async () => {
    vi.mocked(acadApi.createFacilityBooking).mockRejectedValue(
      problem(422, {
        booked_on: [
          {
            code: 'acad.validation.booking_conflict',
            message: 'x',
            params: { conflicts: [{ kind: 'session', session: 'SE9' }] },
          },
        ],
      }),
    )
    vi.mocked(acadApi.getTimetableSession).mockRejectedValue(
      new ApiError('x', 403, { type: 'urn:pge:error:forbidden', status: 403 }),
    )

    await open()
    buttonByText('Nueva reserva')!.click()
    await flushPromises()
    await fillBooking()
    await submit()

    expect(
      document.body.querySelector('[data-slot="acad-booking-conflicts"]')!.textContent,
    ).toContain('Una sesión del horario en ese aula a esa hora')
  })

  it('RN-ACAD-140: la fecha fuera del curso y los errores de campo se muestran junto a su campo', async () => {
    vi.mocked(acadApi.createFacilityBooking).mockRejectedValue(
      problem(422, {
        booked_on: [
          {
            code: 'acad.validation.booking_outside_academic_year',
            message: 'La fecha de la reserva debe estar dentro del curso académico.',
            params: {},
          },
        ],
        ends_at: [
          {
            code: 'acad.validation.slot_ends_before_start',
            message: 'La hora de fin debe ser posterior a la de inicio.',
            params: {},
          },
        ],
      }),
    )

    await open()
    buttonByText('Nueva reserva')!.click()
    await flushPromises()
    await fillBooking()
    await submit()

    expect(document.body.querySelector('#acad-booking-date-errors')!.textContent).toContain(
      'dentro del curso académico',
    )
    expect(document.body.querySelector('#acad-booking-ends-errors')!.textContent).toContain(
      'posterior a la de inicio',
    )
    expect(document.body.querySelector('[data-slot="acad-booking-conflicts"]')).toBeNull()
  })
})

describe('CA-ACAD-139: cancelar una reserva', () => {
  it('«Cancelar» pide confirmación y, solo si se confirma, cancela y recarga', async () => {
    vi.mocked(acadApi.deleteFacilityBooking).mockResolvedValue(undefined as never)

    const wrapper = await open()

    wrapper.get('button[aria-label^="Cancelar la reserva de CARRO1"]').trigger('click')
    await flushPromises()

    expect(acadApi.deleteFacilityBooking).not.toHaveBeenCalled()

    buttonByText('Cancelar reserva')!.click()
    await flushPromises()

    expect(acadApi.deleteFacilityBooking).toHaveBeenCalledWith('B1')
    expect(wrapper.text()).toContain('Reserva cancelada.')
  })

  it('con ámbito propios, una reserva ajena responde 404 y se dice junto a la acción sin tocar el listado', async () => {
    vi.mocked(acadApi.deleteFacilityBooking).mockRejectedValue(
      new ApiError('x', 404, {
        type: 'urn:pge:error:not-found',
        status: 404,
        detail: 'El recurso solicitado no existe.',
      }),
    )

    const wrapper = await open()
    const loads = vi.mocked(acadApi.listFacilityBookings).mock.calls.length

    wrapper.get('button[aria-label^="Cancelar la reserva de A21"]').trigger('click')
    await flushPromises()
    buttonByText('Cancelar reserva')!.click()
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('El recurso solicitado no existe.')
    expect(vi.mocked(acadApi.listFacilityBookings).mock.calls.length).toBe(loads)
  })

  it('cada acción exige su permiso: solo reservas.leer no tiene alta ni cancelación', async () => {
    session.__setPermissions(['reservas.leer'])

    const wrapper = await open()

    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    expect(buttonByText('Nueva reserva')).toBeUndefined()
    expect(wrapper.find('button[aria-label^="Cancelar la reserva"]').exists()).toBe(false)
    expect(acadApi.listFacilities).not.toHaveBeenCalled()
  })

  it('con reservas.crear y sin reservas.eliminar se puede reservar pero no cancelar', async () => {
    session.__setPermissions(['reservas.leer', 'reservas.crear', 'espacios.leer'])

    const wrapper = await open()

    expect(buttonByText('Nueva reserva')).toBeDefined()
    expect(wrapper.find('button[aria-label^="Cancelar la reserva"]').exists()).toBe(false)
  })
})

describe('CA-ACAD-140: curso de solo lectura', () => {
  it('con el curso cerrado hay aviso persistente de texto y ninguna acción de escritura (RN-ACAD-42)', async () => {
    vi.mocked(cursoApi.listSelectableAcademicYears).mockResolvedValue({
      data: [year('Y2', '2025-2026', 'cerrado')],
    })

    const wrapper = await open('/academico/reservas?academic_year=Y2')

    expect(wrapper.get('[data-slot="acad-bookings-read-only"]').text()).toContain('solo lectura')
    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    expect(buttonByText('Nueva reserva')).toBeUndefined()
    expect(wrapper.find('button[aria-label^="Cancelar la reserva"]').exists()).toBe(false)
  })
})
