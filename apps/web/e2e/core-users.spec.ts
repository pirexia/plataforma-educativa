import { test, expect, type Page } from '@playwright/test'

/**
 * `docs/modulos/REQ-CORE/funcional.md §14.18`: criterios **[Playwright]** de
 * 1.9b que necesitan *layout* real y foco de verdad — `CA-CORE-259`
 * (tarjetas a 320 px en el listado de usuarios) y `CA-CORE-260` (recorrido
 * solo con teclado, etiquetas y objetivos táctiles en el alta y la ficha de
 * usuario). Sin backend real: se intercepta la API con `page.route`, mismo
 * patrón que `e2e/shell.spec.ts`. Auditoría, configuración del centro y
 * sesiones son de 1.9d/1.9e/1.9f: sus comprobaciones llegan con ellos.
 */

const BRANDING = {
  name: 'Centro de ejemplo',
  color_primary: '#1D4ED8',
  color_secondary: '#FFFFFF',
  logo_url: null,
  favicon_url: null,
  login_background_url: null,
  default_locale: 'es-ES',
  active_locales: ['es-ES', 'en', 'de', 'fr'],
}

const ME = {
  public_id: '01J-ME',
  email: 'ana@example.com',
  status: 'activo',
  person: {
    public_id: '01J-PERSON',
    given_name: 'Ana',
    family_name_1: 'García',
    family_name_2: null,
    contact_email: null,
    contact_phone: null,
    document_type: null,
    document_number: null,
    birth_date: null,
    locale: 'es-ES',
  },
  roles: [],
  permissions: [
    'usuario.leer',
    'usuario.crear',
    'usuario.actualizar',
    'usuario.eliminar',
    'rol.leer',
    'asignacion_rol.crear',
    'asignacion_rol.leer',
    'invitacion.crear',
  ],
  email_verified_at: '2026-01-01T00:00:00Z',
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
  deleted_at: null,
}

function user(id: string, given: string) {
  return {
    public_id: id,
    email: `${given.toLowerCase()}@example.com`,
    status: id === 'U2' ? 'pendiente' : 'activo',
    person: {
      public_id: `P-${id}`,
      given_name: given,
      family_name_1: 'Pérez',
      family_name_2: 'Gómez',
      contact_email: null,
      contact_phone: null,
      document_type: null,
      document_number: null,
      birth_date: null,
      locale: 'es-ES',
    },
    roles: [{ public_id: 'R1', code: 'docente', name: 'Docente' }],
    email_verified_at: null,
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-09-01T10:00:00Z',
    deleted_at: null,
  }
}

const ROLES = {
  data: [
    { public_id: 'R1', code: 'docente', name: 'Docente', is_system: true },
    { public_id: 'R2', code: 'secretaria', name: 'Secretaría', is_system: true },
  ],
  meta: { current_page: 1, per_page: 100, total: 2, last_page: 1 },
}

async function mockBackend(page: Page): Promise<void> {
  const json = (body: unknown) => ({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify(body),
  })

  await page.route('**/tenant/branding', (route) => route.fulfill(json(BRANDING)))
  await page.route('**/auth/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }))
  await page.route('**/me', (route) => route.fulfill(json(ME)))
  await page.route(/\/api\/v1\/roles(\?.*)?$/, (route) => route.fulfill(json(ROLES)))
  await page.route(/\/api\/v1\/users(\?.*)?$/, (route) =>
    route.fulfill(
      json({
        data: [user('U1', 'Lucía'), user('U2', 'Mario'), user('U3', 'Elena')],
        meta: { current_page: 1, per_page: 25, total: 3, last_page: 1 },
      }),
    ),
  )
  await page.route(/\/api\/v1\/users\/U1(\?.*)?$/, (route) =>
    route.fulfill(json(user('U1', 'Lucía'))),
  )
  await page.route(/\/api\/v1\/users\/U1\/roles$/, (route) =>
    route.fulfill(json({ data: [{ public_id: 'R1', code: 'docente', name: 'Docente' }] })),
  )
}

async function documentFitsWidth(page: Page): Promise<boolean> {
  return page.evaluate(
    () => document.documentElement.scrollWidth <= document.documentElement.clientWidth,
  )
}

test.describe('CA-CORE-259 (RUX-RESP-004): el listado de usuarios, en tarjetas por debajo de 768 px', () => {
  test('a 320 px hay lista de tarjetas y no table, sin desplazamiento horizontal; a 1024 px, table', async ({
    page,
  }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 320, height: 900 })
    await page.goto('/administracion/usuarios')

    await expect(page.locator('ul[data-slot="data-table-cards"] > li')).toHaveCount(3)
    await expect(page.locator('table')).toHaveCount(0)
    expect(await documentFitsWidth(page)).toBe(true)

    await page.setViewportSize({ width: 1024, height: 900 })

    await expect(page.locator('table')).toBeVisible()
    await expect(page.locator('ul[data-slot="data-table-cards"]')).toHaveCount(0)
    expect(await documentFitsWidth(page)).toBe(true)
  })
})

test.describe('CA-CORE-260 (RUX-004, WCAG 2.2 AA): alta y ficha de usuario', () => {
  test('el alta se recorre con el teclado en orden de documento y todo campo tiene etiqueta', async ({
    page,
  }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 1280, height: 900 })
    await page.goto('/administracion/usuarios/nuevo')
    await expect(page.getByRole('heading', { level: 1, name: 'Nuevo usuario' })).toBeVisible()

    for (const label of [
      'Correo de acceso',
      'Nombre',
      'Primer apellido',
      'Segundo apellido',
      'Fecha de nacimiento',
      'Tipo de documento',
      'Número de documento',
      'Correo de contacto',
      'Teléfono de contacto',
      'Idioma preferido',
    ]) {
      await expect(page.getByLabel(label, { exact: false }).first(), label).toBeVisible()
    }

    // Obligatorios marcados de forma no solo visual: texto para lectores de pantalla y `required`.
    for (const id of ['email', 'given_name', 'family_name_1']) {
      await expect(page.locator(`#user-form-${id}`)).toHaveAttribute('required', '')
      await expect(page.locator(`label[for="user-form-${id}"] .sr-only`)).toHaveText(
        '(obligatorio)',
      )
    }

    await page.locator('#user-form-email').focus()

    const visited: string[] = ['user-form-email']

    for (let step = 0; step < 30; step += 1) {
      await page.keyboard.press('Tab')

      const id = await page.evaluate(() => {
        const el = document.activeElement as HTMLElement | null

        return !el || el === document.body ? 'BODY' : el.id || el.textContent?.trim() || el.tagName
      })

      visited.push(id)

      if (id === 'Guardar') {
        break
      }
    }

    const fieldOrder = [
      'user-form-email',
      'user-form-given_name',
      'user-form-family_name_1',
      'user-form-family_name_2',
      'user-form-birth_date',
      'user-form-document_type',
      'user-form-document_number',
      'user-form-contact_email',
      'user-form-contact_phone',
      'user-form-locale',
      'user-form-roles',
    ]
    const positions = fieldOrder.map((id) => visited.indexOf(id))

    expect(
      positions.every((position) => position >= 0),
      `recorrido: ${visited.join(' | ')}`,
    ).toBe(true)
    expect(
      [...positions].sort((a, b) => a - b),
      `orden: ${visited.join(' | ')}`,
    ).toEqual(positions)
    expect(visited).not.toContain('BODY')
    expect(visited).toContain('Guardar')
  })

  test('la ficha se recorre con el teclado y el diálogo de confirmación atrapa y devuelve el foco', async ({
    page,
  }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 1280, height: 900 })
    await page.goto('/administracion/usuarios/U1')
    await expect(page.getByRole('heading', { level: 1, name: 'Lucía Pérez Gómez' })).toBeVisible()

    const delete_ = page.getByRole('button', { name: 'Dar de baja' })

    await delete_.focus()
    await page.keyboard.press('Enter')

    const dialog = page.getByRole('alertdialog')

    await expect(dialog).toBeVisible()
    expect(
      await page.evaluate(() => document.activeElement?.closest('[role="alertdialog"]') !== null),
    ).toBe(true)

    // El foco queda atrapado: varias tabulaciones no lo sacan del diálogo.
    for (let step = 0; step < 4; step += 1) {
      await page.keyboard.press('Tab')
      expect(
        await page.evaluate(() => document.activeElement?.closest('[role="alertdialog"]') !== null),
      ).toBe(true)
    }

    await page.keyboard.press('Escape')
    await expect(dialog).toHaveCount(0)
    await expect(delete_).toBeFocused()
  })

  test.describe('objetivos táctiles de 44 px con puntero grueso (OPEN-CORE-14)', () => {
    test.use({ hasTouch: true, isMobile: true })

    async function expectTarget(page: Page, selector: string, name: string): Promise<void> {
      const box = await page.locator(selector).first().boundingBox()

      expect(box, name).not.toBeNull()
      expect(box!.height, `${name}: alto`).toBeGreaterThanOrEqual(43.5)
      expect(box!.width, `${name}: ancho`).toBeGreaterThanOrEqual(43.5)
    }

    test('campos, selector, casillas y botones del alta', async ({ page }) => {
      await mockBackend(page)
      await page.setViewportSize({ width: 360, height: 900 })
      await page.goto('/administracion/usuarios/nuevo')
      await expect(page.locator('#user-form-email')).toBeVisible()

      for (const id of ['email', 'given_name', 'contact_phone', 'locale']) {
        await expectTarget(page, `#user-form-${id}`, id)
      }

      // La casilla se activa pulsando su etiqueta: el objetivo es la etiqueta entera.
      await expectTarget(page, 'label:has(#user-form-roles)', 'casilla de rol')
      await expectTarget(
        page,
        'label:has(input[type="checkbox"]):has-text("invitación")',
        'invitación',
      )
      await expectTarget(page, 'button[type="submit"]', 'guardar')
    })
  })
})
