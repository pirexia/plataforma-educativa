import { test, expect, type Page } from '@playwright/test'

/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §12.7`, `CA-FAM-UNIT-076` (E2E), `docs/adr/ADR-067` `§5.11` y `CA-067-09`: un
 * tutor con la aceptación de las condiciones de uso pendiente aterriza en el muro nada más cargar la sesión
 * (`session_walls` de `GET /me`), no puede navegar a otra ruta y, tras aceptar, llega a su inicio. Sin backend real: se
 * intercepta la API con `page.route`, mismo patrón que `e2e/auth-sessions.spec.ts`; la prueba contra la API real es
 * `npm run test:e2e:real` (que recrea el centro de demostración y requiere permiso expreso).
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

const TERMS = {
  public_id: '01JB0000000000000000000040',
  kind: 'condiciones_uso',
  version_number: 1,
  title: 'Condiciones de uso del centro',
  body: 'El centro trata los datos de su familia conforme a su política de privacidad.',
  status: 'publicada',
  requires_reacceptance: false,
  published_at: '2026-10-28T08:00:00Z',
}

function me(sessionWalls: string[]) {
  return {
    public_id: '01J-ME',
    email: 'tutor@example.com',
    status: 'activo',
    person: {
      public_id: '01J-PERSON',
      given_name: 'Tutora',
      family_name_1: 'Ejemplo',
      family_name_2: null,
      contact_email: null,
      contact_phone: null,
      document_type: null,
      document_number: null,
      birth_date: null,
      locale: 'es-ES',
    },
    roles: [],
    permissions: [],
    session_walls: sessionWalls,
    email_verified_at: '2026-01-01T00:00:00Z',
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    deleted_at: null,
  }
}

async function mockBackend(page: Page): Promise<{ accepted: () => boolean }> {
  let accepted = false
  const json = (body: unknown, status = 200) => ({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  })

  await page.route('**/tenant/branding', (route) => route.fulfill(json(BRANDING)))
  await page.route('**/auth/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }))
  await page.route('**/me', (route) =>
    route.fulfill(json(me(accepted ? [] : ['terms-acceptance']))),
  )
  await page.route('**/me/terms', (route) =>
    route.fulfill(
      json(accepted ? { required: false, version: null } : { required: true, version: TERMS }),
    ),
  )
  await page.route('**/me/terms-acceptances', (route) => {
    accepted = true

    return route.fulfill(
      json(
        {
          public_id: '01JC0000000000000000000001',
          user: '01J-ME',
          terms_version: TERMS.public_id,
          version_number: 1,
          shown_locale: 'es-ES',
          accepted_at: '2026-10-28T09:00:00Z',
        },
        201,
      ),
    )
  })

  return { accepted: () => accepted }
}

test('CA-FAM-UNIT-076: el tutor aterriza en el muro, no puede navegar a otra ruta y tras aceptar llega a su inicio', async ({
  page,
}) => {
  const backend = await mockBackend(page)

  // Aterriza en el muro aunque pida el inicio.
  await page.goto('/')
  await expect(page).toHaveURL(/\/condiciones-de-uso$/)
  await expect(page.getByRole('heading', { level: 1, name: 'Condiciones de uso' })).toBeVisible()
  await expect(page.getByText(TERMS.title)).toBeVisible()
  await expect(page.getByRole('region', { name: 'Texto de las condiciones de uso' })).toContainText(
    TERMS.body,
  )

  // No puede navegar a otra ruta: el guard lo devuelve al muro.
  await page.goto('/cuenta/sesiones')
  await expect(page).toHaveURL(/\/condiciones-de-uso$/)
  expect(backend.accepted()).toBe(false)

  // El muro no tiene navegación (BareLayout): solo cerrar sesión y aceptar.
  await expect(page.getByRole('navigation')).toHaveCount(0)

  // Tras aceptar, llega a su inicio.
  await page.getByRole('button', { name: 'Acepto las condiciones de uso' }).click()
  await expect(page).toHaveURL(/\/$/)
  expect(backend.accepted()).toBe(true)
})
