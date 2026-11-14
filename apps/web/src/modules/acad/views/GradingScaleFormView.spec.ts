/**
 * `docs/modulos/REQ-ACAD/funcional.md §10.5.2`, `§10.5.4`, `§13.16` (1.11d-b):
 * formulario de escala (alta y edición en página propia) — `CA-ACAD-099`
 * (cuerpos, `422` por campo, por fila y de lista enlazados con
 * `aria-describedby`, curso de solo lectura sin formulario) y `CA-ACAD-100`
 * (editor de valores con «Subir»/«Bajar» por botón y región viva, columna
 * «Aprobado» de la mixta recalculada, tipo bloqueado en edición, decimales como
 * cadena). `REQ-ACAD-001`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as acadApi from '../api'
import * as cursoApi from '@/modules/curso/api'
import { bandScale, fill, mountView, scale, stubMatchMedia, year } from '../testSupport'

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
const { default: GradingScaleFormView } = await import('./GradingScaleFormView.vue')

const wrappers: VueWrapper[] = []

const YEARS = [
  year('Y3', '2026-2027', 'planificacion'),
  year('Y2', '2025-2026', 'activo'),
  year('Y1', '2024-2025', 'cerrado'),
]

async function openNew(url = '/academico/escalas/nueva'): Promise<VueWrapper> {
  const wrapper = await mountView('acad-grading-scale-new', GradingScaleFormView, url)

  wrappers.push(wrapper)

  return wrapper
}

async function openEdit(id = 'E1'): Promise<VueWrapper> {
  const wrapper = await mountView(
    'acad-grading-scale-edit',
    GradingScaleFormView,
    `/academico/escalas/${id}/editar`,
  )

  wrappers.push(wrapper)

  return wrapper
}

function byId<T extends HTMLElement = HTMLInputElement>(id: string): T {
  const element = document.getElementById(id)

  if (!element) throw new Error(`No existe #${id}`)

  return element as T
}

function buttonText(wrapper: VueWrapper, text: string) {
  return wrapper.findAll('button').find((button) => button.text() === text)
}

async function pickType(type: string): Promise<void> {
  byId<HTMLButtonElement>(`acad-scale-type-${type}`).click()
  await flushPromises()
}

async function submit(wrapper: VueWrapper): Promise<void> {
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

function routeName(wrapper: VueWrapper): string {
  return String((wrapper.vm as unknown as { $route: { name: string } }).$route.name)
}

function problem(errors: Record<string, unknown[]>, detail = 'x'): ApiError {
  return new ApiError('x', 422, {
    type: 'urn:pge:error:validation',
    status: 422,
    detail,
    errors,
  })
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  Element.prototype.scrollIntoView = () => undefined
  window.localStorage.clear()
  resetSelectableYears()
  vi.mocked(cursoApi.listSelectableAcademicYears).mockReset().mockResolvedValue({ data: YEARS })
  for (const fn of Object.values(acadApi)) {
    if (typeof fn === 'function' && 'mockReset' in fn) (fn as ReturnType<typeof vi.fn>).mockReset()
  }
  vi.mocked(acadApi.getGradingScale).mockResolvedValue(scale() as never)
  vi.mocked(acadApi.createGradingScale).mockResolvedValue(scale({ public_id: 'NEW' }) as never)
  vi.mocked(acadApi.updateGradingScale).mockResolvedValue(scale() as never)
  session.__setPermissions([
    'configuracion_academica.leer',
    'configuracion_academica.crear',
    'configuracion_academica.actualizar',
  ])
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ACAD-099 (§4.9.2): alta', () => {
  it('numérica: decimales como cadena, decimals entero, academic_year explícito y sin values', async () => {
    const wrapper = await openNew('/academico/escalas/nueva?academic_year=Y3')

    await pickType('numerica')
    await fill('#acad-scale-code', 'NUM10')
    await fill('#acad-scale-name-es-ES', 'De 0 a 10')
    await fill('#acad-scale-min_value', '0')
    await fill('#acad-scale-max_value', '10')
    await fill('#acad-scale-passing_min_value', '5')

    const trigger = byId('acad-scale-decimals')

    trigger.focus()
    trigger.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
    await flushPromises()

    const option = [...document.body.querySelectorAll<HTMLElement>('[role="option"]')].find(
      (item) => item.textContent?.trim() === '2',
    )!

    option.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerType: 'mouse' }))
    option.click()
    await flushPromises()

    await submit(wrapper)

    const body = vi.mocked(acadApi.createGradingScale).mock.calls[0]![0] as unknown as Record<
      string,
      unknown
    >

    expect(body).toEqual({
      academic_year: 'Y3',
      code: 'NUM10',
      name_translations: { 'es-ES': 'De 0 a 10' },
      scale_type: 'numerica',
      min_value: '0',
      max_value: '10',
      decimals: 2,
      passing_min_value: '5',
    })
    expect(typeof body.min_value).toBe('string')
    expect(routeName(wrapper)).toBe('acad-grading-scale-detail')
  })

  it('cualitativa: valores en el orden de la lista, con is_passing, sin position ni campos numéricos', async () => {
    const wrapper = await openNew()

    await pickType('cualitativa')
    expect(wrapper.find('#acad-scale-min_value').exists()).toBe(false)

    await fill('#acad-scale-code', 'CUAL')
    await fill('#acad-scale-name-es-ES', 'Cualitativa')

    buttonText(wrapper, 'Añadir valor')!.element.click()
    await flushPromises()
    buttonText(wrapper, 'Añadir valor')!.element.click()
    await flushPromises()

    const codes = [...document.querySelectorAll<HTMLInputElement>('input[id$="-code"]')].filter(
      (input) => input.id.startsWith('acad-scale-row-'),
    )

    expect(codes).toHaveLength(2)

    await fill(`#${codes[0]!.id}`, 'IN')
    await fill(`#${codes[0]!.id.replace('-code', '-label-es-ES')}`, 'Insuficiente')
    await fill(`#${codes[1]!.id}`, 'SU')
    await fill(`#${codes[1]!.id.replace('-code', '-label-es-ES')}`, 'Suficiente')

    byId(codes[1]!.id.replace('-code', '-is_passing')).click()
    await flushPromises()
    await submit(wrapper)

    const body = vi.mocked(acadApi.createGradingScale).mock.calls[0]![0]

    expect(body.values).toEqual([
      { code: 'IN', label_translations: { 'es-ES': 'Insuficiente' }, is_passing: false },
      { code: 'SU', label_translations: { 'es-ES': 'Suficiente' }, is_passing: true },
    ])
    expect(JSON.stringify(body)).not.toContain('position')
    expect(body).not.toHaveProperty('min_value')
  })

  it('sin curso activo: estado «sin curso activo», sin formulario', async () => {
    vi.mocked(cursoApi.listSelectableAcademicYears).mockResolvedValue({
      data: [year('Y3', '2026-2027', 'planificacion')],
    } as never)

    const wrapper = await openNew()

    expect(wrapper.text()).toContain('No hay un curso académico activo')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('con un curso de solo lectura: aviso de texto y sin formulario (la API daría 409)', async () => {
    const wrapper = await openNew('/academico/escalas/nueva?academic_year=Y1')

    expect(wrapper.find('[data-slot="acad-scale-read-only"]').text()).toContain('solo lectura')
    expect(wrapper.find('form').exists()).toBe(false)
  })
})

describe('CA-ACAD-099 (§10.5.2): los 422 se pintan en su campo, su fila o su lista', () => {
  async function failCreate(wrapper: VueWrapper, errors: Record<string, unknown[]>): Promise<void> {
    vi.mocked(acadApi.createGradingScale).mockRejectedValue(problem(errors))
    await submit(wrapper)
  }

  it('values.<n>.code en la fila n; values sobre la lista; max_value junto a su campo', async () => {
    const wrapper = await openNew()

    await pickType('mixta')
    await fill('#acad-scale-code', 'MIX')
    await fill('#acad-scale-name-es-ES', 'Mixta')
    buttonText(wrapper, 'Añadir tramo')!.element.click()
    await flushPromises()
    buttonText(wrapper, 'Añadir tramo')!.element.click()
    await flushPromises()

    await failCreate(wrapper, {
      max_value: [{ code: 'acad.validation.range_invalid', message: 'El mínimo es mayor.' }],
      values: [{ code: 'acad.validation.bands_not_contiguous', message: 'Hay huecos.' }],
      'values.1.code': [{ code: 'acad.validation.code_taken', message: 'Código repetido.' }],
      'values.0.range_max': [
        { code: 'acad.validation.band_range_invalid', message: 'Tramo fuera.' },
      ],
    })

    // Campo de la escala.
    const max = byId('acad-scale-max_value')

    expect(max.getAttribute('aria-invalid')).toBe('true')
    expect(document.getElementById(max.getAttribute('aria-describedby')!)!.textContent).toContain(
      'El mínimo es mayor.',
    )

    // Lista: sobre el grupo, enlazada.
    const group = wrapper.find('[data-slot="acad-scale-values"] [role="group"]')
    const listErrors = document.getElementById(group.attributes('aria-describedby')!)!

    expect(listErrors.textContent).toContain('Hay huecos.')

    // Filas: la 2.ª (índice 1) lleva el código repetido; la 1.ª, el tramo fuera.
    const rows = wrapper.findAll('[data-slot="acad-scale-value"]')
    const second = rows[1]!.find('input[id$="-code"]')
    const first = rows[0]!.find('input[id$="-range_max"]')

    expect(second.attributes('aria-invalid')).toBe('true')
    expect(document.getElementById(second.attributes('aria-describedby')!)!.textContent).toContain(
      'Código repetido.',
    )
    expect(rows[0]!.find('input[id$="-code"]').attributes('aria-invalid')).toBeUndefined()
    expect(first.attributes('aria-invalid')).toBe('true')
    expect(rows[1]!.text()).not.toContain('Tramo fuera.')
    expect(rows[0]!.text()).toContain('Tramo fuera.')
  })

  it('values.<n>.public_id (value_not_in_scale) se muestra en la fila; academic_year, junto a la acción', async () => {
    const wrapper = await openEdit()

    vi.mocked(acadApi.updateGradingScale).mockRejectedValue(
      problem({
        'values.0.public_id': [
          { code: 'acad.validation.value_not_in_scale', message: 'No pertenece a la escala.' },
        ],
        academic_year: [{ code: 'acad.validation.parent_immutable', message: 'Inmutable.' }],
      }),
    )
    await fill('#acad-scale-code', 'CUAL2')
    await submit(wrapper)

    expect(wrapper.findAll('[data-slot="acad-scale-value"]')[0]!.text()).toContain(
      'No pertenece a la escala.',
    )
    expect(wrapper.find('#acad-scale-general-errors').text()).toContain('Inmutable.')
  })

  it('el foco va al primer error', async () => {
    const wrapper = await openNew()

    await pickType('numerica')
    await failCreate(wrapper, {
      code: [{ code: 'acad.validation.code_required', message: 'Falta el código.' }],
    })

    expect(document.activeElement?.id).toBe('acad-scale-code')
  })

  it('un 409 academic-year-closed se muestra con el mensaje del servidor y el formulario conserva lo escrito', async () => {
    const wrapper = await openNew()

    await pickType('numerica')
    await fill('#acad-scale-code', 'NUM')
    vi.mocked(acadApi.createGradingScale).mockRejectedValue(
      new ApiError('x', 409, {
        type: 'urn:pge:error:academic-year-closed',
        status: 409,
        detail: 'El curso académico está cerrado.',
      }),
    )
    await submit(wrapper)

    expect(wrapper.find('#acad-scale-general-errors').text()).toContain('está cerrado')
    expect(byId('acad-scale-code').value).toBe('NUM')
  })
})

describe('CA-ACAD-100: editor de valores', () => {
  const twoRows = (): Promise<VueWrapper> => openEdit()

  function codes(wrapper: VueWrapper): string[] {
    return wrapper
      .findAll('[data-slot="acad-scale-value"] input[id$="-code"]')
      .map((input) => (input.element as HTMLInputElement).value)
  }

  it('«Bajar» reordena por botón, lo anuncia en la región viva y conserva el foco', async () => {
    const wrapper = await twoRows()

    expect(codes(wrapper)).toEqual(['IN', 'SU'])

    wrapper.findAll('[data-slot="acad-scale-value"]')[0]!.findAll('button')[1]!.element.click()
    await flushPromises()

    expect(codes(wrapper)).toEqual(['SU', 'IN'])
    expect(wrapper.find('[data-slot="acad-scale-announcement"]').text()).toBe(
      'Insuficiente ahora en la posición 2',
    )
    // «Bajar» queda deshabilitado en el último: el foco pasa a «Subir» de la misma fila.
    expect(document.activeElement?.textContent?.trim()).toBe('Subir')
  })

  it('«Subir» está deshabilitado en la primera fila y «Bajar» en la última', async () => {
    const wrapper = await twoRows()
    const rows = wrapper.findAll('[data-slot="acad-scale-value"]')

    expect(rows[0]!.findAll('button')[0]!.attributes('disabled')).toBeDefined()
    expect(rows[1]!.findAll('button')[1]!.attributes('disabled')).toBeDefined()
  })

  it('cada fila es un fieldset con legend «Valor n»', async () => {
    const wrapper = await twoRows()
    const legends = wrapper.findAll('[data-slot="acad-scale-value"] > legend').map((l) => l.text())

    expect(legends).toEqual(['Valor 1', 'Valor 2'])
  })

  it('«Quitar» pasa el foco a la fila que ocupa su sitio; «Añadir» al código del nuevo', async () => {
    const wrapper = await twoRows()

    wrapper.findAll('[data-slot="acad-scale-value"]')[0]!.findAll('button')[2]!.element.click()
    await flushPromises()

    expect(codes(wrapper)).toEqual(['SU'])
    expect(document.activeElement).toBe(
      wrapper.find('[data-slot="acad-scale-value"] input[id$="-code"]').element,
    )

    buttonText(wrapper, 'Añadir valor')!.element.click()
    await flushPromises()

    const inputs = wrapper.findAll('[data-slot="acad-scale-value"] input[id$="-code"]')

    expect(inputs).toHaveLength(2)
    expect(document.activeElement).toBe(inputs[1]!.element)
  })

  it('en la mixta la columna «Aprobado» se recalcula al cambiar el umbral, en texto', async () => {
    vi.mocked(acadApi.getGradingScale).mockResolvedValue(bandScale() as never)

    const wrapper = await openEdit('E2')
    const passing = () =>
      wrapper.findAll('[data-slot="acad-scale-band-passing"] output').map((output) => output.text())

    expect(passing()).toEqual(['Suspenso', 'Aprobado'])

    await fill('#acad-scale-passing_min_value', '0.0')

    expect(passing()).toEqual(['Aprobado', 'Aprobado'])

    await fill('#acad-scale-passing_min_value', '5.1')

    expect(passing()).toEqual(['Suspenso', 'Suspenso'])
  })

  it('la mixta usa «Tramos»/«Añadir tramo»; la cualitativa «Valores»/«Añadir valor»', async () => {
    vi.mocked(acadApi.getGradingScale).mockResolvedValue(bandScale() as never)

    const mixed = await openEdit('E2')

    expect(mixed.find('[data-slot="acad-scale-values"] h2').text()).toBe('Tramos')
    expect(buttonText(mixed, 'Añadir tramo')).toBeDefined()
  })
})

describe('CA-ACAD-099 (§4.9.3): edición', () => {
  it('el tipo está bloqueado y se explica; el formulario parte de lo guardado', async () => {
    const wrapper = await openEdit()

    expect(wrapper.find('h1').text()).toBe('Editar escala')
    expect(wrapper.text()).toContain('El tipo no se puede cambiar')
    expect(byId('acad-scale-type-cualitativa').hasAttribute('disabled')).toBe(true)
    expect(byId('acad-scale-code').value).toBe('CUAL')
  })

  it('PATCH solo con lo modificado; values como conjunto completo con public_id', async () => {
    const wrapper = await openEdit()

    await fill('#acad-scale-code', 'CUAL2')
    await submit(wrapper)

    expect(acadApi.updateGradingScale).toHaveBeenCalledWith('E1', { code: 'CUAL2' })
    expect(routeName(wrapper)).toBe('acad-grading-scale-detail')
  })

  it('un cambio de orden envía la lista entera en el nuevo orden', async () => {
    const wrapper = await openEdit()

    wrapper.findAll('[data-slot="acad-scale-value"]')[0]!.findAll('button')[1]!.element.click()
    await flushPromises()
    await submit(wrapper)

    expect(acadApi.updateGradingScale).toHaveBeenCalledWith('E1', {
      values: [{ public_id: 'V2' }, { public_id: 'V1' }],
    })
  })

  it('sin cambios no hay petición', async () => {
    const wrapper = await openEdit()

    await submit(wrapper)

    expect(acadApi.updateGradingScale).not.toHaveBeenCalled()
    expect(routeName(wrapper)).toBe('acad-grading-scale-detail')
  })

  it('con el curso de la escala cerrado: estado propio sin formulario', async () => {
    vi.mocked(acadApi.getGradingScale).mockResolvedValue(scale({ academic_year: 'Y1' }) as never)

    const wrapper = await openEdit()

    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('[data-slot="acad-scale-read-only"]').text()).toContain('solo lectura')
  })

  it('una escala inexistente (404): estado de error, sin formulario', async () => {
    vi.mocked(acadApi.getGradingScale).mockRejectedValue(
      new ApiError('x', 404, { type: 'urn:pge:error:not-found', status: 404 }),
    )

    const wrapper = await openEdit()

    expect(wrapper.find('form').exists()).toBe(false)
  })
})
