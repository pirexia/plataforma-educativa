/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.3` punto 4, `§18.4.4` (`RN-ACAD-110`,
 * `-111`), `§18.9`: `CA-ACAD-136` (parte SPA) — gestión de marcos horarios
 * ESPECIALES (jornada intensiva): alta desde la ficha de etapa con tipo y fechas
 * de vigencia, y edición de la vigencia en la ficha del marco con solo las claves
 * que cambian; los errores del servidor (`frame_outside_academic_year`,
 * `frame_overlap`, `timetable_conflict`) se muestran junto a su campo. `REQ-ACAD-002`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as acadApi from '../api'
import * as cursoApi from '@/modules/curso/api'
import {
  buttonByText,
  fill,
  frame,
  level,
  mountView,
  page,
  stubMatchMedia,
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

vi.mock('@/tenant/useTenantBranding', async () => {
  const { shallowRef } = await import('vue')
  const branding = shallowRef({ default_locale: 'es-ES', active_locales: ['es-ES', 'en'] })

  return { useTenantBranding: () => ({ branding }) }
})

const session = (await import('@/session/useSession')) as unknown as {
  __setPermissions: (permissions: string[]) => void
}
const { resetSelectableYears } = await import('@/academic-year')
const { default: LevelDetailView } = await import('./LevelDetailView.vue')
const { default: TimetableFrameDetailView } = await import('./TimetableFrameDetailView.vue')

const wrappers: VueWrapper[] = []
const WRITER = [
  'estructura_academica.leer',
  'horarios.leer',
  'horarios.crear',
  'horarios.actualizar',
  'horarios.eliminar',
]

const SPECIAL = frame({
  public_id: 'M2',
  frame_type: 'especial',
  name: 'Jornada intensiva',
  name_translations: { 'es-ES': 'Jornada intensiva' },
  valid_from: '2026-06-01',
  valid_to: '2026-06-20',
})

async function open(name: string, component: object, url: string): Promise<VueWrapper> {
  const wrapper = await mountView(name, component as never, url)

  wrappers.push(wrapper)

  return wrapper
}

function problem(status: number, errors: Record<string, unknown[]>, detail = 'x'): ApiError {
  return new ApiError('x', status, { type: 'urn:pge:error:validation', status, detail, errors })
}

async function submitCreate(): Promise<void> {
  document.body
    .querySelector<HTMLFormElement>('[data-slot="acad-frame-form"] form')!
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
    .mockResolvedValue({
      data: [year('Y2', '2025-2026', 'activo'), year('Y1', '2024-2025', 'cerrado')],
    })
  for (const fn of Object.values(acadApi)) {
    if (typeof fn === 'function' && 'mockReset' in fn) (fn as ReturnType<typeof vi.fn>).mockReset()
  }
  vi.mocked(acadApi.getEducationLevel).mockResolvedValue(level() as never)
  vi.mocked(acadApi.listCourses).mockResolvedValue(page([]) as never)
  vi.mocked(acadApi.listTimetableFrames).mockResolvedValue(page([frame(), SPECIAL]) as never)
  vi.mocked(acadApi.getTimetableFrame).mockResolvedValue(SPECIAL as never)
  session.__setPermissions(WRITER)
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ACAD-136: marcos especiales en la ficha de etapa', () => {
  it('la tabla muestra la vigencia: «Todo el curso» del ordinario y las fechas del especial', async () => {
    const wrapper = await open('acad-level-detail', LevelDetailView, '/academico/etapas/L1')
    const rows = wrapper.findAll('[data-slot="acad-frames"] tbody tr').map((row) => row.text())

    expect(rows).toHaveLength(2)
    expect(rows[0]).toContain('Todo el curso')
    expect(rows[1]).toContain('Jornada intensiva')
    expect(rows[1]).toMatch(/Del .* al /)
  })

  it('con el ordinario ya creado se sigue pudiendo crear un especial: solo ofrece «Especial» y pide las fechas', async () => {
    vi.mocked(acadApi.createTimetableFrame).mockResolvedValue(SPECIAL as never)

    const wrapper = await open('acad-level-detail', LevelDetailView, '/academico/etapas/L1')

    buttonByText('Nuevo marco horario')!.click()
    await flushPromises()

    expect(document.body.querySelector('[data-slot="acad-frame-validity"]')).not.toBeNull()

    // El selector de tipo ofrece solo «Especial» (un ordinario por etapa, RN-ACAD-110).
    const trigger = document.body.querySelector<HTMLElement>('#acad-frame-type')!

    trigger.focus()
    trigger.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
    await flushPromises()

    const options = [...document.body.querySelectorAll<HTMLElement>('[role="option"]')]

    expect(options.map((node) => node.textContent?.trim())).toEqual(['Especial'])
    options[0]!.dispatchEvent(
      new PointerEvent('pointerup', { bubbles: true, pointerType: 'mouse' }),
    )
    options[0]!.click()
    await flushPromises()

    await fill('#acad-frame-name-es-ES', 'Jornada intensiva')
    await fill('#acad-frame-valid-from', '2026-06-01')
    await fill('#acad-frame-valid-to', '2026-06-20')
    await submitCreate()

    expect(acadApi.createTimetableFrame).toHaveBeenCalledWith({
      education_level: 'L1',
      frame_type: 'especial',
      name_translations: { 'es-ES': 'Jornada intensiva' },
      valid_from: '2026-06-01',
      valid_to: '2026-06-20',
    })
    expect(wrapper.exists()).toBe(true)
  })

  it('sin marco ordinario se ofrecen los dos tipos, por defecto el ordinario, sin fechas', async () => {
    vi.mocked(acadApi.listTimetableFrames).mockResolvedValue(page([]) as never)
    vi.mocked(acadApi.createTimetableFrame).mockResolvedValue(frame() as never)

    await open('acad-level-detail', LevelDetailView, '/academico/etapas/L1')

    buttonByText('Nuevo marco horario')!.click()
    await flushPromises()

    expect(document.body.querySelector('[data-slot="acad-frame-validity"]')).toBeNull()

    await fill('#acad-frame-name-es-ES', 'Horario general')
    await submitCreate()

    expect(acadApi.createTimetableFrame).toHaveBeenCalledWith({
      education_level: 'L1',
      frame_type: 'ordinaria',
      name_translations: { 'es-ES': 'Horario general' },
    })
  })

  it('RN-ACAD-111: un 422 de fechas fuera del curso o solapadas se muestra junto a su campo y el diálogo sigue abierto', async () => {
    vi.mocked(acadApi.createTimetableFrame).mockRejectedValue(
      problem(422, {
        valid_from: [
          {
            code: 'acad.validation.frame_outside_academic_year',
            message:
              'La vigencia del marco debe estar dentro del curso académico (2025-09-01 – 2026-06-30).',
            params: {},
          },
        ],
        valid_to: [
          {
            code: 'acad.validation.frame_dates_invalid',
            message: 'Las fechas de vigencia no son válidas.',
            params: {},
          },
        ],
      }),
    )

    await open('acad-level-detail', LevelDetailView, '/academico/etapas/L1')

    buttonByText('Nuevo marco horario')!.click()
    await flushPromises()
    await fill('#acad-frame-name-es-ES', 'Jornada')
    await fill('#acad-frame-valid-from', '2025-08-01')
    await fill('#acad-frame-valid-to', '2025-07-01')
    await submitCreate()

    const from = document.body.querySelector('#acad-frame-valid-from-errors')!
    const to = document.body.querySelector('#acad-frame-valid-to-errors')!

    expect(from.getAttribute('role')).toBe('alert')
    expect(from.textContent).toContain('dentro del curso académico')
    expect(to.textContent).toContain('no son válidas')
    expect(
      document.body.querySelector('#acad-frame-valid-from')!.getAttribute('aria-invalid'),
    ).toBe('true')
    expect(document.body.querySelector('[data-slot="acad-frame-form"]')).not.toBeNull()
  })

  it('con el curso cerrado no hay «Nuevo marco horario» (RN-ACAD-42)', async () => {
    vi.mocked(acadApi.getEducationLevel).mockResolvedValue(level({ academic_year: 'Y1' }) as never)

    await open('acad-level-detail', LevelDetailView, '/academico/etapas/L1')

    expect(buttonByText('Nuevo marco horario')).toBeUndefined()
  })
})

describe('CA-ACAD-136: vigencia en la ficha del marco', () => {
  it('un especial muestra «Especial · Del … al …» y los campos de fecha; solo viajan las fechas que cambian', async () => {
    vi.mocked(acadApi.updateTimetableFrame).mockResolvedValue(SPECIAL as never)

    const wrapper = await open(
      'acad-timetable-frame',
      TimetableFrameDetailView,
      '/academico/marcos/M2',
    )

    expect(wrapper.get('[data-slot="acad-frame-validity-text"]').text()).toMatch(
      /Especial\s*·\s*Del .* al /,
    )
    expect(
      document.body.querySelector<HTMLInputElement>('#acad-frame-detail-valid-from')!.value,
    ).toBe('2026-06-01')

    await fill('#acad-frame-detail-valid-to', '2026-06-25')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const body = vi.mocked(acadApi.updateTimetableFrame).mock.calls[0]![1] as Record<
      string,
      unknown
    >

    expect(vi.mocked(acadApi.updateTimetableFrame).mock.calls[0]![0]).toBe('M2')
    expect(body.valid_to).toBe('2026-06-25')
    expect(body).not.toHaveProperty('valid_from')
    expect(Array.isArray(body.slots)).toBe(true)
  })

  it('RN-ACAD-125: un 422 timetable_conflict o frame_overlap en las fechas se muestra junto a su campo y no se recarga', async () => {
    vi.mocked(acadApi.updateTimetableFrame).mockRejectedValue(
      problem(422, {
        valid_from: [
          {
            code: 'acad.validation.timetable_conflict',
            message: 'La sesión coincide en el horario con otra del mismo profesor, aula o grupo.',
            params: { conflicts: [{ kind: 'teacher', session: 'SE9' }] },
          },
        ],
      }),
    )

    const wrapper = await open(
      'acad-timetable-frame',
      TimetableFrameDetailView,
      '/academico/marcos/M2',
    )

    await fill('#acad-frame-detail-valid-from', '2026-05-01')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const errors = document.body.querySelector('#acad-frame-detail-valid-from-errors')!

    expect(errors.getAttribute('role')).toBe('alert')
    expect(errors.textContent).toContain('coincide en el horario')
    expect(acadApi.getTimetableFrame).toHaveBeenCalledTimes(1)
  })

  it('un ordinario muestra «Todo el curso» y no tiene campos de fecha', async () => {
    vi.mocked(acadApi.getTimetableFrame).mockResolvedValue(frame() as never)

    const wrapper = await open(
      'acad-timetable-frame',
      TimetableFrameDetailView,
      '/academico/marcos/M1',
    )

    expect(wrapper.get('[data-slot="acad-frame-validity-text"]').text()).toContain('Todo el curso')
    expect(wrapper.find('[data-slot="acad-frame-validity"]').exists()).toBe(false)
  })

  it('sin horarios.actualizar la vigencia se muestra como texto, sin campos', async () => {
    session.__setPermissions(['horarios.leer'])

    const wrapper = await open(
      'acad-timetable-frame',
      TimetableFrameDetailView,
      '/academico/marcos/M2',
    )

    expect(wrapper.get('[data-slot="acad-frame-validity-text"]').text()).toMatch(/Del .* al /)
    expect(wrapper.find('[data-slot="acad-frame-validity"]').exists()).toBe(false)
  })
})
