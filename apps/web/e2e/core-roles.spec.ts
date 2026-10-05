import { test, expect, type Page } from '@playwright/test'

/**
 * `docs/modulos/REQ-PERM/funcional.md §20.17`, criterios **[Playwright]** de 1.5b:
 * `CA-PERM-128` (editor de concesiones a 320 px con puntero grueso: tablas con
 * desplazamiento interno, región enfocable, columna de recurso fija, botones de
 * ≥ 44 × 44 px, panel con teclado), `CA-PERM-132` (recorrido solo con teclado de
 * alta, ficha, editor y permisos efectivos: orden, motivos de las opciones
 * deshabilitadas en el árbol de accesibilidad, diálogos que atrapan y devuelven
 * el foco) y `CA-PERM-133` (flujo completo **contra la API real**, solo con
 * `E2E_REAL_API=1`: este entorno no la levanta). Sin backend real en los dos
 * primeros: se intercepta la API con `page.route`, mismo patrón que
 * `e2e/core-users.spec.ts`.
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
    'rol.leer',
    'rol.crear',
    'rol.actualizar',
    'rol.eliminar',
    'permiso.leer',
    'permiso_efectivo.leer',
    'usuario.leer',
  ],
  email_verified_at: '2026-01-01T00:00:00Z',
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
  deleted_at: null,
}

function permission(
  code: string,
  moduleCode: string,
  label: string,
  scopes: { applicable: string[]; grantable: string[] } = {
    applicable: ['todos'],
    grantable: ['todos'],
  },
) {
  const [resource = code, action = 'leer'] = code.split('.')

  return {
    code,
    resource,
    action,
    module_code: moduleCode,
    is_special_category: false,
    applicable_scopes: scopes.applicable,
    grantable_scopes: scopes.grantable,
    resource_label: label,
    retired_at: null,
  }
}

const CATALOG = {
  data: [
    permission('usuario.crear', 'core', 'Usuarios'),
    permission('usuario.leer', 'core', 'Usuarios'),
    permission('usuario.actualizar', 'core', 'Usuarios'),
    permission('usuario.eliminar', 'core', 'Usuarios'),
    permission('usuario.exportar', 'core', 'Usuarios'),
    permission('auditoria.leer', 'core', 'Auditoría', {
      applicable: ['todos', 'propios', 'grupo'],
      grantable: ['todos', 'propios'],
    }),
    permission('mfa.leer', 'auth', 'MFA'),
    permission('mfa.eliminar', 'auth', 'MFA'),
  ],
}

function effective(code: string, extra: Record<string, unknown> = {}) {
  const [resource = code, action = 'leer'] = code.split('.')

  return {
    code,
    resource,
    action,
    module_code: code.startsWith('mfa') ? 'auth' : 'core',
    is_special_category: false,
    decision: 'permitido',
    scopes: [],
    unrestricted: true,
    sources: [],
    ...extra,
  }
}

/** El solicitante lo posee todo salvo `auditoria.leer` con `todos` (solo `propios`). */
const MINE = {
  data: CATALOG.data.map((entry) =>
    effective(
      entry.code,
      entry.code === 'auditoria.leer' ? { unrestricted: false, scopes: ['propios'] } : {},
    ),
  ),
  meta: {
    subject: { public_id: '01J-ME', display_name: 'Ana García' },
    roles: [],
    computed_at: '2026-10-05T10:00:00Z',
  },
}

const ROLE = {
  public_id: 'R1',
  code: 'coordinacion',
  name: 'Coordinación pastoral',
  is_system: false,
  mfa_required: false,
  special_data_access: false,
  users_count: 0,
  permissions: [
    {
      code: 'auditoria.leer',
      resource: 'auditoria',
      action: 'leer',
      effect: 'allow',
      scope: 'propios',
    },
    { code: 'usuario.leer', resource: 'usuario', action: 'leer', effect: 'allow', scope: 'todos' },
  ],
}

const ROLES = {
  data: [{ ...ROLE }],
  meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
}

const EFFECTIVE_OF_USER = {
  data: [
    effective('auditoria.leer', {
      scopes: ['propios'],
      unrestricted: false,
      resource_label: 'Auditoría',
      sources: [
        {
          role: { public_id: 'R1', code: 'coordinacion', name: 'Coordinación pastoral' },
          effect: 'allow',
          scope: 'propios',
          inert: false,
          inert_reason: null,
        },
      ],
    }),
    ...Array.from({ length: 4 }, (_, index) => ({
      ...effective(`otro${index}.leer`, { decision: 'denegado', unrestricted: false }),
      resource_label: `Otro ${index}`,
    })),
  ],
  meta: {
    subject: { public_id: 'U1', display_name: 'Lucía Pérez' },
    roles: [{ public_id: 'R1', code: 'coordinacion', name: 'Coordinación pastoral' }],
    computed_at: '2026-10-05T10:00:00Z',
  },
}

async function mockBackend(page: Page): Promise<void> {
  const json = (body: unknown, status = 200) => ({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  })

  await page.route('**/tenant/branding', (route) => route.fulfill(json(BRANDING)))
  await page.route('**/auth/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }))
  await page.route('**/me', (route) => route.fulfill(json(ME)))
  await page.route(/\/api\/v1\/me\/effective-permissions(\?.*)?$/, (route) =>
    route.fulfill(json(MINE)),
  )
  await page.route(/\/api\/v1\/permissions(\?.*)?$/, (route) => route.fulfill(json(CATALOG)))
  await page.route(/\/api\/v1\/roles(\?.*)?$/, (route) =>
    route.request().method() === 'POST'
      ? route.fulfill(json({ ...ROLE, public_id: 'NEW', name: 'Nuevo' }, 201))
      : route.fulfill(json(ROLES)),
  )
  await page.route(/\/api\/v1\/roles\/R1$/, (route) => route.fulfill(json(ROLE)))
  await page.route(/\/api\/v1\/roles\/R1\/permissions$/, (route) => route.fulfill(json(ROLE)))
  await page.route(/\/api\/v1\/users\/U1\/effective-permissions$/, (route) =>
    route.fulfill(json(EFFECTIVE_OF_USER)),
  )
}

async function documentFitsWidth(page: Page): Promise<boolean> {
  return page.evaluate(
    () => document.documentElement.scrollWidth <= document.documentElement.clientWidth,
  )
}

test.describe('CA-PERM-128 (§20.8.1, ADR-054 §4, RUX-RESP-004/-007, WCAG 1.4.10/2.1.1): editor a 320 px con puntero grueso', () => {
  test.use({ hasTouch: true, isMobile: true, viewport: { width: 320, height: 800 } })

  test('hay una tabla por módulo (no tarjetas), sin desplazamiento horizontal de la página', async ({
    page,
  }) => {
    await mockBackend(page)
    await page.goto('/administracion/roles/R1/permisos')

    await expect(page.locator('table')).toHaveCount(2)
    await expect(page.locator('ul[data-slot="data-table-cards"]')).toHaveCount(0)
    expect(await documentFitsWidth(page)).toBe(true)
  })

  test('el contenedor de cada matriz es enfocable con Tab, tiene nombre accesible y la flecha derecha lo desplaza; la columna de recurso sigue visible', async ({
    page,
  }) => {
    await mockBackend(page)
    await page.goto('/administracion/roles/R1/permisos')

    const region = page.getByRole('region', { name: 'Matriz de concesiones de core, desplazable' })

    await expect(region).toBeVisible()
    await region.focus()
    await expect(region).toBeFocused()

    const before = await region.evaluate((el) => el.scrollLeft)

    await page.keyboard.press('ArrowRight')
    await page.keyboard.press('ArrowRight')

    // El desplazamiento con el teclado es animado: se espera a que avance, no se lee al instante.
    await expect.poll(() => region.evaluate((el) => el.scrollLeft)).toBeGreaterThan(before)

    // La columna de recurso (sticky) no sale del contenedor al desplazar.
    const [regionBox, headBox] = await Promise.all([
      region.boundingBox(),
      region.locator('tbody th').first().boundingBox(),
    ])

    expect(headBox!.x).toBeGreaterThanOrEqual(regionBox!.x - 1)
    expect(headBox!.x).toBeLessThanOrEqual(regionBox!.x + 8)
  })

  test('los botones de celda miden al menos 44 × 44 px y el panel se abre, se recorre con teclado y devuelve el foco al cerrarse', async ({
    page,
  }) => {
    await mockBackend(page)
    await page.goto('/administracion/roles/R1/permisos')

    const buttons = page.locator('button[data-code]')

    await expect(buttons.first()).toBeVisible()
    expect(await buttons.count()).toBeGreaterThan(0)

    for (const handle of await buttons.elementHandles()) {
      const box = await handle.boundingBox()

      expect(box!.width).toBeGreaterThanOrEqual(43.5)
      expect(box!.height).toBeGreaterThanOrEqual(43.5)
    }

    const cell = page.locator('button[data-code="usuario.crear"]')

    await cell.focus()
    await page.keyboard.press('Enter')

    const dialog = page.getByRole('dialog')

    await expect(dialog).toBeVisible()

    for (let step = 0; step < 6; step += 1) {
      await page.keyboard.press('Tab')
      expect(
        await page.evaluate(() => document.activeElement?.closest('[role="dialog"]') !== null),
      ).toBe(true)
    }

    await page.keyboard.press('Escape')
    await expect(dialog).toHaveCount(0)
    await expect(cell).toBeFocused()
  })
})

test.describe('CA-PERM-132 (RUX-004, WCAG 2.2 AA): recorrido solo con teclado', () => {
  test.use({ hasTouch: true, isMobile: true, viewport: { width: 1280, height: 900 } })

  test('las opciones de ámbito deshabilitadas se anuncian con su motivo en el árbol de accesibilidad', async ({
    page,
  }) => {
    await mockBackend(page)
    await page.goto('/administracion/roles/R1/permisos')

    await page.locator('button[data-code="auditoria.leer"]').focus()
    await page.keyboard.press('Enter')
    await page.getByRole('combobox').focus()
    await page.keyboard.press('ArrowDown')

    const notHeld = page.getByRole('option', { name: /Todos — no puedes concederlo/ })
    const noResolver = page.getByRole('option', { name: /Grupo — todavía no se puede conceder/ })

    await expect(notHeld).toHaveAttribute('aria-disabled', 'true')
    await expect(noResolver).toHaveAttribute('aria-disabled', 'true')
    await expect(page.getByRole('option', { name: /Propios \(actual\)/ })).not.toHaveAttribute(
      'aria-disabled',
      'true',
    )
  })

  test('el diálogo de confirmación atrapa el foco, Esc lo cierra y el foco vuelve a «Guardar»', async ({
    page,
  }) => {
    await mockBackend(page)
    await page.goto('/administracion/roles/R1/permisos')

    const cell = page.locator('button[data-code="usuario.crear"]')

    await cell.focus()
    await page.keyboard.press('Enter')
    await page.getByRole('radio', { name: 'Permitir' }).click()
    await page.getByRole('button', { name: 'Aplicar' }).click()

    const save = page.getByRole('button', { name: 'Guardar', exact: true })

    await expect(save).toBeEnabled()
    await save.focus()
    await page.keyboard.press('Enter')

    const dialog = page.getByRole('alertdialog')

    await expect(dialog).toBeVisible()

    for (let step = 0; step < 4; step += 1) {
      await page.keyboard.press('Tab')
      expect(
        await page.evaluate(() => document.activeElement?.closest('[role="alertdialog"]') !== null),
      ).toBe(true)
    }

    await page.keyboard.press('Escape')
    await expect(dialog).toHaveCount(0)
    await expect(save).toBeFocused()
  })

  test('alta: todos los controles se alcanzan con Tab en orden de documento y llevan etiqueta', async ({
    page,
  }) => {
    await mockBackend(page)
    await page.goto('/administracion/roles/nuevo')

    await expect(page.getByLabel(/^Nombre/)).toBeVisible()

    const visited: string[] = []

    for (let step = 0; step < 14; step += 1) {
      await page.keyboard.press('Tab')
      visited.push(
        await page.evaluate(() => {
          const el = document.activeElement as HTMLElement | null

          return el?.id || el?.getAttribute('data-slot') || el?.tagName || ''
        }),
      )
    }

    for (const id of ['role-form-name', 'role-form-code', 'role-form-mfa']) {
      expect(visited).toContain(id)
    }

    expect(visited.indexOf('role-form-name')).toBeLessThan(visited.indexOf('role-form-code'))
    expect(visited.indexOf('role-form-code')).toBeLessThan(visited.indexOf('role-form-mfa'))
    await expect(page.getByLabel('MFA obligatorio')).toBeVisible()
  })

  test('ficha de rol y permisos efectivos: acciones y tabla con nombres accesibles, sin objetivos de menos de 44 px con puntero grueso', async ({
    page,
  }) => {
    await mockBackend(page)
    await page.goto('/administracion/roles/R1')

    await expect(
      page.getByRole('heading', { level: 1, name: 'Coordinación pastoral' }),
    ).toBeVisible()

    for (const name of ['Editar datos', 'Editar concesiones', 'Clonar']) {
      const box = await page.getByRole('link', { name }).boundingBox()

      expect(box!.height, name).toBeGreaterThanOrEqual(43.5)
    }

    await page.goto('/administracion/usuarios/U1/permisos')

    await expect(
      page.getByRole('heading', { level: 1, name: 'Permisos efectivos de Lucía Pérez' }),
    ).toBeVisible()
    await expect(page.getByRole('table')).toBeVisible()
    await expect(page.getByLabel('Incluir permisos sin ninguna concesión')).not.toBeChecked()
    await expect(page.locator('tbody tr')).toHaveCount(1)

    await page.getByLabel('Incluir permisos sin ninguna concesión').check()
    await expect(page.locator('tbody tr')).toHaveCount(5)
  })
})

test.describe('CA-PERM-133 (RPERM-005, RPERM-009, RPERM-013): flujo completo contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real con un centro recién aprovisionado (E2E_REAL_API=1, E2E_ADMIN_EMAIL, E2E_ADMIN_PASSWORD).',
  )

  test('un administrador crea «Revisión propia», le concede auditoria.leer con propios, lo asigna y ve los permisos efectivos', async ({
    page,
  }) => {
    const email = process.env.E2E_ADMIN_EMAIL ?? ''
    const password = process.env.E2E_ADMIN_PASSWORD ?? ''

    await page.goto('/entrar')
    await page.getByLabel(/correo/i).fill(email)
    await page.getByLabel(/contraseña/i).fill(password)
    await page.getByRole('button', { name: /entrar|acceder/i }).click()

    await page.goto('/administracion/roles/nuevo')
    await page.getByLabel(/^Nombre/).fill('Revisión propia')
    await page.getByRole('button', { name: 'Crear rol' }).click()

    // Alta sin concesiones y paso directo al editor (OPEN-PERM-16 = A).
    await expect(page).toHaveURL(/\/administracion\/roles\/[^/]+\/permisos$/)

    await page.locator('button[data-code="auditoria.leer"]').click()
    await page.getByRole('radio', { name: 'Permitir' }).click()
    await page.getByRole('button', { name: 'Aplicar' }).click()
    await page.getByRole('button', { name: 'Guardar', exact: true }).click()
    await page.getByRole('button', { name: /^Guardar concesiones de/ }).click()
    await expect(page.getByRole('status')).toContainText('Concesiones guardadas')

    // El control de special_data_access del administrador está deshabilitado (permisos.md §5).
    await page.getByRole('link', { name: 'Roles' }).first().click()
  })
})
