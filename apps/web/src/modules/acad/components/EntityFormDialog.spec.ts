/**
 * `docs/modulos/REQ-ACAD/funcional.md §10.1`, `§4.2`-`§4.4` (1.11-b):
 * `CA-ACAD-016` (no se envía sin el idioma por defecto; una entrada por idioma
 * activo), `CA-ACAD-050` (errores `translations.*` por idioma junto al campo, la
 * pantalla no cambia), `RN-ACAD-13` (`name` no se envía en las traducibles) y
 * `ADR-062 §5.2` (el `PATCH` envía solo los idiomas que cambian).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { i18n, setLocale } from '@/i18n'
import { course, group, level } from '../testSupport'
import { fill, submitForm } from '../testSupport'

vi.mock('@/tenant/useTenantBranding', async () => {
  const { shallowRef } = await import('vue')
  const branding = shallowRef<{ default_locale: string; active_locales: string[] } | null>(null)

  return {
    useTenantBranding: () => ({ branding }),
    __setBranding: (defaultLocale: string, active: string[]) => {
      branding.value = { default_locale: defaultLocale, active_locales: active }
    },
  }
})

const tenant = (await import('@/tenant/useTenantBranding')) as unknown as {
  __setBranding: (defaultLocale: string, active: string[]) => void
}

const { default: EntityFormDialog } = await import('./EntityFormDialog.vue')

const wrappers: VueWrapper[] = []

function open(props: Record<string, unknown>): VueWrapper {
  const wrapper = mount(EntityFormDialog, {
    props: { open: true, entity: null, ...props } as never,
    global: { plugins: [i18n] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)

  return wrapper
}

function problem(status: number, errors: Record<string, { code: string; message: string }[]>) {
  return new ApiError('x', status, {
    type: 'urn:pge:error:validation',
    status,
    detail: 'Detalle del servidor',
    errors,
  })
}

beforeEach(() => {
  setLocale('es')
  tenant.__setBranding('es-ES', ['es-ES', 'en', 'fr'])
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ACAD-016: una entrada por idioma activo y no se envía sin el idioma por defecto', () => {
  it('muestra una entrada por idioma activo con su lang, el por defecto primero y obligatorio', async () => {
    open({ kind: 'course', save: vi.fn() })
    await flushPromises()

    const inputs = [...document.body.querySelectorAll<HTMLInputElement>('[id^="acad-name-"]')]

    expect(inputs.map((input) => input.getAttribute('lang'))).toEqual(['es-ES', 'en', 'fr'])
    expect(inputs[0]!.required).toBe(true)
    expect(inputs[1]!.required).toBe(false)
  })

  it('un centro con default en: la entrada obligatoria es la inglesa', async () => {
    tenant.__setBranding('en', ['en'])
    open({ kind: 'course', save: vi.fn() })
    await flushPromises()

    const inputs = [...document.body.querySelectorAll<HTMLInputElement>('[id^="acad-name-"]')]

    expect(inputs.map((input) => input.getAttribute('lang'))).toEqual(['en'])
    expect(inputs[0]!.required).toBe(true)
  })

  it('sin el nombre en el idioma por defecto no se envía la petición', async () => {
    const save = vi.fn()
    open({ kind: 'course', save, extra: { education_level: 'L1' } })
    await flushPromises()

    await fill('#acad-code', '2ESO')
    await fill('#acad-name-en', 'Second year')
    await submitForm()

    expect(save).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('Falta el nombre en el idioma por defecto')
  })
})

describe('alta: cuerpo enviado', () => {
  it('envía name_translations recortado, el padre de extra y nunca name (RN-ACAD-13)', async () => {
    const save = vi.fn().mockResolvedValue({})
    const wrapper = open({ kind: 'course', save, extra: { education_level: 'L1' } })
    await flushPromises()

    await fill('#acad-code', ' 2ESO ')
    await fill('#acad-name-es-ES', ' 2.º ESO ')
    await fill('#acad-name-en', '   ')
    await fill('#acad-position', '4')
    await submitForm()

    expect(save).toHaveBeenCalledWith({
      education_level: 'L1',
      code: '2ESO',
      name_translations: { 'es-ES': '2.º ESO' },
      position: 4,
    })
    expect(save.mock.calls[0]![0]).not.toHaveProperty('name')
    expect(wrapper.emitted('saved')).toHaveLength(1)
    expect(wrapper.emitted('update:open')![0]).toEqual([false])
  })

  it('grupo: nombre de texto simple, sí se envía como name', async () => {
    const save = vi.fn().mockResolvedValue({})
    open({ kind: 'group', save, extra: { course: 'C1' } })
    await flushPromises()

    await fill('#acad-code', 'A')
    await fill('#acad-name', '2.º ESO A')
    await submitForm()

    expect(save).toHaveBeenCalledWith({ course: 'C1', code: 'A', name: '2.º ESO A' })
  })
})

describe('edición: solo viaja lo que cambia (ADR-038 §9.2, ADR-062 §5.2)', () => {
  it('PATCH de traducciones: solo los idiomas cambiados, null para el que se vacía', async () => {
    const save = vi.fn().mockResolvedValue({})
    open({
      kind: 'course',
      save,
      entity: course({ name_translations: { 'es-ES': '2.º ESO', en: 'Second', de: 'Zweite' } }),
    })
    await flushPromises()

    await fill('#acad-name-en', '')
    await fill('#acad-name-fr', 'Deuxième')
    await submitForm()

    // `de` (idioma no activo) no se menciona: el servidor lo conserva.
    expect(save).toHaveBeenCalledWith({ name_translations: { en: null, fr: 'Deuxième' } })
  })

  it('sin cambios no se envía nada y se cierra', async () => {
    const save = vi.fn()
    const wrapper = open({ kind: 'group', save, entity: group() })
    await flushPromises()

    await submitForm()

    expect(save).not.toHaveBeenCalled()
    expect(wrapper.emitted('update:open')![0]).toEqual([false])
  })

  it('el grupo cambia solo su nombre', async () => {
    const save = vi.fn().mockResolvedValue({})
    open({ kind: 'group', save, entity: group() })
    await flushPromises()

    await fill('#acad-name', '2.º ESO A (bilingüe)')
    await submitForm()

    expect(save).toHaveBeenCalledWith({ name: '2.º ESO A (bilingüe)' })
  })

  it('una etapa con stage desconocido ofrece su código en crudo (ADR-038 §7.3)', async () => {
    open({ kind: 'level', save: vi.fn(), entity: level({ stage: 'otra' }) })
    await flushPromises()

    expect(document.body.querySelector('#acad-stage')!.textContent).toContain('otra')
  })
})

describe('CA-ACAD-050: errores del servidor junto al campo y sin cambiar la pantalla', () => {
  it('translations.* por idioma junto a su entrada', async () => {
    const save = vi.fn().mockRejectedValue(
      problem(422, {
        'name_translations.en': [
          { code: 'translations.empty_value', message: 'El valor no puede estar vacío.' },
        ],
      }),
    )
    const wrapper = open({ kind: 'course', save, extra: { education_level: 'L1' } })
    await flushPromises()

    await fill('#acad-code', '2ESO')
    await fill('#acad-name-es-ES', 'Segundo')
    await submitForm()

    const input = document.body.querySelector('#acad-name-en')!

    expect(input.getAttribute('aria-invalid')).toBe('true')
    expect(document.body.querySelector('#acad-name-en-errors')!.textContent).toContain(
      'no puede estar vacío',
    )
    expect(wrapper.emitted('saved')).toBeUndefined()
    expect(wrapper.emitted('update:open')).toBeUndefined()
  })

  it('translations.default_locale_required del campo entero, junto al grupo', async () => {
    const save = vi.fn().mockRejectedValue(
      problem(422, {
        name_translations: [
          { code: 'translations.default_locale_required', message: 'Falta el idioma por defecto.' },
        ],
      }),
    )
    open({ kind: 'course', save, entity: course() })
    await flushPromises()

    await fill('#acad-name-en', 'Second')
    await submitForm()

    expect(document.body.querySelector('#acad-name-errors')!.textContent).toContain(
      'Falta el idioma por defecto',
    )
  })

  it('code_taken junto al campo código', async () => {
    const save = vi.fn().mockRejectedValue(
      problem(422, {
        code: [{ code: 'acad.validation.code_taken', message: 'Ese código ya existe.' }],
      }),
    )
    open({ kind: 'group', save, extra: { course: 'C1' } })
    await flushPromises()

    await fill('#acad-code', 'A')
    await fill('#acad-name', 'X')
    await submitForm()

    expect(document.body.querySelector('#acad-code-errors')!.textContent).toContain(
      'Ese código ya existe',
    )
  })

  it('409 academic-year-closed: el detalle del servidor junto a la acción, el diálogo sigue abierto', async () => {
    const save = vi.fn().mockRejectedValue(
      new ApiError('x', 409, {
        type: 'urn:pge:error:academic-year-closed',
        status: 409,
        detail: 'El curso académico está cerrado.',
        errors: { academic_year: [{ code: 'curso.academic_year_closed', message: 'Cerrado' }] },
      }),
    )
    const wrapper = open({ kind: 'group', save, entity: group() })
    await flushPromises()

    await fill('#acad-name', 'Otro')
    await submitForm()

    expect(document.body.textContent).toContain('El curso académico está cerrado.')
    expect(wrapper.emitted('update:open')).toBeUndefined()
  })
})
