/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.3`, `§23.9` (`1.14b-c2` T2), `REQ-PRL-004`: salida, entrada fuera de
 * horario y apertura de alerta desde la lista de puerta. `CA-PRL-017` (verificación sin fotografía), `-018`
 * (`Idempotency-Key`), `-019` (persona no autorizada), `-020` (fuera de horario), `-021` (alerta abierta), `-024`
 * (fotografía caída) y `-025` (aviso con o sin nombre). Datos ficticios (`REQ-SEED-005`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as prlApi from '../api'
import { useDoorContext } from '../composables/useDoorContext'
import {
  buttonByText,
  doorList,
  fill,
  mountView,
  pickupAlert,
  pickupEntry,
  stubMatchMedia,
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
const { default: DoorStudentView } = await import('./DoorStudentView.vue')

const wrappers: VueWrapper[] = []

async function open() {
  const mounted = await mountView('prl-door-student', DoorStudentView, '/conserjeria/alumnos/P1')

  wrappers.push(mounted.wrapper)

  return mounted
}

function text(): string {
  return document.body.textContent ?? ''
}

function el<T extends Element = HTMLElement>(selector: string): T {
  const found = document.body.querySelector<T>(selector)

  if (!found) throw new Error(`No existe ${selector}`)

  return found
}

async function click(target: HTMLElement | undefined): Promise<void> {
  if (!target) throw new Error('No existe el botón')

  target.click()
  await flushPromises()
}

async function chooseMethod(method: string): Promise<void> {
  await click(el(`#prl-movement-method-${method}`))
}

function submitButton(): HTMLButtonElement {
  return el<HTMLButtonElement>('[data-slot="prl-movement-dialog"] button[type="submit"]')
}

async function submit(): Promise<void> {
  await click(submitButton())
}

function movement(over: Record<string, unknown> = {}) {
  return {
    public_id: 'M1',
    student_person: 'P1',
    direction: 'salida',
    off_schedule: false,
    off_schedule_reason: null,
    notes: null,
    picked_up_by: { person: 'PE1', display_name: 'Rosa Ejemplo Vidal' },
    basis: 'tutor',
    verification_method: 'foto',
    delivered_by: 'ME',
    recorded_by: 'ME',
    corrects: null,
    correction_reason: null,
    occurred_at: '2026-11-03T09:40:00Z',
    ...over,
  }
}

function noPhotoEntry() {
  return pickupEntry({
    person: 'PE2',
    display_name: 'Iván Ejemplo Soto',
    bases: [{ basis: 'tercero', relationship: 'abuelo' }],
    has_photo: false,
    photo_url: null,
    contact_phone: null,
    document: null,
  })
}

function problem(
  status: number,
  body: Record<string, unknown>,
  headers: Record<string, string> = {},
) {
  return new ApiError(`HTTP ${status}`, status, body, new Headers(headers))
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  window.localStorage.clear()
  window.sessionStorage.clear()
  useDoorContext().clear()
  vi.mocked(prlApi.getDoorPickupList).mockReset()
  vi.mocked(prlApi.getDoorPickupList).mockResolvedValue(
    doorList([pickupEntry(), noPhotoEntry()]) as never,
  )
  vi.mocked(prlApi.createStudentMovement).mockReset()
  vi.mocked(prlApi.createStudentMovement).mockResolvedValue(movement() as never)
  vi.mocked(prlApi.createPickupAlert).mockReset()
  vi.mocked(prlApi.updatePickupAlert).mockReset()
  session.__setPermissions(['entrega_menor.leer', 'entrega_menor.crear', 'alerta_entrega.crear'])
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-PRL-017: sin fotografía solo se verifica con el documento (RN-PRL-06)', () => {
  it('has_photo = false: solo «Por el documento» habilitado y preseleccionado, con la explicación', async () => {
    await open()
    await click(buttonByText('Entregar a Iván Ejemplo Soto'))

    expect(el('#prl-movement-method-foto').hasAttribute('disabled')).toBe(true)
    expect(el('#prl-movement-method-foto_y_documento').hasAttribute('disabled')).toBe(true)
    expect(el('#prl-movement-method-documento').hasAttribute('disabled')).toBe(false)
    expect(el('#prl-movement-method-documento').getAttribute('aria-checked')).toBe('true')
    expect(text()).toContain(
      'Esta persona no tiene fotografía: solo puedes verificar con el documento.',
    )
  })

  it('has_photo = true con la imagen cargada: los tres métodos están habilitados', async () => {
    await open()
    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))

    for (const method of ['foto', 'documento', 'foto_y_documento']) {
      expect(el(`#prl-movement-method-${method}`).hasAttribute('disabled')).toBe(false)
    }

    expect(text()).not.toContain('Esta persona no tiene fotografía')
    expect(text()).not.toContain('No se puede mostrar la fotografía')
  })
})

describe('CA-PRL-018: idempotencia de la salida (RN-PRL-09, DP-4)', () => {
  it('el reintento lleva la misma clave; al cerrar y reabrir, otra; el botón se bloquea en curso', async () => {
    await open()
    vi.mocked(prlApi.createStudentMovement).mockRejectedValueOnce(
      new ApiError('sin red', 0, new TypeError('fail')),
    )
    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))
    await chooseMethod('foto')
    await submit()

    const calls = vi.mocked(prlApi.createStudentMovement).mock.calls

    expect(calls).toHaveLength(1)
    expect(calls[0]![0]).toMatchObject({
      direction: 'salida',
      picked_up_by: 'PE1',
      verification_method: 'foto',
      delivered_by: null,
    })
    expect(calls[0]![1]).toHaveLength(36)

    // Reintento del mismo envío: pendiente, con el botón deshabilitado; después, repetición del servidor.
    let resolveSecond: (value: unknown) => void = () => undefined

    vi.mocked(prlApi.createStudentMovement).mockImplementationOnce(
      (() => new Promise((resolve) => (resolveSecond = resolve))) as never,
    )
    await submit()

    expect(calls).toHaveLength(2)
    expect(calls[1]![1]).toBe(calls[0]![1])
    expect(submitButton().disabled).toBe(true)

    // Un doble toque mientras hay petición no envía una tercera.
    submitButton().click()
    await flushPromises()
    expect(calls).toHaveLength(2)

    // `Idempotency-Replayed: true` llega como un 201 normal: éxito.
    resolveSecond(movement())
    await flushPromises()
    expect(text()).toContain('Salida registrada')
    expect(document.body.querySelector('[data-slot="prl-movement-dialog"]')).toBeNull()

    // Otro diálogo ⇒ otra clave.
    vi.mocked(prlApi.createStudentMovement).mockRejectedValueOnce(
      new ApiError('sin red', 0, new TypeError('fail')),
    )
    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))
    await chooseMethod('foto')
    await submit()

    expect(calls).toHaveLength(3)
    expect(calls[2]![1]).toHaveLength(36)
    expect(calls[2]![1]).not.toBe(calls[0]![1])
  })

  it('cambiar el cuerpo tras un fallo genera otra clave', async () => {
    await open()
    vi.mocked(prlApi.createStudentMovement).mockRejectedValue(
      new ApiError('sin red', 0, new TypeError('fail')),
    )
    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))
    await chooseMethod('foto')
    await submit()
    await chooseMethod('documento')
    await submit()

    const calls = vi.mocked(prlApi.createStudentMovement).mock.calls

    expect(calls[1]![1]).not.toBe(calls[0]![1])
  })

  it('409 idempotency_mismatch se muestra y obliga a cerrar y reabrir (el envío queda bloqueado)', async () => {
    await open()
    vi.mocked(prlApi.createStudentMovement).mockRejectedValueOnce(
      problem(409, { detail: 'La clave de idempotencia ya se usó con otro cuerpo.' }),
    )
    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))
    await chooseMethod('foto')
    await submit()

    expect(text()).toContain('La clave de idempotencia ya se usó con otro cuerpo.')
    expect(submitButton().disabled).toBe(true)
  })

  it('429 se muestra con su espera y no reintenta', async () => {
    await open()
    vi.mocked(prlApi.createStudentMovement).mockRejectedValueOnce(
      problem(429, { detail: 'Demasiadas consultas.' }, { 'Retry-After': '90' }),
    )
    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))
    await chooseMethod('foto')
    await submit()

    expect(text()).toContain('Demasiadas consultas.')
    expect(text()).toContain('90')
    expect(prlApi.createStudentMovement).toHaveBeenCalledTimes(1)
  })
})

describe('CA-PRL-019: persona no autorizada al escribir (RN-PRL-02, OPEN-FAM-UNIT-34)', () => {
  const rejection = () =>
    problem(422, {
      detail: 'Esta persona no puede recoger al alumno.',
      errors: {
        picked_up_by: [
          {
            code: 'prl.movement.person_not_authorized',
            message: 'Esta persona no puede recoger al alumno.',
          },
        ],
      },
    })

  it('muestra el mensaje del servidor, no anuncia salida y ofrece recargar y, con permiso, abrir alerta', async () => {
    await open()
    vi.mocked(prlApi.createStudentMovement).mockRejectedValueOnce(rejection())
    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))
    await chooseMethod('foto')
    await submit()

    const dialog = el('[data-slot="prl-movement-dialog"]')

    expect(dialog.textContent).toContain('Esta persona no puede recoger al alumno.')
    expect(text()).not.toContain('Salida registrada')
    expect(dialog.textContent).toContain('Volver a cargar la lista')
    expect(dialog.textContent).toContain('Abrir una alerta')
    expect(submitButton().disabled).toBe(true)

    const reads = vi.mocked(prlApi.getDoorPickupList).mock.calls.length

    await click(
      [...dialog.querySelectorAll('button')].find((b) =>
        b.textContent?.includes('Volver a cargar'),
      ),
    )

    expect(prlApi.getDoorPickupList).toHaveBeenCalledTimes(reads + 1)
    expect(document.body.querySelector('[data-slot="prl-movement-dialog"]')).toBeNull()
  })

  it('«Abrir una alerta» abre el diálogo de alerta; sin alerta_entrega.crear no se ofrece', async () => {
    await open()
    vi.mocked(prlApi.createStudentMovement).mockRejectedValue(rejection())
    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))
    await chooseMethod('foto')
    await submit()
    await click(buttonByText('Abrir una alerta'))

    expect(document.body.querySelector('[data-slot="prl-alert-open-dialog"]')).not.toBeNull()

    while (wrappers.length > 0) wrappers.pop()!.unmount()
    document.body.innerHTML = ''
    session.__setPermissions(['entrega_menor.leer', 'entrega_menor.crear'])
    await open()
    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))
    await chooseMethod('foto')
    await submit()

    expect(text()).toContain('Esta persona no puede recoger al alumno.')
    expect(buttonByText('Abrir una alerta')).toBeUndefined()
  })
})

describe('CA-PRL-020: fuera de horario (RN-PRL-07, DP-3)', () => {
  it('sin motivo, o con «Otro» sin nota, el envío no sale; el 422 reason_required se pinta junto al campo', async () => {
    await open()
    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))
    await chooseMethod('foto')
    await click(el('input[type="checkbox"]'))
    await submit()

    expect(prlApi.createStudentMovement).not.toHaveBeenCalled()
    expect(el('#prl-movement-reason-errors').textContent).toContain('Elige el motivo.')

    await click(el('#prl-movement-reason-otro'))
    await submit()

    expect(prlApi.createStudentMovement).not.toHaveBeenCalled()
    expect(el('#prl-movement-notes-errors').textContent).toContain('Escribe una nota')

    vi.mocked(prlApi.createStudentMovement).mockRejectedValueOnce(
      problem(422, {
        errors: {
          notes: [{ code: 'prl.movement.reason_required', message: 'El motivo exige una nota.' }],
        },
      }),
    )
    await fill('#prl-movement-notes', 'Cita con el pediatra')
    await submit()

    expect(prlApi.createStudentMovement).toHaveBeenCalledTimes(1)
    expect(vi.mocked(prlApi.createStudentMovement).mock.calls[0]![0]).toMatchObject({
      off_schedule: true,
      off_schedule_reason: 'otro',
      notes: 'Cita con el pediatra',
    })
    expect(el('#prl-movement-notes-errors').textContent).toContain('El motivo exige una nota.')
  })

  it('la entrada fuera de horario envía direction = entrada sin picked_up_by ni verification_method', async () => {
    await open()
    vi.mocked(prlApi.createStudentMovement).mockResolvedValueOnce(
      movement({
        direction: 'entrada',
        picked_up_by: null,
        basis: null,
        verification_method: null,
      }) as never,
    )
    await click(buttonByText('Registrar entrada fuera de horario'))

    expect(document.body.querySelector('#prl-movement-method-foto')).toBeNull()

    await click(el('#prl-movement-reason-medico'))
    await submit()

    const body = vi.mocked(prlApi.createStudentMovement).mock.calls[0]![0] as unknown as Record<
      string,
      unknown
    >

    expect(body).toMatchObject({
      direction: 'entrada',
      student_person: 'P1',
      off_schedule: true,
      off_schedule_reason: 'medico',
    })
    expect('picked_up_by' in body).toBe(false)
    expect('verification_method' in body).toBe(false)
    expect(text()).toContain('Entrada registrada a las')
  })
})

describe('CA-PRL-021: alerta abierta (RN-PRL-08, DP-5, OPEN-FAM-UNIT-33)', () => {
  async function raise(permissions?: string[]) {
    if (permissions) session.__setPermissions(permissions)

    vi.mocked(prlApi.createPickupAlert).mockResolvedValue(pickupAlert() as never)
    await open()
    await click(buttonByText('Quien se presenta no está en la lista'))
    await fill('#prl-alert-presenter', 'Dice llamarse Pedro Ejemplo')
    await click(el('[data-slot="prl-alert-open-dialog"] button[type="submit"]'))
  }

  it('pinta el protocolo tal cual en una región con título, el teléfono como tel: y «No disponible», con anuncio assertive', async () => {
    await raise()

    expect(prlApi.createPickupAlert).toHaveBeenCalledTimes(1)
    expect(vi.mocked(prlApi.createPickupAlert).mock.calls[0]![0]).toEqual({
      student_person: 'P1',
      presenter_description: 'Dice llamarse Pedro Ejemplo',
    })
    expect(vi.mocked(prlApi.createPickupAlert).mock.calls[0]![1]).toHaveLength(36)

    const region = document.body.querySelector('section[aria-labelledby="prl-alert-protocol"]')

    expect(region).not.toBeNull()
    expect(region!.textContent).toContain('Qué hacer ahora')
    expect(region!.textContent).toContain('No entregues al alumno. Llama al tutor principal.')
    expect(document.body.querySelector('a[href="tel:600000001"]')).not.toBeNull()
    expect(text()).toContain('No disponible')

    const live = document.body.querySelector('[aria-live="assertive"]')

    expect(live?.textContent).toContain('Alerta abierta.')
  })

  it('sin alerta_entrega.actualizar no se ofrecen la llamada ni el cierre y aparece callManagement', async () => {
    await raise()

    expect(text()).toContain('Avisa a dirección para que registre la llamada y cierre la alerta.')
    expect(buttonByText('Guardar la llamada')).toBeUndefined()
    expect(buttonByText('Cerrar la alerta')).toBeUndefined()
  })

  it('con alerta_entrega.actualizar se ofrecen la llamada y el cierre', async () => {
    await raise(['entrega_menor.leer', 'alerta_entrega.crear', 'alerta_entrega.actualizar'])

    expect(text()).not.toContain('Avisa a dirección')
    expect(buttonByText('Guardar la llamada')).toBeDefined()
    expect(buttonByText('Cerrar la alerta')).toBeDefined()
  })

  it('429 en el alta: mensaje sin reintento y enlace a «Alertas de entrega» (con alerta_entrega.leer)', async () => {
    session.__setPermissions(['entrega_menor.leer', 'alerta_entrega.crear', 'alerta_entrega.leer'])
    await open()
    vi.mocked(prlApi.createPickupAlert).mockRejectedValueOnce(
      problem(429, { detail: 'Demasiadas consultas.' }, { 'Retry-After': '30' }),
    )
    await click(buttonByText('Quien se presenta no está en la lista'))
    await fill('#prl-alert-presenter', 'Dice llamarse Pedro Ejemplo')
    await click(el('[data-slot="prl-alert-open-dialog"] button[type="submit"]'))

    expect(text()).toContain('Demasiadas consultas.')
    expect(prlApi.createPickupAlert).toHaveBeenCalledTimes(1)
    expect(document.body.querySelector('a[href="/conserjeria/alertas"]')).not.toBeNull()
  })

  it('la descripción vacía no sale; el contador es visible', async () => {
    await open()
    await click(buttonByText('Quien se presenta no está en la lista'))

    expect(text()).toContain('0 de 500 caracteres')

    await click(el('[data-slot="prl-alert-open-dialog"] button[type="submit"]'))

    expect(prlApi.createPickupAlert).not.toHaveBeenCalled()
  })
})

describe('CA-PRL-024: fotografía caída ⇒ solo el documento (OPEN-FAM-UNIT-47)', () => {
  it('la imagen emite error: los métodos con foto se deshabilitan y se envía documento; al recargar vuelven los tres', async () => {
    await open()
    document.body.querySelector('img')!.dispatchEvent(new Event('error'))
    await flushPromises()
    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))

    expect(el('#prl-movement-method-foto').hasAttribute('disabled')).toBe(true)
    expect(el('#prl-movement-method-foto_y_documento').hasAttribute('disabled')).toBe(true)
    expect(el('#prl-movement-method-documento').getAttribute('aria-checked')).toBe('true')
    expect(text()).toContain('No se puede mostrar la fotografía: verifica solo con el documento.')

    await submit()

    expect(vi.mocked(prlApi.createStudentMovement).mock.calls[0]![0]).toMatchObject({
      verification_method: 'documento',
    })

    // «Volver a cargar la lista»: la imagen carga de nuevo y los tres métodos vuelven.
    await click(buttonByText('Volver a cargar la lista'))
    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))

    for (const method of ['foto', 'documento', 'foto_y_documento']) {
      expect(el(`#prl-movement-method-${method}`).hasAttribute('disabled')).toBe(false)
    }

    expect(text()).not.toContain('No se puede mostrar la fotografía')
  })

  it('la imagen del propio diálogo que falla también deja solo el documento', async () => {
    await open()
    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))

    const image = el<HTMLImageElement>('[data-slot="prl-movement-dialog"] img')

    expect(image.getAttribute('alt')).toBe('Fotografía de Rosa Ejemplo Vidal')

    image.dispatchEvent(new Event('error'))
    await flushPromises()

    expect(el('#prl-movement-method-foto').hasAttribute('disabled')).toBe(true)
    expect(el('#prl-movement-method-documento').getAttribute('aria-checked')).toBe('true')
  })
})

describe('CA-PRL-025: aviso de salida con o sin nombre del alumno (OPEN-FAM-UNIT-58, UI-3)', () => {
  it('desde la búsqueda: la cabecera y el aviso llevan el nombre; history.state y el almacenamiento, no', async () => {
    useDoorContext().remember('P1', {
      displayName: 'Lucas Ejemplo Ruiz',
      familyUnitCode: 'UF-000123',
    })
    await open()

    expect(document.body.querySelector('h1')!.textContent).toContain('Lucas Ejemplo Ruiz')

    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))
    await chooseMethod('foto')
    await submit()

    const notice = document.body.querySelector('[role="status"]')

    expect(notice!.textContent).toMatch(
      /^Salida registrada: Lucas Ejemplo Ruiz con Rosa Ejemplo Vidal a las /,
    )
    expect(JSON.stringify(window.history.state)).not.toContain('Lucas')
    expect(document.title).not.toContain('Lucas')
    expect(window.localStorage.length + window.sessionStorage.length).toBe(0)
  })

  it('por URL directa: la cabecera dice solo «Lista de recogida» y el aviso usa la forma sin alumno', async () => {
    await open()

    expect(document.body.querySelector('h1')!.textContent).toContain('Lista de recogida')

    await click(buttonByText('Entregar a Rosa Ejemplo Vidal'))
    await chooseMethod('foto')
    await submit()

    const notice = document.body.querySelector('[role="status"]')

    expect(notice!.textContent).toMatch(/^Salida registrada con Rosa Ejemplo Vidal a las /)
    expect(notice!.textContent).not.toContain('Lucas')
    expect(JSON.stringify(window.history.state)).not.toContain('Lucas')
  })
})
