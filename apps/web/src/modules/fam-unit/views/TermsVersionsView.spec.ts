/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §10` (1.14-d), `§4.9`, `CA-FAM-UNIT-074`/`-075`/`-081`, `ADR-062 §5.7`: versiones
 * de los textos legales —listado con filtro por tipo, edición del borrador con el campo traducible, publicación (con
 * re-aceptación solo en las condiciones) y borrado— y consulta de aceptaciones, todo por permiso y por estado.
 * `REQ-FAM-UNIT-003`/`-004`.
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
  cursorPage,
  fill,
  mountView,
  page,
  stubMatchMedia,
  submitForm,
  termsVersion,
} from '../testSupport'

vi.mock('../api')
vi.mock('@/tenant/useTenantBranding', async () => {
  const { shallowRef } = await import('vue')
  const branding = shallowRef<{ default_locale: string; active_locales: string[] } | null>({
    default_locale: 'es-ES',
    active_locales: ['es-ES', 'en'],
  })

  return { useTenantBranding: () => ({ branding }) }
})
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
const { default: TermsVersionsView } = await import('./TermsVersionsView.vue')

const wrappers: VueWrapper[] = []

const ALL = [
  'texto_legal.leer',
  'texto_legal.crear',
  'texto_legal.actualizar',
  'texto_legal.eliminar',
  'texto_legal.publicar',
  'aceptacion_condiciones.leer',
]

const DRAFT = termsVersion({ public_id: 'V3', version_number: 3, status: 'borrador' })
const PUBLISHED = termsVersion({
  public_id: 'V2',
  version_number: 2,
  status: 'publicada',
  published_at: '2026-10-20T08:00:00Z',
})
const IMAGE_DRAFT = termsVersion({
  public_id: 'V5',
  kind: 'consentimiento_imagen_web',
  version_number: 1,
  title: 'Imagen web',
  status: 'borrador',
})

async function open(url = '/configuracion/condiciones-de-uso') {
  const mounted = await mountView('fam-unit-terms', TermsVersionsView, url)

  wrappers.push(mounted.wrapper)

  return mounted
}

function problem(status: number, detail: string, errors: Record<string, unknown[]> = {}): ApiError {
  return new ApiError('x', status, { type: 'urn:pge:error:validation', status, detail, errors })
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  window.localStorage.clear()
  for (const fn of Object.values(famApi)) {
    if (typeof fn === 'function' && 'mockReset' in fn) (fn as ReturnType<typeof vi.fn>).mockReset()
  }
  vi.mocked(famApi.listTermsVersions).mockResolvedValue(
    page([DRAFT, PUBLISHED, IMAGE_DRAFT]) as never,
  )
  vi.mocked(famApi.getTermsVersion).mockImplementation(async (id: string) => {
    return ([DRAFT, PUBLISHED, IMAGE_DRAFT].find((item) => item.public_id === id) ?? DRAFT) as never
  })
  vi.mocked(famApi.listTermsAcceptances).mockResolvedValue(
    cursorPage([
      {
        public_id: 'A1',
        user: 'USR1',
        terms_version: 'V2',
        version_number: 2,
        shown_locale: 'es-ES',
        accepted_at: '2026-10-21T09:00:00Z',
      },
    ]) as never,
  )
  session.__setPermissions(ALL)
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-FAM-UNIT-074: listado de versiones de los textos legales', () => {
  it('pinta tipo, versión, título y estado (con texto e icono)', async () => {
    const { wrapper } = await open()
    const rows = wrapper
      .find('#fam-unit-terms-versions')
      .element.closest('section')!
      .querySelectorAll('tbody tr')

    expect(rows).toHaveLength(3)
    expect(rows[0]!.textContent).toContain('Borrador')
    expect(rows[1]!.textContent).toContain('Publicada')
    expect(rows[2]!.textContent).toContain('Consentimiento de imagen: web')

    for (const row of rows) {
      expect(
        row.querySelector('[data-slot="fam-unit-status"] svg[aria-hidden="true"]'),
      ).not.toBeNull()
    }
  })

  it('el listado se pide con el filtro por tipo y estado de la URL', async () => {
    await open(
      '/configuracion/condiciones-de-uso?kind=condiciones_uso&status=borrador&sort=-version_number',
    )

    expect(vi.mocked(famApi.listTermsVersions).mock.calls.at(-1)![0]).toMatchObject({
      kind: 'condiciones_uso',
      status: 'borrador',
      sort: '-version_number',
    })
  })

  it('una versión publicada es inmutable: solo se puede leer', async () => {
    await open()

    expect(buttonByLabel('Leer Condiciones de uso · versión 2')).toBeDefined()
    expect(buttonByLabel('Editar Condiciones de uso · versión 2')).toBeUndefined()
    expect(buttonByLabel('Publicar Condiciones de uso · versión 2')).toBeUndefined()
    expect(buttonByLabel('Eliminar Condiciones de uso · versión 2')).toBeUndefined()
    expect(buttonByLabel('Editar Condiciones de uso · versión 3')).toBeDefined()
  })

  it('leer muestra el texto como texto (nunca HTML) en una región con nombre alcanzable con el teclado', async () => {
    vi.mocked(famApi.getTermsVersion).mockResolvedValue(
      termsVersion({ public_id: 'V2', body: 'Texto con <b>marcado</b>.' }) as never,
    )

    await open()

    buttonByLabel('Leer Condiciones de uso · versión 2')!.click()
    await flushPromises()

    const region = document.body.querySelector('[data-slot="fam-unit-terms-text"] [role="region"]')!

    expect(region.getAttribute('tabindex')).toBe('0')
    expect(region.getAttribute('aria-label')).toBe('Texto de la versión')
    expect(region.textContent).toContain('<b>marcado</b>')
    expect(region.querySelector('b')).toBeNull()
  })
})

describe('CA-FAM-UNIT-081 / INV-002: acciones por permiso', () => {
  it('con solo texto_legal.leer no hay alta, edición, publicación, borrado ni aceptaciones', async () => {
    session.__setPermissions(['texto_legal.leer'])

    const { wrapper } = await open()

    expect(buttonByText('Nueva versión')).toBeUndefined()
    expect(buttonByLabel('Editar Condiciones de uso · versión 3')).toBeUndefined()
    expect(buttonByLabel('Publicar Condiciones de uso · versión 3')).toBeUndefined()
    expect(buttonByLabel('Eliminar Condiciones de uso · versión 3')).toBeUndefined()
    expect(wrapper.find('#fam-unit-terms-acceptances').exists()).toBe(false)
    expect(famApi.listTermsAcceptances).not.toHaveBeenCalled()
  })

  it('con aceptacion_condiciones.leer se listan las aceptaciones (cursor)', async () => {
    const { wrapper } = await open()
    const text = wrapper.find('#fam-unit-terms-acceptances').element.closest('section')!.textContent

    expect(famApi.listTermsAcceptances).toHaveBeenCalled()
    expect(text).toContain('USR1')
    expect(text).toContain('es-ES')
  })
})

describe('CA-FAM-UNIT-074/-075: alta y edición del borrador', () => {
  it('el alta envía tipo y títulos y textos por idioma activo (el por defecto, obligatorio)', async () => {
    vi.mocked(famApi.createTermsVersion).mockResolvedValue(DRAFT as never)

    const { wrapper } = await open()

    buttonByText('Nueva versión')!.click()
    await flushPromises()

    expect(
      document.body.querySelector<HTMLInputElement>('#fam-unit-terms-title-es-ES')!.required,
    ).toBe(true)
    expect(document.body.querySelector('#fam-unit-terms-title-en')!.hasAttribute('required')).toBe(
      false,
    )
    expect(document.body.querySelector('textarea#fam-unit-terms-body-es-ES')).not.toBeNull()
    expect(document.body.querySelector('#fam-unit-terms-title-en')!.getAttribute('lang')).toBe('en')

    await chooseOption('#fam-unit-terms-kind', 'Condiciones de uso')
    await fill('#fam-unit-terms-title-es-ES', ' Condiciones ')
    await fill('#fam-unit-terms-body-es-ES', 'Texto del centro.')
    await fill('#fam-unit-terms-title-en', 'Terms')
    await submitForm('fam-unit-terms-form')

    expect(famApi.createTermsVersion).toHaveBeenCalledWith({
      kind: 'condiciones_uso',
      title_translations: { 'es-ES': 'Condiciones', en: 'Terms' },
      body_translations: { 'es-ES': 'Texto del centro.' },
    })
    expect(wrapper.text()).toContain('Borrador creado.')
  })

  it('CA-FAM-UNIT-075: el 422 default_locale_missing se muestra junto al campo y nada cambia', async () => {
    vi.mocked(famApi.createTermsVersion).mockRejectedValue(
      problem(422, 'Datos no válidos.', {
        title_translations: [
          { code: 'translations.default_locale_required', message: 'Falta el idioma por defecto.' },
        ],
        'body_translations.en': [{ code: 'x', message: 'El valor no puede estar vacío.' }],
      }),
    )

    await open()

    buttonByText('Nueva versión')!.click()
    await flushPromises()
    await chooseOption('#fam-unit-terms-kind', 'Condiciones de uso')
    await fill('#fam-unit-terms-title-es-ES', 'x')
    await fill('#fam-unit-terms-body-es-ES', 'y')
    await submitForm('fam-unit-terms-form')

    expect(document.body.querySelector('#fam-unit-terms-title-errors')?.textContent).toContain(
      'Falta el idioma por defecto.',
    )
    expect(document.body.querySelector('#fam-unit-terms-body-en-errors')?.textContent).toContain(
      'no puede estar vacío',
    )
    expect(vi.mocked(famApi.listTermsVersions).mock.calls).toHaveLength(1)
  })

  it('la edición manda solo los idiomas que cambian y el tipo queda inmutable', async () => {
    vi.mocked(famApi.updateTermsVersion).mockResolvedValue(DRAFT as never)

    const { wrapper } = await open()

    buttonByLabel('Editar Condiciones de uso · versión 3')!.click()
    await flushPromises()

    expect(document.body.querySelector('#fam-unit-terms-kind')!.hasAttribute('disabled')).toBe(true)
    expect(document.body.textContent).toContain('El tipo no se puede cambiar')

    await fill('#fam-unit-terms-body-en', 'Terms text')
    await submitForm('fam-unit-terms-form')

    expect(famApi.updateTermsVersion).toHaveBeenCalledWith('V3', {
      body_translations: { en: 'Terms text' },
    })
    expect(wrapper.text()).toContain('Borrador actualizado.')
  })

  it('sin cambios no se envía nada y se cierra el diálogo', async () => {
    await open()

    buttonByLabel('Editar Condiciones de uso · versión 3')!.click()
    await flushPromises()
    await submitForm('fam-unit-terms-form')

    expect(famApi.updateTermsVersion).not.toHaveBeenCalled()
    expect(document.body.querySelector('[data-slot="fam-unit-terms-form"]')).toBeNull()
  })

  it('un 409 not_draft al editar se muestra en el diálogo (el servidor decide)', async () => {
    vi.mocked(famApi.updateTermsVersion).mockRejectedValue(
      problem(409, 'Solo se puede editar un borrador.'),
    )

    await open()

    buttonByLabel('Editar Condiciones de uso · versión 3')!.click()
    await flushPromises()
    await fill('#fam-unit-terms-body-es-ES', 'Otro texto')
    await submitForm('fam-unit-terms-form')

    expect(document.body.textContent).toContain('Solo se puede editar un borrador.')
  })
})

describe('CA-FAM-UNIT-072/-074: publicación', () => {
  it('condiciones de uso: ofrece exigir re-aceptación (desmarcado por defecto) y publica con ese valor', async () => {
    vi.mocked(famApi.publishTermsVersion).mockResolvedValue(PUBLISHED as never)

    const { wrapper } = await open()

    buttonByLabel('Publicar Condiciones de uso · versión 3')!.click()
    await flushPromises()

    const checkbox = document.body.querySelector<HTMLInputElement>(
      '[data-slot="fam-unit-terms-publish"] input[type="checkbox"]',
    )!

    expect(checkbox.checked).toBe(false)
    checkbox.click()
    await flushPromises()
    await submitForm('fam-unit-terms-publish')

    expect(famApi.publishTermsVersion).toHaveBeenCalledWith('V3', true)
    expect(wrapper.text()).toContain('Versión publicada.')
  })

  it('texto de imagen: no se ofrece re-aceptación y se publica sin ella', async () => {
    vi.mocked(famApi.publishTermsVersion).mockResolvedValue(PUBLISHED as never)

    await open()

    buttonByLabel('Publicar Consentimiento de imagen: web · versión 1')!.click()
    await flushPromises()

    expect(
      document.body.querySelector('[data-slot="fam-unit-terms-publish"] input[type="checkbox"]'),
    ).toBeNull()
    expect(document.body.textContent).toContain('no cambia ninguna decisión de consentimiento')

    await submitForm('fam-unit-terms-publish')

    expect(famApi.publishTermsVersion).toHaveBeenCalledWith('V5', undefined)
  })

  it('CA-FAM-UNIT-075: un 422 default_locale_missing se muestra en el diálogo y no refresca', async () => {
    vi.mocked(famApi.publishTermsVersion).mockRejectedValue(
      problem(422, 'Falta el texto en el idioma por defecto.'),
    )

    await open()

    buttonByLabel('Publicar Condiciones de uso · versión 3')!.click()
    await flushPromises()
    await submitForm('fam-unit-terms-publish')

    expect(
      document.body.querySelector('[data-slot="fam-unit-terms-publish"] [role="alert"]')
        ?.textContent,
    ).toContain('Falta el texto en el idioma por defecto.')
    expect(vi.mocked(famApi.listTermsVersions).mock.calls).toHaveLength(1)
  })
})

describe('CA-FAM-UNIT-074: borrado del borrador', () => {
  it('pide confirmación antes de borrar y refresca', async () => {
    vi.mocked(famApi.deleteTermsVersion).mockResolvedValue(undefined as never)

    const { wrapper } = await open()

    buttonByLabel('Eliminar Condiciones de uso · versión 3')!.click()
    await flushPromises()
    expect(famApi.deleteTermsVersion).not.toHaveBeenCalled()
    buttonByText('Eliminar Condiciones de uso · versión 3')!.click()
    await flushPromises()

    expect(famApi.deleteTermsVersion).toHaveBeenCalledWith('V3')
    expect(wrapper.text()).toContain('Borrador eliminado.')
  })
})
