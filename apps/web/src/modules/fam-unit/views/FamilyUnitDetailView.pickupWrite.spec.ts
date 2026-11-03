/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.4.2`/`§23.4.3` (`1.14b-c1` T2, escritura), `REQ-FAM-UNIT-005`:
 * `CA-FAM-UNIT-132` (acciones según permiso), `-136` (asistente persona → autorización → fotografía), `-137` (fotografía),
 * `-138` (canal y tipo), `-139` (retirada y fecha de fin), `-140` (ficha de la persona), `-144` (sin persistencia local),
 * `-145` (`texto_legal.leer`, `OPEN-FAM-UNIT-55`) y `-146` (sin `.actualizar`). Datos ficticios (`REQ-SEED-005`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as famApi from '../api'
import {
  buttonByLabel,
  buttonByText,
  chooseOption,
  familyUnit,
  fill,
  guardian,
  mountView,
  page,
  pickupAuthorization,
  stubMatchMedia,
  student,
  termsVersion,
} from '../testSupport'

vi.mock('../api')
vi.mock('@/modules/core/api', () => ({ listUsers: vi.fn() }))
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
const { default: FamilyUnitDetailView } = await import('./FamilyUnitDetailView.vue')

const wrappers: VueWrapper[] = []
const READ = ['familia.leer', 'autorizado_recogida.leer']
const STAFF = [
  ...READ,
  'autorizado_recogida.crear',
  'autorizado_recogida.actualizar',
  'autorizado_recogida.eliminar',
  'texto_legal.leer',
]

const PUBLISHED = termsVersion({
  public_id: 'V9',
  kind: 'autorizacion_recogida',
  version_number: 2,
  status: 'publicada',
})

function problem(status: number, detail: string, errors: Record<string, unknown[]> = {}): ApiError {
  return new ApiError('x', status, { type: 'urn:pge:error:x', status, detail, errors })
}

async function open() {
  const mounted = await mountView('fam-unit-detail', FamilyUnitDetailView, '/familias/U1')

  wrappers.push(mounted.wrapper)

  return mounted
}

function text(): string {
  return document.body.textContent ?? ''
}

async function pickStudent(): Promise<void> {
  await chooseOption('#fam-unit-pickup-student', 'Lucas Ejemplo Ruiz')
}

/** Pulsa el botón de ese texto, buscando primero dentro del último diálogo abierto (la tabla repite etiquetas). */
async function click(label: string): Promise<void> {
  const dialogs = document.body.querySelectorAll('[role="dialog"]')
  const scope = dialogs.length > 0 ? dialogs[dialogs.length - 1]! : document.body
  const button = [...scope.querySelectorAll<HTMLButtonElement>('button')].find((item) =>
    item.textContent?.trim().includes(label),
  )

  if (!button) throw new Error(`No hay botón «${label}»`)

  button.click()
  await flushPromises()
}

/** Simula elegir un fichero en un `<input type="file">`. */
async function chooseFile(name: string, type: string, size: number): Promise<void> {
  const input = document.body.querySelector<HTMLInputElement>('input[type="file"]')!
  const file = new File(['x'], name, { type })

  Object.defineProperty(file, 'size', { value: size })
  Object.defineProperty(input, 'files', { configurable: true, value: [file] })
  input.dispatchEvent(new Event('change', { bubbles: true }))
  await flushPromises()
}

const NEW_PERSON = {
  person: 'PP9',
  display_name: 'Rosa Ejemplo Vidal',
  has_photo: false,
  reused: false,
  data_mismatch: false,
}

/** Recorre el paso 1 por el camino del alta (con `.actualizar`: busca y no hay coincidencia). */
async function fillPersonStep(search = true): Promise<void> {
  await fill('#fam-unit-pickup-doc-number', '00000000X')

  if (search) await click('Buscar')

  await fill('#fam-unit-pickup-given', 'Rosa')
  await fill('#fam-unit-pickup-family1', 'Ejemplo')
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  window.localStorage.clear()
  window.sessionStorage.clear()
  Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:preview-1'), revokeObjectURL: vi.fn() })
  for (const fn of Object.values(famApi)) {
    if (typeof fn === 'function' && 'mockReset' in fn) (fn as ReturnType<typeof vi.fn>).mockReset()
  }
  vi.mocked(famApi.getFamilyUnit).mockResolvedValue(familyUnit() as never)
  vi.mocked(famApi.listGuardians).mockResolvedValue(page([guardian()]) as never)
  vi.mocked(famApi.listFamilyUnitStudents).mockResolvedValue({ data: [student()] } as never)
  vi.mocked(famApi.listPickupAuthorizations).mockResolvedValue(
    page([pickupAuthorization()]) as never,
  )
  vi.mocked(famApi.listTermsVersions).mockResolvedValue(page([PUBLISHED]) as never)
  vi.mocked(famApi.findPickupPerson).mockResolvedValue({ data: [] })
  vi.mocked(famApi.createPickupPerson).mockResolvedValue(NEW_PERSON as never)
  vi.mocked(famApi.createPickupAuthorization).mockResolvedValue(pickupAuthorization() as never)
  vi.mocked(famApi.putPickupPersonPhoto).mockResolvedValue(NEW_PERSON as never)
  session.__setPermissions(STAFF)
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-FAM-UNIT-132: las acciones salen solo con su permiso de /me', () => {
  it('con .leer y .crear: alta, pero ninguna acción de fila de escritura', async () => {
    session.__setPermissions([...READ, 'autorizado_recogida.crear', 'texto_legal.leer'])
    await open()
    await pickStudent()

    expect(buttonByText('Nueva autorización')).toBeDefined()
    expect(buttonByLabel('Subir fotografía de Rosa Ejemplo Vidal')).toBeUndefined()
    expect(buttonByLabel('Ver los datos de Rosa Ejemplo Vidal')).toBeUndefined()
    expect(document.body.querySelector('button[aria-label^="Retirar"]')).toBeNull()
  })

  it('con .actualizar y .eliminar: acciones de fila, sin botón de alta', async () => {
    session.__setPermissions([
      ...READ,
      'autorizado_recogida.actualizar',
      'autorizado_recogida.eliminar',
    ])
    await open()
    await pickStudent()

    expect(buttonByText('Nueva autorización')).toBeUndefined()
    expect(buttonByLabel('Cambiar la fecha de fin de Rosa Ejemplo Vidal')).toBeDefined()
    expect(buttonByLabel('Subir fotografía de Rosa Ejemplo Vidal')).toBeDefined()
    expect(buttonByLabel('Ver los datos de Rosa Ejemplo Vidal')).toBeDefined()
    expect(
      buttonByLabel('Retirar la autorización de Rosa Ejemplo Vidal para Lucas Ejemplo Ruiz'),
    ).toBeDefined()
  })
})

describe('CA-FAM-UNIT-145: texto_legal.leer para ofrecer el alta (OPEN-FAM-UNIT-55 A)', () => {
  it('con .crear y sin texto_legal.leer el botón está deshabilitado, explicado, y no se piden las versiones', async () => {
    session.__setPermissions([...READ, 'autorizado_recogida.crear'])
    await open()

    const button = buttonByText('Nueva autorización')!
    const hint = document.getElementById(button.getAttribute('aria-describedby')!)

    expect(button.disabled).toBe(true)
    expect(hint?.textContent).toContain('permiso para consultar los textos legales')
    expect(famApi.listTermsVersions).not.toHaveBeenCalled()
  })

  it('con ambos permisos, al abrir pide las versiones publicada y retirada y deja preseleccionada la publicada', async () => {
    await open()
    await pickStudent()
    await click('Nueva autorización')

    expect(famApi.listTermsVersions).toHaveBeenCalledTimes(1)
    expect(vi.mocked(famApi.listTermsVersions).mock.calls[0]![0]).toMatchObject({
      kind: 'autorizacion_recogida',
      status: 'publicada,retirada',
    })

    await fillPersonStep()
    await click('Siguiente')

    expect(document.body.querySelector('#fam-unit-pickup-auth-terms')?.textContent).toContain(
      'Versión 2 (Publicada)',
    )
  })
})

describe('CA-FAM-UNIT-136: asistente persona → autorización → fotografía', () => {
  it('sale en orden POST persona, POST autorización (authorized_by = principal) y PUT foto; avisa de la persona reutilizada', async () => {
    vi.mocked(famApi.createPickupPerson).mockResolvedValue({
      ...NEW_PERSON,
      reused: true,
      data_mismatch: true,
    } as never)
    await open()
    await pickStudent()
    await click('Nueva autorización')
    await fillPersonStep()
    await click('Siguiente')

    expect(text()).toContain('Ya existía una persona con ese documento')
    expect(text()).toContain('no coinciden con los registrados')
    expect(famApi.findPickupPerson).toHaveBeenCalledWith('dni', '00000000X')
    expect(document.body.querySelector<HTMLInputElement>('#fam-unit-pickup-auth-by')!.value).toBe(
      'Marta Ejemplo Ruiz',
    )

    await click('Siguiente')
    await chooseFile('foto.png', 'image/png', 1000)
    await click('Subir fotografía')

    const order = (fn: unknown) =>
      (fn as { mock: { invocationCallOrder: number[] } }).mock.invocationCallOrder[0]!

    expect(order(famApi.createPickupPerson)).toBeLessThan(order(famApi.createPickupAuthorization))
    expect(order(famApi.createPickupAuthorization)).toBeLessThan(order(famApi.putPickupPersonPhoto))
    expect(famApi.createPickupAuthorization).toHaveBeenCalledWith(
      expect.objectContaining({
        authorized_by: 'G1',
        person: 'PP9',
        student_person: 'P1',
        terms_version: 'V9',
        kind: 'permanente',
        channel: 'presencial',
      }),
    )
    expect(famApi.putPickupPersonPhoto).toHaveBeenCalledWith('PP9', expect.any(File))
  })

  it('una unidad sin tutor principal deja el alta deshabilitada con su explicación asociada', async () => {
    vi.mocked(famApi.getFamilyUnit).mockResolvedValue(
      familyUnit({ principal_guardian: null }) as never,
    )
    await open()

    const button = buttonByText('Nueva autorización')!

    expect(button.disabled).toBe(true)
    expect(
      document.getElementById(button.getAttribute('aria-describedby')!)?.textContent,
    ).toContain('necesita un tutor principal')
  })

  it('un 422 text_version_invalid sale junto al selector de versión y el asistente no avanza', async () => {
    vi.mocked(famApi.createPickupAuthorization).mockRejectedValue(
      problem(422, 'Versión no válida.', {
        terms_version: [
          { code: 'pickup.text_version_invalid', message: 'La versión elegida no es válida.' },
        ],
      }),
    )
    await open()
    await pickStudent()
    await click('Nueva autorización')
    await fillPersonStep()
    await click('Siguiente')
    await click('Siguiente')

    const errors = document.body.querySelector('#fam-unit-pickup-auth-terms_version-errors')

    expect(errors?.textContent).toContain('La versión elegida no es válida.')
    expect(famApi.putPickupPersonPhoto).not.toHaveBeenCalled()
    expect(document.body.querySelector('input[type="file"]')).toBeNull()
  })
})

describe('CA-FAM-UNIT-136 (regresión de revisión): una búsqueda resuelta no sobrevive a editar el documento', () => {
  it('buscar A, editar a B y pulsar «Siguiente» no avanza con A: exige buscar de nuevo', async () => {
    vi.mocked(famApi.findPickupPerson)
      .mockResolvedValueOnce({
        data: [
          {
            person: 'PPA',
            display_name: 'Persona Ejemplo A',
            document: { type: 'dni', number: 'AAA' },
          },
        ],
      } as never)
      .mockResolvedValueOnce({ data: [] })
    await open()
    await pickStudent()
    await click('Nueva autorización')
    await fill('#fam-unit-pickup-doc-number', 'AAA')
    await click('Buscar')

    expect(text()).toContain('Persona Ejemplo A')

    await fill('#fam-unit-pickup-doc-number', 'BBB')

    expect(text()).not.toContain('Persona Ejemplo A')
    expect(buttonByText('Usar esta persona')).toBeUndefined()

    await click('Siguiente')

    // No avanzó con A: la clave nueva obliga a buscar (la pulsación hace la búsqueda) y sigue en el paso 1.
    expect(famApi.findPickupPerson).toHaveBeenLastCalledWith('dni', 'BBB')
    expect(famApi.createPickupAuthorization).not.toHaveBeenCalled()
    expect(
      document.body.querySelector('[data-slot="fam-unit-pickup-wizard"]')?.textContent,
    ).toContain('1. Persona')
    expect(text()).not.toContain('Persona Ejemplo A')
  })
})

describe('CA-FAM-UNIT-136 (regresión de revisión): una respuesta de búsqueda tardía no pisa un documento más reciente', () => {
  it('buscar A, editar a B antes de que responda y resolver A: A no aparece ni permite avanzar y busy se restablece', async () => {
    let resolveA!: (value: unknown) => void

    vi.mocked(famApi.findPickupPerson).mockReturnValueOnce(
      new Promise((resolve) => {
        resolveA = resolve
      }) as never,
    )
    await open()
    await pickStudent()
    await click('Nueva autorización')
    await fill('#fam-unit-pickup-doc-number', 'AAA')
    await click('Buscar')
    await fill('#fam-unit-pickup-doc-number', 'BBB')

    resolveA({
      data: [
        {
          person: 'PPA',
          display_name: 'Persona Ejemplo A',
          document: { type: 'dni', number: 'AAA' },
        },
      ],
    })
    await flushPromises()

    expect(text()).not.toContain('Persona Ejemplo A')
    expect(buttonByText('Usar esta persona')).toBeUndefined()
    expect(famApi.createPickupAuthorization).not.toHaveBeenCalled()

    // `busy` se restableció: «Buscar» y «Siguiente» responden de nuevo (B no figura: pasa al alta).
    vi.mocked(famApi.findPickupPerson).mockResolvedValueOnce({ data: [] })
    await click('Buscar')

    expect(famApi.findPickupPerson).toHaveBeenLastCalledWith('dni', 'BBB')
    expect(text()).toContain('No hay ninguna persona con ese documento.')
  })
})

describe('Regresión de revisión: la ficha de una persona descarta la respuesta de otra anterior', () => {
  it('abrir la ficha de A, cerrarla y abrir la de B: al resolver A tarde no se pinta', async () => {
    let resolveA!: (value: unknown) => void

    vi.mocked(famApi.getPickupPerson)
      .mockReturnValueOnce(
        new Promise((resolve) => {
          resolveA = resolve
        }) as never,
      )
      .mockResolvedValueOnce({
        person: 'PP2',
        display_name: 'Persona Ejemplo B',
        document: { type: 'dni', number: 'BBB' },
        contact_phone: null,
        has_photo: true,
      } as never)
    vi.mocked(famApi.listPickupAuthorizations).mockResolvedValue(
      page([
        pickupAuthorization({
          public_id: 'A1',
          person: { public_id: 'PP1', display_name: 'Persona Ejemplo A' },
        }),
        pickupAuthorization({
          public_id: 'A2',
          person: { public_id: 'PP2', display_name: 'Persona Ejemplo B' },
        }),
      ]) as never,
    )
    await open()
    await pickStudent()
    buttonByLabel('Ver los datos de Persona Ejemplo A')!.click()
    await flushPromises()
    await click('Cerrar')
    buttonByLabel('Ver los datos de Persona Ejemplo B')!.click()
    await flushPromises()

    resolveA({
      person: 'PP1',
      display_name: 'Persona Ejemplo A',
      document: { type: 'dni', number: 'AAA' },
      contact_phone: '600000001',
      has_photo: true,
    })
    await flushPromises()

    const input = document.body.querySelector<HTMLInputElement>('#fam-unit-pickup-person-document')!

    expect(input.value).toContain('BBB')
    expect(input.value).not.toContain('AAA')
  })
})

describe('CA-FAM-UNIT-137: fotografía', () => {
  async function toPhotoStep(): Promise<void> {
    await open()
    await pickStudent()
    await click('Nueva autorización')
    await fillPersonStep()
    await click('Siguiente')
    await click('Siguiente')
  }

  it('cerrar sin subirla deja la autorización no vigente con la acción «Subir fotografía de {persona}»', async () => {
    await toPhotoStep()
    vi.mocked(famApi.listPickupAuthorizations).mockResolvedValue(
      page([pickupAuthorization({ is_effective: false, ineffective_reason: 'sin_foto' })]) as never,
    )
    await click('Cerrar sin fotografía')

    expect(famApi.putPickupPersonPhoto).not.toHaveBeenCalled()
    expect(text()).toContain('No vigente: falta la fotografía')
    expect(buttonByLabel('Subir fotografía de Rosa Ejemplo Vidal')).toBeDefined()
  })

  it('un fichero de 6 MB o un .gif no se envía y avisa en cliente', async () => {
    await toPhotoStep()
    await chooseFile('grande.png', 'image/png', 6 * 1024 * 1024)

    expect(text()).toContain('El fichero supera 5 MB.')
    expect(
      [...document.body.querySelectorAll('[role="dialog"] button')]
        .find((b) => b.textContent?.includes('Subir fotografía'))!
        .hasAttribute('disabled'),
    ).toBe(true)

    await chooseFile('animada.gif', 'image/gif', 1000)

    expect(text()).toContain('Solo se admiten JPEG, PNG o WebP.')
    expect(famApi.putPickupPersonPhoto).not.toHaveBeenCalled()
  })

  it.each([
    [413, 'El fichero es demasiado grande.', {}],
    [
      422,
      'Dimensiones no válidas.',
      {
        file: [
          {
            code: 'fam_unit.photo.dimensions',
            message: 'Las dimensiones de la imagen no son válidas.',
          },
        ],
      },
    ],
  ])(
    'un %i del servidor sale junto al campo y no cambia la autorización',
    async (status, detail, errors) => {
      vi.mocked(famApi.putPickupPersonPhoto).mockRejectedValue(problem(status, detail, errors))
      await toPhotoStep()
      await chooseFile('foto.png', 'image/png', 1000)
      await click('Subir fotografía')

      const alert = document.body.querySelector('#fam-unit-pickup-photo-step-errors')

      expect(alert?.textContent).toContain(
        Object.keys(errors).length > 0 ? 'Las dimensiones de la imagen no son válidas.' : detail,
      )
      expect(document.body.querySelector('[role="dialog"]')).not.toBeNull()
    },
  )
})

describe('CA-FAM-UNIT-138: tipo y canal', () => {
  it('permanente: telefono deshabilitado con explicación; puntual: sin valid_until, motivo obligatorio y telefono elegible', async () => {
    await open()
    await pickStudent()
    await click('Nueva autorización')
    await fillPersonStep()
    await click('Siguiente')

    expect(text()).toContain('Por teléfono solo se registran autorizaciones de un día.')
    expect(document.body.querySelector('#fam-unit-pickup-auth-until')).not.toBeNull()

    await chooseOption('#fam-unit-pickup-auth-kind', 'Puntual (un día)')

    expect(document.body.querySelector('#fam-unit-pickup-auth-until')).toBeNull()
    expect(document.body.querySelector('#fam-unit-pickup-auth-reason')).not.toBeNull()
    expect(text()).not.toContain('Por teléfono solo se registran autorizaciones de un día.')

    await click('Siguiente')

    expect(famApi.createPickupAuthorization).not.toHaveBeenCalled()
    expect(text()).toContain('El motivo es obligatorio')

    await chooseOption('#fam-unit-pickup-auth-channel', 'Por teléfono (con devolución de llamada)')
    await fill('#fam-unit-pickup-auth-reason', 'Recoge una tía')
    await click('Siguiente')

    expect(famApi.createPickupAuthorization).toHaveBeenCalledWith(
      expect.objectContaining({
        kind: 'puntual',
        channel: 'telefono',
        valid_until: null,
        reason: 'Recoge una tía',
      }),
    )
  })

  it('un 422 channel_requires_puntual sale junto al canal', async () => {
    vi.mocked(famApi.createPickupAuthorization).mockRejectedValue(
      problem(422, 'x', {
        channel: [
          {
            code: 'pickup.channel_requires_puntual',
            message: 'El canal teléfono exige una autorización puntual.',
          },
        ],
      }),
    )
    await open()
    await pickStudent()
    await click('Nueva autorización')
    await fillPersonStep()
    await click('Siguiente')
    await click('Siguiente')

    expect(
      document.body.querySelector('#fam-unit-pickup-auth-channel-errors')?.textContent,
    ).toContain('El canal teléfono exige una autorización puntual.')
  })
})

describe('CA-FAM-UNIT-139: retirada y fecha de fin', () => {
  const WITHDRAW = 'Retirar la autorización de Rosa Ejemplo Vidal para Lucas Ejemplo Ruiz'

  it('retirar pide confirmación con el texto de irreversibilidad y solo tras confirmar sale la petición', async () => {
    vi.mocked(famApi.withdrawPickupAuthorization).mockResolvedValue(pickupAuthorization() as never)
    await open()
    await pickStudent()
    buttonByLabel(WITHDRAW)!.click()
    await flushPromises()

    expect(text()).toContain('La retirada no se puede deshacer')
    expect(famApi.withdrawPickupAuthorization).not.toHaveBeenCalled()

    const confirm = [
      ...document.body.querySelectorAll<HTMLButtonElement>('[role="alertdialog"] button'),
    ].find((b) => b.textContent?.includes('Retirar la autorización'))!

    confirm.click()
    await flushPromises()

    expect(famApi.withdrawPickupAuthorization).toHaveBeenCalledWith('PA1')
  })

  it('un 409 invalid_transition se muestra y la fila no cambia', async () => {
    vi.mocked(famApi.withdrawPickupAuthorization).mockRejectedValue(
      problem(409, 'La autorización ya estaba retirada.'),
    )
    await open()
    await pickStudent()
    buttonByLabel(WITHDRAW)!.click()
    await flushPromises()
    ;[...document.body.querySelectorAll<HTMLButtonElement>('[role="alertdialog"] button')]
      .find((b) => b.textContent?.includes('Retirar la autorización'))!
      .click()
    await flushPromises()

    expect(text()).toContain('La autorización ya estaba retirada.')
    expect(text()).toContain('Vigente')
  })

  it('una puntual o una retirada no ofrecen «Cambiar la fecha de fin»', async () => {
    vi.mocked(famApi.listPickupAuthorizations).mockResolvedValue(
      page([
        pickupAuthorization({
          public_id: 'A1',
          kind: 'puntual',
          person: { public_id: 'X1', display_name: 'Ana Uno' },
        }),
        pickupAuthorization({
          public_id: 'A2',
          withdrawn_at: '2026-10-01T10:00:00Z',
          is_effective: false,
          ineffective_reason: 'retirada',
          person: { public_id: 'X2', display_name: 'Beatriz Dos' },
        }),
      ]) as never,
    )
    await open()
    await pickStudent()

    expect(buttonByLabel('Cambiar la fecha de fin de Ana Uno')).toBeUndefined()
    expect(buttonByLabel('Cambiar la fecha de fin de Beatriz Dos')).toBeUndefined()
    expect(
      document.body.querySelector('button[aria-label^="Retirar la autorización de Beatriz"]'),
    ).toBeNull()
  })

  it('un 409 pickup.withdrawn al guardar no aplica el cambio: cierra el diálogo, avisa en la sección y recarga la tabla', async () => {
    vi.mocked(famApi.updatePickupAuthorizationValidity).mockRejectedValue(
      problem(409, 'La autorización fue retirada por otra persona.'),
    )
    await open()
    await pickStudent()
    buttonByLabel('Cambiar la fecha de fin de Rosa Ejemplo Vidal')!.click()
    await flushPromises()
    await fill('#fam-unit-pickup-valid-until', '2027-06-30')
    ;(
      document.body.querySelector('[data-slot="fam-unit-pickup-validity"] form') as HTMLFormElement
    ).dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await flushPromises()

    expect(document.body.querySelector('[data-slot="fam-unit-pickup-validity"]')).toBeNull()
    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain(
      'La autorización fue retirada por otra persona.',
    )
    expect(famApi.listPickupAuthorizations).toHaveBeenCalledTimes(2)
  })
})

describe('CA-FAM-UNIT-140: ficha de la persona', () => {
  const FULL = {
    person: 'PP1',
    display_name: 'Rosa Ejemplo Vidal',
    document: { type: 'dni', number: '00000000T' },
    contact_phone: '600000001',
    has_photo: true,
  }

  it('se abre solo al pulsar «Ver los datos», con el documento completo de solo lectura y sin copiarlo a otros sitios', async () => {
    vi.mocked(famApi.getPickupPerson).mockResolvedValue(FULL as never)
    await open()
    await pickStudent()

    expect(famApi.getPickupPerson).not.toHaveBeenCalled()

    buttonByLabel('Ver los datos de Rosa Ejemplo Vidal')!.click()
    await flushPromises()

    const input = document.body.querySelector<HTMLInputElement>('#fam-unit-pickup-person-document')!

    expect(famApi.getPickupPerson).toHaveBeenCalledTimes(1)
    expect(input.readOnly).toBe(true)
    expect(input.value).toContain('00000000T')
    expect(document.title).not.toContain('00000000T')
    expect(
      document.body.querySelector('[role="status"], [role="alert"]')?.textContent ?? '',
    ).not.toContain('00000000T')
  })

  it('un 404 al corregir muestra «no se puede corregir desde aquí» sin distinguir la causa', async () => {
    vi.mocked(famApi.getPickupPerson).mockResolvedValue(FULL as never)
    vi.mocked(famApi.updatePickupPerson).mockRejectedValue(problem(404, 'No encontrado.'))
    await open()
    await pickStudent()
    buttonByLabel('Ver los datos de Rosa Ejemplo Vidal')!.click()
    await flushPromises()
    await fill('#fam-unit-pickup-person-given', 'Rosita')
    ;(
      document.body.querySelector('[data-slot="fam-unit-pickup-person"] form') as HTMLFormElement
    ).dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await flushPromises()

    expect(famApi.updatePickupPerson).toHaveBeenCalledWith('PP1', { given_name: 'Rosita' })
    expect(text()).toContain('Esta persona no se puede corregir desde aquí')
  })
})

describe('CA-FAM-UNIT-144: sin persistencia local en la ficha, la corrección y la subida', () => {
  it('ni el documento, ni el teléfono, ni la URL de objeto salen a almacenamiento, ruta, history.state o título; la vista previa se libera', async () => {
    vi.mocked(famApi.getPickupPerson).mockResolvedValue({
      person: 'PP1',
      display_name: 'Rosa Ejemplo Vidal',
      document: { type: 'dni', number: '00000000T' },
      contact_phone: '600000001',
      has_photo: false,
    } as never)
    vi.mocked(famApi.updatePickupPerson).mockResolvedValue({ person: 'PP1' } as never)
    const { router } = await open()

    await pickStudent()
    buttonByLabel('Ver los datos de Rosa Ejemplo Vidal')!.click()
    await flushPromises()
    await fill('#fam-unit-pickup-person-phone', '600000009')
    ;(
      document.body.querySelector('[data-slot="fam-unit-pickup-person"] form') as HTMLFormElement
    ).dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await flushPromises()

    buttonByLabel('Subir fotografía de Rosa Ejemplo Vidal')!.click()
    await flushPromises()
    await chooseFile('foto.png', 'image/png', 1000)

    expect(URL.createObjectURL).toHaveBeenCalledTimes(1)

    const haystack = [
      JSON.stringify({ ...window.localStorage }),
      JSON.stringify({ ...window.sessionStorage }),
      router.currentRoute.value.fullPath,
      JSON.stringify(window.history.state),
      document.title,
    ].join('\n')

    for (const needle of ['00000000T', '600000001', '600000009', 'blob:', 'Rosa']) {
      expect(haystack, needle).not.toContain(needle)
    }

    await click('Cancelar')

    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview-1')
  })
})

describe('CA-FAM-UNIT-146: sin autorizado_recogida.actualizar', () => {
  it('avisa en el paso 2, no ofrece búsqueda, en el paso 3 no ofrece subida y no hay acción de foto en la fila', async () => {
    session.__setPermissions([...READ, 'autorizado_recogida.crear', 'texto_legal.leer'])
    vi.mocked(famApi.listPickupAuthorizations).mockResolvedValue(
      page([pickupAuthorization({ is_effective: false, ineffective_reason: 'sin_foto' })]) as never,
    )
    await open()
    await pickStudent()
    await click('Nueva autorización')

    expect(buttonByText('Buscar')).toBeUndefined()

    await fillPersonStep(false)
    await click('Siguiente')

    expect(text()).toContain('No tienes permiso para subir la fotografía')

    await click('Siguiente')

    expect(text()).toContain('Autorización registrada sin fotografía')
    expect(document.body.querySelector('input[type="file"]')).toBeNull()
    expect(famApi.findPickupPerson).not.toHaveBeenCalled()
    expect(famApi.putPickupPersonPhoto).not.toHaveBeenCalled()

    await click('Terminar')

    expect(buttonByLabel('Subir fotografía de Rosa Ejemplo Vidal')).toBeUndefined()
  })
})
