/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23` (`1.14b-c1` T1, solo lectura), `REQ-FAM-UNIT-005`: sección «Personas
 * autorizadas a recoger» de la ficha de familia. `CA-FAM-UNIT-132` (visibilidad por permiso, `INV-002`), `-133` (lecturas
 * auditadas solo bajo demanda, UI-2), `-134` (tarjetas: fotografía, bases, documento enmascarado, UI-4/UI-5), `-135`
 * (estado vigente/no vigente, columna «Motivo») y `-141` (sin persistencia local de datos de personas, UI-3).
 * Datos ficticios (`REQ-SEED-005`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { VueWrapper } from '@vue/test-utils'
import { flushPromises } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as famApi from '../api'
import {
  buttonByText,
  chooseOption,
  familyUnit,
  guardian,
  mountView,
  page,
  pickupAuthorization,
  pickupEntry,
  pickupList,
  stubMatchMedia,
  student,
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
const SECTION = '#fam-unit-pickup'
const READ = ['familia.leer', 'autorizado_recogida.leer']

async function open() {
  const mounted = await mountView('fam-unit-detail', FamilyUnitDetailView, '/familias/U1')

  wrappers.push(mounted.wrapper)

  return mounted
}

function sectionText(): string {
  return document.body.querySelector(SECTION)?.closest('section')?.textContent ?? ''
}

async function pickStudent(name: string): Promise<void> {
  await chooseOption('#fam-unit-pickup-student', name)
}

async function showList(): Promise<void> {
  buttonByText('Ver lista de recogida')!.click()
  await flushPromises()
}

function authorizationsPage(rows: unknown[]) {
  return page(rows) as never
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  window.localStorage.clear()
  window.sessionStorage.clear()
  for (const fn of Object.values(famApi)) {
    if (typeof fn === 'function' && 'mockReset' in fn) (fn as ReturnType<typeof vi.fn>).mockReset()
  }
  vi.mocked(famApi.getFamilyUnit).mockResolvedValue(familyUnit() as never)
  vi.mocked(famApi.listGuardians).mockResolvedValue(page([guardian()]) as never)
  vi.mocked(famApi.listFamilyUnitStudents).mockResolvedValue({
    data: [
      student(),
      student({ public_id: 'M2', student_person: 'P2', display_name: 'Irene Ejemplo Ruiz' }),
      student({ public_id: 'M3', student_person: 'P3', display_name: 'Hugo Ejemplo Ruiz' }),
    ],
  } as never)
  vi.mocked(famApi.listPickupAuthorizations).mockResolvedValue(
    authorizationsPage([pickupAuthorization()]),
  )
  vi.mocked(famApi.getPickupList).mockResolvedValue(pickupList() as never)
  session.__setPermissions(READ)
})

afterEach(() => {
  vi.useRealTimers()
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-FAM-UNIT-132: la sección existe solo con autorizado_recogida.leer', () => {
  it('sin el permiso no está en el DOM y no hay ninguna petición a /pickup-*', async () => {
    session.__setPermissions(['familia.leer'])
    await open()

    expect(document.body.querySelector(SECTION)).toBeNull()
    expect(famApi.listPickupAuthorizations).not.toHaveBeenCalled()
    expect(famApi.getPickupList).not.toHaveBeenCalled()
    expect(famApi.findPickupPerson).not.toHaveBeenCalled()
  })

  it('con .leer sin .crear/.actualizar/.eliminar no hay botón de alta ni acciones de fila', async () => {
    await open()
    await pickStudent('Lucas Ejemplo Ruiz')

    const section = document.body.querySelector(SECTION)!.closest('section')!

    expect(section).not.toBeNull()
    expect(buttonByText('Nueva autorización')).toBeUndefined()
    expect(section.textContent).toContain('Rosa Ejemplo Vidal')
    expect(
      [...section.querySelectorAll('button')].some((button) =>
        /retirar|subir fotograf|quitar|cambiar la fecha|ver los datos/i.test(
          `${button.textContent} ${button.getAttribute('aria-label')}`,
        ),
      ),
    ).toBe(false)
  })
})

describe('CA-FAM-UNIT-133: las lecturas auditadas solo se lanzan por una acción explícita', () => {
  it('al montar no hay petición a /pickup-lists ni a /pickup-persons; una pulsación = una petición, sin refresco', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval', 'clearTimeout', 'clearInterval'] })
    await open()

    expect(famApi.getPickupList).not.toHaveBeenCalled()
    expect(famApi.findPickupPerson).not.toHaveBeenCalled()
    expect(famApi.getPickupPerson).not.toHaveBeenCalled()
    expect(famApi.getPickupPhotoUrl).not.toHaveBeenCalled()

    await pickStudent('Irene Ejemplo Ruiz')

    // Elegir alumno carga la tabla (no escribe `read`), pero ninguna lectura auditada.
    expect(famApi.listPickupAuthorizations).toHaveBeenCalledTimes(1)
    expect(vi.mocked(famApi.listPickupAuthorizations).mock.calls[0]![0]).toMatchObject({
      student_person: 'P2',
    })
    expect(famApi.getPickupList).not.toHaveBeenCalled()

    await showList()

    expect(famApi.getPickupList).toHaveBeenCalledTimes(1)
    expect(famApi.getPickupList).toHaveBeenCalledWith('P2')

    await vi.advanceTimersByTimeAsync(6 * 60 * 1000)
    await flushPromises()

    expect(famApi.getPickupList).toHaveBeenCalledTimes(1)
    expect(famApi.findPickupPerson).not.toHaveBeenCalled()
    expect(famApi.getPickupPhotoUrl).not.toHaveBeenCalled()
  })

  it('cambiar de alumno descarta la lista anterior y no pide la del nuevo hasta pulsar', async () => {
    await open()
    await pickStudent('Lucas Ejemplo Ruiz')
    await showList()
    expect(sectionText()).toContain('Rosa Ejemplo Vidal')

    await pickStudent('Hugo Ejemplo Ruiz')

    expect(famApi.getPickupList).toHaveBeenCalledTimes(1)
    expect(sectionText()).toContain('Elige un alumno y pulsa «Ver lista de recogida»')
  })

  it('un 429 muestra el mensaje con su espera y no reintenta solo', async () => {
    vi.mocked(famApi.getPickupList).mockRejectedValue(
      new ApiError(
        'x',
        429,
        { type: 'urn:pge:error:rate-limited', status: 429 },
        new Headers({ 'Retry-After': '90' }),
      ),
    )
    await open()
    await pickStudent('Lucas Ejemplo Ruiz')
    await showList()
    await flushPromises()

    expect(famApi.getPickupList).toHaveBeenCalledTimes(1)
    expect(document.body.querySelector('[role="alert"]')).not.toBeNull()
  })

  it('un 404 y un 403 de la lista usan el texto de §23.4.5 y no ofrecen reintentar', async () => {
    vi.mocked(famApi.getPickupList).mockRejectedValueOnce(
      new ApiError('x', 404, { type: 'urn:pge:error:not-found', status: 404 }),
    )
    await open()
    await pickStudent('Lucas Ejemplo Ruiz')
    await showList()

    expect(sectionText()).toContain('No se encuentra esta información.')
    expect(buttonByText('Reintentar')).toBeUndefined()

    vi.mocked(famApi.getPickupList).mockRejectedValueOnce(
      new ApiError('x', 403, { type: 'urn:pge:error:forbidden', status: 403 }),
    )
    await showList()

    expect(sectionText()).toContain('No tienes permiso para ver esta información.')
  })
})

describe('CA-FAM-UNIT-134: tarjetas de la lista vigente', () => {
  const tutor = pickupEntry({
    person: 'PT1',
    display_name: 'Marta Ejemplo Ruiz',
    bases: [{ basis: 'tutor', guardian_link: 'L1', relationship: 'madre' }],
    has_photo: false,
    photo_url: null,
    photo_url_expires_at: null,
    contact_phone: '600000002',
    document: { type: 'dni', masked: '•••••456Z' },
  })
  const third = pickupEntry()
  const oneOff = pickupEntry({
    person: 'PP2',
    display_name: 'Elena Ejemplo Soto',
    bases: [
      {
        basis: 'puntual',
        authorization: 'PA2',
        relationship: 'otro',
        valid_from: '2026-11-02',
        valid_until: '2026-11-02',
      },
    ],
    has_photo: true,
    photo_url: 'https://almacen.example.com/firmada/pp2.jpg?firma=def',
    document: { type: 'nie', masked: '•••••789K' },
  })

  beforeEach(() => {
    vi.mocked(famApi.getPickupList).mockResolvedValue(pickupList([tutor, third, oneOff]) as never)
  })

  it('pinta foto con alt traducido, sin foto con aviso de texto, etiquetas de base y documento enmascarado', async () => {
    await open()
    await pickStudent('Lucas Ejemplo Ruiz')
    await showList()

    const section = document.body.querySelector(SECTION)!.closest('section')!
    const image = section.querySelector<HTMLImageElement>(
      'img[alt="Fotografía de Rosa Ejemplo Vidal"]',
    )

    expect(image).not.toBeNull()
    expect(image!.getAttribute('referrerpolicy')).toBe('no-referrer')
    expect(image!.getAttribute('src')).toBe(third.photo_url)

    const text = section.textContent ?? ''

    expect(text).toContain('Sin fotografía')
    expect(text).toContain('Verifica con el documento.')
    expect(text).toContain('Tutor/a (Madre)')
    expect(text).toContain('Autorizado/a')
    expect(text).toContain('Abuelo/a')
    expect(text).toContain('Sin fecha de fin')
    expect(text).toContain('Solo hoy')
    expect(text).toContain('•••••456Z')
    expect(text).toContain('•••••123X')
    expect(text).toContain('Lista calculada el')
    expect(text).toContain('Esta consulta queda registrada con tu usuario, la fecha y la hora.')
    expect(text).not.toMatch(/revoca|judicial|excluid/i)
    expect(section.querySelector('a[href="tel:600000002"]')).not.toBeNull()
  })

  it('si el <img> falla, lo sustituye por «Fotografía no disponible» y «Volver a cargar la lista» sin petición automática', async () => {
    await open()
    await pickStudent('Lucas Ejemplo Ruiz')
    await showList()

    const image = document.body.querySelector<HTMLImageElement>(
      'img[alt="Fotografía de Rosa Ejemplo Vidal"]',
    )!

    image.dispatchEvent(new Event('error'))
    await flushPromises()

    expect(document.body.querySelector('img[alt="Fotografía de Rosa Ejemplo Vidal"]')).toBeNull()
    expect(sectionText()).toContain('Fotografía no disponible')
    expect(famApi.getPickupList).toHaveBeenCalledTimes(1)

    buttonByText('Volver a cargar la lista')!.click()
    await flushPromises()

    expect(famApi.getPickupList).toHaveBeenCalledTimes(2)
  })

  it('una lista vacía dice que nadie puede recoger al alumno', async () => {
    vi.mocked(famApi.getPickupList).mockResolvedValue(pickupList([]) as never)
    await open()
    await pickStudent('Lucas Ejemplo Ruiz')
    await showList()

    expect(sectionText()).toContain('Nadie puede recoger a este alumno en este momento.')
  })
})

describe('CA-FAM-UNIT-135: estado de las autorizaciones y columna «Motivo»', () => {
  it('pinta «No vigente: {causa}» con texto para sin_foto, no_vigente y persona_excluida, y «Vigente» para las demás', async () => {
    vi.mocked(famApi.listPickupAuthorizations).mockResolvedValue(
      authorizationsPage([
        pickupAuthorization({
          public_id: 'A1',
          person: { public_id: 'X1', display_name: 'Ana Uno' },
          is_effective: false,
          ineffective_reason: 'sin_foto',
        }),
        pickupAuthorization({
          public_id: 'A2',
          person: { public_id: 'X2', display_name: 'Beatriz Dos' },
          is_effective: false,
          ineffective_reason: 'no_vigente',
        }),
        pickupAuthorization({
          public_id: 'A3',
          person: { public_id: 'X3', display_name: 'Carla Tres' },
          is_effective: false,
          ineffective_reason: 'persona_excluida',
        }),
        pickupAuthorization({
          public_id: 'A4',
          person: { public_id: 'X4', display_name: 'Diana Cuatro' },
        }),
      ]),
    )
    await open()
    await pickStudent('Lucas Ejemplo Ruiz')

    const text = sectionText()

    expect(text).toContain('No vigente: falta la fotografía')
    expect(text).toContain('No vigente: no vigente')
    expect(text).toContain('No vigente: persona excluida')
    expect(text).toContain('Vigente')
  })

  it('sin reason en las respuestas, la columna «Motivo» no se pinta; con reason, sí', async () => {
    await open()
    await pickStudent('Lucas Ejemplo Ruiz')

    const headers = (): string[] =>
      [...document.body.querySelectorAll('th')].map((th) => th.textContent?.trim() ?? '')

    expect(headers()).not.toContain('Motivo')

    wrappers.pop()!.unmount()
    document.body.innerHTML = ''
    vi.mocked(famApi.listPickupAuthorizations).mockResolvedValue(
      authorizationsPage([pickupAuthorization({ kind: 'puntual', reason: 'Recoge un familiar' })]),
    )
    await open()
    await pickStudent('Lucas Ejemplo Ruiz')

    expect(headers()).toContain('Motivo')
    expect(sectionText()).toContain('Recoge un familiar')
  })
})

describe('CA-FAM-UNIT-141: sin persistencia local de datos de personas', () => {
  it('localStorage, sessionStorage, route.fullPath, history.state y document.title no contienen datos de personas', async () => {
    const entry = pickupEntry()

    vi.mocked(famApi.getPickupList).mockResolvedValue(pickupList([entry]) as never)

    const { router } = await open()

    await pickStudent('Lucas Ejemplo Ruiz')
    await showList()

    expect(sectionText()).toContain('Rosa Ejemplo Vidal')

    const haystack = [
      JSON.stringify({ ...window.localStorage }),
      JSON.stringify({ ...window.sessionStorage }),
      router.currentRoute.value.fullPath,
      JSON.stringify(window.history.state),
      document.title,
    ].join('\n')
    const sensitive = [
      'Lucas',
      'Rosa',
      'Vidal',
      'Ejemplo',
      '123X',
      entry.contact_phone,
      entry.photo_url,
      'firmada',
      'P1',
      'PP1',
    ]

    for (const needle of sensitive) {
      expect(haystack, needle).not.toContain(needle as string)
    }
  })
})
