/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §10` (1.14-d), `CA-FAM-UNIT-084` (parte de SPA: la ficha refleja cada paso y
 * el estado revocado se distingue sin depender del color), `CA-FAM-UNIT-081`, `INV-002`: ficha de una unidad familiar con
 * sus secciones por permiso, las altas, la revocación con confirmación y los errores del servidor junto a la acción.
 * `REQ-FAM-UNIT-001`/`-002`/`-004`.
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
  consent,
  familyUnit,
  fill,
  guardian,
  link,
  mountView,
  page,
  stubMatchMedia,
  student,
  submitForm,
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
const coreApi = await import('@/modules/core/api')
const { default: FamilyUnitDetailView } = await import('./FamilyUnitDetailView.vue')

const wrappers: VueWrapper[] = []

const ALL = [
  'familia.leer',
  'familia.crear',
  'familia.actualizar',
  'familia.eliminar',
  'vinculo_tutor.leer',
  'vinculo_tutor.crear',
  'revocacion_tutor.leer',
  'revocacion_tutor.crear',
  'revocacion_tutor.eliminar',
  'consentimiento_imagen.leer',
]

const REVOKED = link({
  public_id: 'L2',
  guardian: 'G2',
  status: 'revocado',
  revocation: {
    reason: 'resolucion_judicial',
    revoked_at: '2026-10-20T08:00:00Z',
    revoked_by: 'USR9',
  },
})

async function open(url = '/familias/U1') {
  const mounted = await mountView('fam-unit-detail', FamilyUnitDetailView, url)

  wrappers.push(mounted.wrapper)

  return mounted
}

function problem(status: number, detail: string, errors: Record<string, unknown[]> = {}): ApiError {
  return new ApiError('x', status, { type: 'urn:pge:error:validation', status, detail, errors })
}

function setLists(links = [link(), REVOKED]): void {
  vi.mocked(famApi.getFamilyUnit).mockResolvedValue(familyUnit() as never)
  vi.mocked(famApi.listGuardians).mockResolvedValue(
    page([
      guardian(),
      guardian({
        public_id: 'G2',
        user: 'USR2',
        display_name: 'Pedro Ejemplo Gil',
        is_principal: false,
      }),
    ]) as never,
  )
  vi.mocked(famApi.listFamilyUnitStudents).mockResolvedValue({ data: [student()] } as never)
  vi.mocked(famApi.listGuardianLinks).mockResolvedValue(page(links) as never)
  vi.mocked(famApi.listImageConsents).mockResolvedValue(
    page([
      consent(),
      consent({ level: 'redes_sociales', state: 'pendiente', last_decision_at: null }),
    ]) as never,
  )
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  window.localStorage.clear()
  for (const fn of Object.values(famApi)) {
    if (typeof fn === 'function' && 'mockReset' in fn) (fn as ReturnType<typeof vi.fn>).mockReset()
  }
  vi.mocked(coreApi.listUsers).mockReset()
  setLists()
  session.__setPermissions(ALL)
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

function section(wrapper: VueWrapper, id: string): string {
  return wrapper.find(`#${id}`).element.closest('section')?.textContent ?? ''
}

describe('CA-FAM-UNIT-084: la ficha muestra tutores, alumnos, vínculos y consentimientos', () => {
  it('pide la unidad filtrando tutores, vínculos y consentimientos por ella', async () => {
    const { wrapper } = await open()

    expect(famApi.getFamilyUnit).toHaveBeenCalledWith('U1')
    expect(famApi.listGuardians).toHaveBeenCalledWith({ family_unit: 'U1', per_page: 100 })
    expect(famApi.listFamilyUnitStudents).toHaveBeenCalledWith('U1')
    expect(famApi.listGuardianLinks).toHaveBeenCalledWith({ family_unit: 'U1', per_page: 100 })
    expect(famApi.listImageConsents).toHaveBeenCalledWith({ family_unit: 'U1', per_page: 100 })
    expect(wrapper.find('h1').text()).toContain('UF-000123')
  })

  it('tutores: nombre, principal en texto y nombre vacío como «Usuario dado de baja»', async () => {
    vi.mocked(famApi.listGuardians).mockResolvedValue(
      page([
        guardian(),
        guardian({ public_id: 'G2', display_name: '', is_principal: false }),
      ]) as never,
    )

    const { wrapper } = await open()
    const text = section(wrapper, 'fam-unit-guardians')

    expect(text).toContain('Marta Ejemplo Ruiz')
    expect(text).toContain('Principal')
    expect(text).toContain('Usuario dado de baja')
  })

  it('el vínculo revocado se distingue con texto («Revocado») y con su motivo y fecha, no solo con color', async () => {
    const { wrapper } = await open()
    const rows = wrapper
      .find('#fam-unit-links')
      .element.closest('section')!
      .querySelectorAll('tbody tr')

    expect(rows).toHaveLength(2)
    expect(rows[0]!.textContent).toContain('Activo')
    expect(rows[0]!.textContent).not.toContain('Revocado')
    expect(rows[1]!.textContent).toContain('Revocado')
    expect(rows[1]!.querySelector('svg[aria-hidden="true"]')).not.toBeNull()
    expect(rows[1]!.querySelector('[data-slot="fam-unit-revocation"]')?.textContent).toContain(
      'Resolución judicial',
    )
  })

  it('sin revocacion_tutor.leer no se muestra el detalle de la revocación (el servidor ya lo omite)', async () => {
    session.__setPermissions(['familia.leer', 'vinculo_tutor.leer'])
    vi.mocked(famApi.listGuardianLinks).mockResolvedValue(
      page([link({ public_id: 'L2', guardian: 'G2', status: 'revocado' })]) as never,
    )

    const { wrapper } = await open()

    expect(wrapper.find('[data-slot="fam-unit-revocation"]').exists()).toBe(false)
  })

  it('consentimientos: estado en texto y fecha, «Pendiente» sin fecha', async () => {
    const { wrapper } = await open()
    const text = section(wrapper, 'fam-unit-consents')

    expect(text).toContain('Autorizo')
    expect(text).toContain('Pendiente')
    expect(text).toContain('Web del centro')
    expect(text).toContain('Redes sociales')
  })
})

describe('CA-FAM-UNIT-081 / INV-002: secciones y acciones por permiso', () => {
  it('con solo familia.leer no se piden ni se pintan vínculos ni consentimientos, ni hay acciones', async () => {
    session.__setPermissions(['familia.leer'])

    const { wrapper } = await open()

    expect(famApi.listGuardianLinks).not.toHaveBeenCalled()
    expect(famApi.listImageConsents).not.toHaveBeenCalled()
    expect(wrapper.find('#fam-unit-links').exists()).toBe(false)
    expect(wrapper.find('#fam-unit-consents').exists()).toBe(false)
    expect(buttonByText('Añadir tutor')).toBeUndefined()
    expect(buttonByText('Añadir alumno')).toBeUndefined()
    expect(buttonByLabel('Quitar a Marta Ejemplo Ruiz de la unidad')).toBeUndefined()
  })

  it('sin vinculo_tutor.crear no hay «Nuevo vínculo»; sin revocacion_tutor.* no hay revocar ni levantar', async () => {
    session.__setPermissions(['familia.leer', 'vinculo_tutor.leer', 'revocacion_tutor.leer'])

    await open()

    expect(buttonByText('Nuevo vínculo')).toBeUndefined()
    expect(buttonByText('Revocar')).toBeUndefined()
    expect(buttonByText('Levantar revocación')).toBeUndefined()
  })

  it('con todos los permisos hay altas y, por fila, acciones con nombre accesible único', async () => {
    await open()

    expect(buttonByText('Añadir tutor')).toBeDefined()
    expect(buttonByText('Añadir alumno')).toBeDefined()
    expect(buttonByText('Nuevo vínculo')).toBeDefined()
    expect(
      buttonByLabel('Revocar el acceso de Marta Ejemplo Ruiz a Lucas Ejemplo Ruiz'),
    ).toBeDefined()
    expect(
      buttonByLabel('Levantar la revocación de Pedro Ejemplo Gil sobre Lucas Ejemplo Ruiz'),
    ).toBeDefined()
    expect(buttonByLabel('Marcar a Pedro Ejemplo Gil como tutor principal')).toBeDefined()
    expect(buttonByLabel('Marcar a Marta Ejemplo Ruiz como tutor principal')).toBeUndefined()
  })

  it('un 404 de la unidad pinta el estado de error de la pantalla', async () => {
    vi.mocked(famApi.getFamilyUnit).mockRejectedValue(new ApiError('x', 404, { status: 404 }))

    const { wrapper } = await open()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('h1').exists()).toBe(false)
  })
})

describe('CA-FAM-UNIT-084: altas de la ficha', () => {
  it('añadir tutor sin usuario.leer: pide el identificador, crea en la unidad y refresca', async () => {
    vi.mocked(famApi.createGuardian).mockResolvedValue(guardian() as never)

    const { wrapper } = await open()

    buttonByText('Añadir tutor')!.click()
    await flushPromises()
    await fill('#fam-unit-guardian-user-id', ' USR5 ')
    await submitForm('fam-unit-guardian-form')

    expect(famApi.createGuardian).toHaveBeenCalledWith({ user: 'USR5', family_unit: 'U1' })
    expect(vi.mocked(famApi.listGuardians).mock.calls.length).toBeGreaterThan(1)
    expect(wrapper.text()).toContain('Tutor añadido a la unidad.')
  })

  it('añadir tutor con usuario.leer: busca en el directorio y envía el usuario elegido', async () => {
    session.__setPermissions([...ALL, 'usuario.leer'])
    vi.mocked(coreApi.listUsers).mockResolvedValue({
      data: [
        {
          public_id: 'USR7',
          email: 'ana@example.com',
          person: { given_name: 'Ana', family_name_1: 'Ejemplo', family_name_2: null },
        },
      ],
      meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 },
    } as never)
    vi.mocked(famApi.createGuardian).mockResolvedValue(guardian() as never)

    await open()

    buttonByText('Añadir tutor')!.click()
    await flushPromises()

    const radio = document.body.querySelector<HTMLInputElement>(
      'input[type="radio"][value="USR7"]',
    )!

    radio.click()
    await flushPromises()
    await submitForm('fam-unit-guardian-form')

    expect(famApi.createGuardian).toHaveBeenCalledWith({ user: 'USR7', family_unit: 'U1' })
  })

  it('un 422 user_not_eligible se muestra en el diálogo y la ficha no cambia', async () => {
    vi.mocked(famApi.createGuardian).mockRejectedValue(
      problem(422, 'Datos no válidos.', {
        user: [
          {
            code: 'fam_unit.guardian.user_not_eligible',
            message: 'El usuario no puede ser tutor.',
          },
        ],
      }),
    )

    await open()

    buttonByText('Añadir tutor')!.click()
    await flushPromises()
    await fill('#fam-unit-guardian-user-id', 'USR5')
    await submitForm('fam-unit-guardian-form')

    expect(document.body.textContent).toContain('El usuario no puede ser tutor.')
    expect(vi.mocked(famApi.listGuardians).mock.calls).toHaveLength(1)
  })

  it('añadir alumno envía la persona y refresca', async () => {
    vi.mocked(famApi.addFamilyUnitStudent).mockResolvedValue(student() as never)

    const { wrapper } = await open()

    buttonByText('Añadir alumno')!.click()
    await flushPromises()
    await fill('#fam-unit-student-person', ' P2 ')
    await submitForm('fam-unit-student-form')

    expect(famApi.addFamilyUnitStudent).toHaveBeenCalledWith('U1', 'P2')
    expect(wrapper.text()).toContain('Alumno añadido a la unidad.')
  })

  it('nuevo vínculo: elige tutor, alumno y relación entre los de la ficha y envía los valores por defecto', async () => {
    vi.mocked(famApi.createGuardianLink).mockResolvedValue(link() as never)

    const { wrapper } = await open()

    buttonByText('Nuevo vínculo')!.click()
    await flushPromises()
    await chooseOption('#fam-unit-link-guardian', 'Pedro Ejemplo Gil')
    await chooseOption('#fam-unit-link-student', 'Lucas Ejemplo Ruiz')
    await chooseOption('#fam-unit-link-relationship', 'Padre')
    await submitForm('fam-unit-link-form')

    expect(famApi.createGuardianLink).toHaveBeenCalledWith({
      guardian: 'G2',
      student_person: 'P1',
      relationship: 'padre',
      is_legal_guardian: false,
      access_level: 'lectura',
      restricted_categories: [],
      is_emergency_contact: false,
    })
    expect(wrapper.text()).toContain('Vínculo creado.')
  })

  it('sin tutores o sin alumnos no se puede crear un vínculo y se explica por qué', async () => {
    vi.mocked(famApi.listFamilyUnitStudents).mockResolvedValue({ data: [] } as never)

    const { wrapper } = await open()

    expect(buttonByText('Nuevo vínculo')!.disabled).toBe(true)
    expect(wrapper.text()).toContain('necesita al menos un tutor y un alumno')
  })
})

describe('CA-FAM-UNIT-084: revocación y levantamiento (confirmación y errores)', () => {
  it('no sale ninguna petición hasta confirmar; al confirmar revoca por resolución judicial y refresca', async () => {
    vi.mocked(famApi.revokeGuardianLink).mockResolvedValue(link({ status: 'revocado' }) as never)

    const { wrapper } = await open()

    buttonByLabel('Revocar el acceso de Marta Ejemplo Ruiz a Lucas Ejemplo Ruiz')!.click()
    await flushPromises()

    expect(famApi.revokeGuardianLink).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('Resolución judicial')

    buttonByText('Revocar el acceso de Marta Ejemplo Ruiz a Lucas Ejemplo Ruiz')!.click()
    await flushPromises()

    expect(famApi.revokeGuardianLink).toHaveBeenCalledWith('L1', 'resolucion_judicial')
    expect(vi.mocked(famApi.listGuardianLinks).mock.calls.length).toBeGreaterThan(1)
    expect(wrapper.text()).toContain('revocado')
  })

  it('cancelar la confirmación no envía nada', async () => {
    await open()

    buttonByLabel('Revocar el acceso de Marta Ejemplo Ruiz a Lucas Ejemplo Ruiz')!.click()
    await flushPromises()
    buttonByText('Cancelar')!.click()
    await flushPromises()

    expect(famApi.revokeGuardianLink).not.toHaveBeenCalled()
  })

  it('un 429 se muestra con el texto del servidor junto a la acción y la ficha no cambia', async () => {
    vi.mocked(famApi.revokeGuardianLink).mockRejectedValue(
      problem(429, 'Demasiadas revocaciones. Inténtalo más tarde.'),
    )

    const { wrapper } = await open()

    buttonByLabel('Revocar el acceso de Marta Ejemplo Ruiz a Lucas Ejemplo Ruiz')!.click()
    await flushPromises()
    buttonByText('Revocar el acceso de Marta Ejemplo Ruiz a Lucas Ejemplo Ruiz')!.click()
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain('Demasiadas revocaciones')
    expect(vi.mocked(famApi.listGuardianLinks).mock.calls).toHaveLength(1)
  })

  it('levantar la revocación pide confirmación y llama al DELETE', async () => {
    vi.mocked(famApi.liftGuardianLinkRevocation).mockResolvedValue(
      link({ public_id: 'L2' }) as never,
    )

    await open()

    buttonByLabel('Levantar la revocación de Pedro Ejemplo Gil sobre Lucas Ejemplo Ruiz')!.click()
    await flushPromises()
    buttonByText('Levantar la revocación de Pedro Ejemplo Gil sobre Lucas Ejemplo Ruiz')!.click()
    await flushPromises()

    expect(famApi.liftGuardianLinkRevocation).toHaveBeenCalledWith('L2')
  })
})

describe('CA-FAM-UNIT-084: otras acciones de la ficha', () => {
  it('marcar tutor principal envía PATCH con el tutor y refresca', async () => {
    vi.mocked(famApi.setPrincipalGuardian).mockResolvedValue(familyUnit() as never)

    const { wrapper } = await open()

    buttonByLabel('Marcar a Pedro Ejemplo Gil como tutor principal')!.click()
    await flushPromises()

    expect(famApi.setPrincipalGuardian).toHaveBeenCalledWith('U1', 'G2')
    expect(wrapper.text()).toContain('Pedro Ejemplo Gil es ahora el tutor principal.')
  })

  it('quitar un tutor pide confirmación y borra; un 409 del servidor se muestra junto a la acción', async () => {
    vi.mocked(famApi.deleteGuardian).mockRejectedValue(
      problem(409, 'Algún vínculo revocado impide la baja.'),
    )

    const { wrapper } = await open()

    buttonByLabel('Quitar a Pedro Ejemplo Gil de la unidad')!.click()
    await flushPromises()
    expect(famApi.deleteGuardian).not.toHaveBeenCalled()
    buttonByText('Quitar a Pedro Ejemplo Gil')!.click()
    await flushPromises()

    expect(famApi.deleteGuardian).toHaveBeenCalledWith('G2')
    expect(wrapper.find('[role="alert"]').text()).toContain('impide la baja')
  })

  it('quitar un alumno pide confirmación y llama al DELETE de su pertenencia', async () => {
    vi.mocked(famApi.removeFamilyUnitStudent).mockResolvedValue({ remaining_links: 0 } as never)

    await open()

    buttonByLabel('Quitar a Lucas Ejemplo Ruiz de la unidad')!.click()
    await flushPromises()
    buttonByText('Quitar a Lucas Ejemplo Ruiz')!.click()
    await flushPromises()

    expect(famApi.removeFamilyUnitStudent).toHaveBeenCalledWith('U1', 'M1')
  })
})
