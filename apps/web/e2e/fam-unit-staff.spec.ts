import { test, expect, type Page, type Route } from '@playwright/test'

/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §12.8`, `CA-FAM-UNIT-084` (E2E, `1.14-d`): secretaría crea una unidad
 * familiar, añade un tutor, vincula un alumno de prueba y revoca el vínculo; la ficha refleja cada paso y el estado
 * revocado se distingue sin depender del color (texto «Revocado», icono propio y el motivo y la fecha en texto,
 * WCAG 1.4.1 / 2.2 AA). Sin backend real: se intercepta la API con `page.route` y un almacén en memoria, mismo patrón
 * que `e2e/fam-unit-terms-wall.spec.ts` y `e2e/auth-sessions.spec.ts`. No hay variante contra la API real: haría falta
 * ampliar el centro de pruebas de `npm run test:e2e:real` (que recrea el centro de demostración y exige permiso
 * expreso) con una persona de alumno de prueba (`funcional.md §21`, `D-46`).
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
  email: 'secretaria@example.com',
  status: 'activo',
  person: {
    public_id: '01J-PERSON',
    given_name: 'Sara',
    family_name_1: 'Secretaría',
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
    'familia.leer',
    'familia.crear',
    'familia.actualizar',
    'familia.eliminar',
    'vinculo_tutor.leer',
    'vinculo_tutor.crear',
    'revocacion_tutor.leer',
    'revocacion_tutor.crear',
    'revocacion_tutor.eliminar',
  ],
  session_walls: [],
  email_verified_at: '2026-01-01T00:00:00Z',
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
  deleted_at: null,
}

const AUDIT = { created_at: '2026-10-28T09:00:00Z', updated_at: '2026-10-28T09:00:00Z' }
const PAGE_META = (total: number) => ({ current_page: 1, per_page: 25, total, last_page: 1 })

interface Store {
  units: { public_id: string; code: string }[]
  guardians: { public_id: string; display_name: string }[]
  students: { public_id: string; student_person: string; display_name: string }[]
  links: Record<string, unknown>[]
}

function json(body: unknown, status = 200) {
  return { status, contentType: 'application/json', body: JSON.stringify(body) }
}

function unitBody(store: Store) {
  const unit = store.units[0]!

  return {
    public_id: unit.public_id,
    code: unit.code,
    principal_guardian: store.guardians[0]?.public_id ?? null,
    guardians_count: store.guardians.length,
    students_count: store.students.length,
    ...AUDIT,
  }
}

async function mockBackend(page: Page): Promise<Store> {
  const store: Store = { units: [], guardians: [], students: [], links: [] }

  await page.route('**/tenant/branding', (route) => route.fulfill(json(BRANDING)))
  await page.route('**/auth/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }))
  await page.route('**/me', (route) => route.fulfill(json(ME)))

  await page.route(
    /\/(family-units|guardians|guardian-links)(\/[^?]*)?(\?.*)?$/,
    async (route: Route) => {
      const request = route.request()

      // Solo las peticiones de datos: los módulos de Vite (`/src/.../family.ts`) siguen su camino.
      if (!['fetch', 'xhr'].includes(request.resourceType())) {
        return route.fallback()
      }

      const url = new URL(request.url())
      const path = url.pathname.replace(/^.*\/api\/v1/, '')
      const method = request.method()
      const body = request.postDataJSON() as Record<string, unknown> | null

      if (path === '/family-units' && method === 'GET') {
        return route.fulfill(
          json({
            data: store.units.map(() => unitBody(store)),
            meta: PAGE_META(store.units.length),
          }),
        )
      }

      if (path === '/family-units' && method === 'POST') {
        store.units.push({ public_id: 'UNIT1', code: 'UF-000001' })

        return route.fulfill(json(unitBody(store), 201))
      }

      if (path === '/family-units/UNIT1' && method === 'GET') {
        return route.fulfill(json(unitBody(store)))
      }

      if (path === '/guardians' && method === 'GET') {
        return route.fulfill(
          json({
            data: store.guardians.map((guardian, index) => ({
              ...guardian,
              family_unit: 'UNIT1',
              user: `USR${index + 1}`,
              is_principal: index === 0,
              ...AUDIT,
            })),
            meta: PAGE_META(store.guardians.length),
          }),
        )
      }

      if (path === '/guardians' && method === 'POST') {
        store.guardians.push({ public_id: 'GUARD1', display_name: 'Marta Ejemplo Ruiz' })

        return route.fulfill(json({ public_id: 'GUARD1', user: body?.user }, 201))
      }

      if (path === '/family-units/UNIT1/students' && method === 'GET') {
        return route.fulfill(
          json({
            data: store.students.map((student) => ({ ...student, family_unit: 'UNIT1', ...AUDIT })),
          }),
        )
      }

      if (path === '/family-units/UNIT1/students' && method === 'POST') {
        store.students.push({
          public_id: 'MEMB1',
          student_person: String(body?.student_person),
          display_name: 'Lucas Ejemplo Ruiz',
        })

        return route.fulfill(json({ public_id: 'MEMB1' }, 201))
      }

      if (path === '/guardian-links' && method === 'GET') {
        return route.fulfill(json({ data: store.links, meta: PAGE_META(store.links.length) }))
      }

      if (path === '/guardian-links' && method === 'POST') {
        store.links.push({
          public_id: 'LINK1',
          guardian: body?.guardian,
          student_person: body?.student_person,
          relationship: body?.relationship,
          is_legal_guardian: body?.is_legal_guardian,
          legal_guardian_verified_at: null,
          access_level: body?.access_level,
          restricted_categories: [],
          is_emergency_contact: body?.is_emergency_contact,
          status: 'activo',
          revocation: null,
          ...AUDIT,
        })

        return route.fulfill(json(store.links[0], 201))
      }

      if (path === '/guardian-links/LINK1/revocation' && method === 'POST') {
        store.links[0] = {
          ...store.links[0],
          status: 'revocado',
          revocation: {
            reason: body?.reason,
            revoked_at: '2026-10-28T10:30:00Z',
            revoked_by: '01J-ME',
          },
        }

        return route.fulfill(json(store.links[0]))
      }

      return route.fulfill(json({ status: 404, type: 'urn:pge:error:not-found' }, 404))
    },
  )

  return store
}

test('CA-FAM-UNIT-084: secretaría crea una unidad, añade un tutor, vincula un alumno y revoca; la ficha lo refleja', async ({
  page,
}) => {
  await mockBackend(page)

  // La entrada «Familias» está en el menú porque se puede montar /familias.
  await page.goto('/familias')
  await expect(page.getByRole('heading', { level: 1, name: 'Familias' })).toBeVisible()
  await expect(page.getByRole('navigation').getByRole('link', { name: 'Familias' })).toBeVisible()
  await expect(page.getByText('Todavía no hay unidades familiares')).toBeVisible()

  // 1. Crea una unidad (código generado por el sistema) y llega a su ficha.
  await page.getByRole('button', { name: 'Nueva unidad' }).first().click()
  await page.getByRole('dialog').getByRole('button', { name: 'Crear' }).click()
  await expect(page).toHaveURL(/\/familias\/UNIT1$/)
  await expect(
    page.getByRole('heading', { level: 1, name: 'Unidad familiar UF-000001' }),
  ).toBeVisible()
  await expect(page.getByText('La unidad no tiene tutores.')).toBeVisible()

  // 2. Añade un tutor (sin `usuario.leer`: por el identificador del usuario).
  await page.getByRole('button', { name: 'Añadir tutor' }).click()
  await page.getByLabel('Identificador del usuario').fill('USR1')
  await page.getByRole('dialog').getByRole('button', { name: 'Añadir tutor' }).click()
  await expect(
    page.getByRole('status').filter({ hasText: 'Tutor añadido a la unidad.' }),
  ).toBeVisible()
  await expect(page.getByRole('cell', { name: 'Marta Ejemplo Ruiz' })).toBeVisible()

  // 3. Añade un alumno de prueba a la unidad.
  await page.getByRole('button', { name: 'Añadir alumno' }).click()
  await page.getByLabel('Identificador de la persona del alumno').fill('PERSON1')
  await page.getByRole('dialog').getByRole('button', { name: 'Añadir alumno' }).click()
  await expect(page.getByRole('cell', { name: 'Lucas Ejemplo Ruiz' })).toBeVisible()

  // 4. Vincula al tutor con el alumno.
  await page.getByRole('button', { name: 'Nuevo vínculo' }).click()
  const linkDialog = page.getByRole('dialog')

  await linkDialog.getByLabel('Tutor/a').click()
  await page.getByRole('option', { name: 'Marta Ejemplo Ruiz' }).click()
  await linkDialog.getByLabel('Alumno', { exact: true }).click()
  await page.getByRole('option', { name: 'Lucas Ejemplo Ruiz' }).click()
  await linkDialog.getByLabel('Relación').click()
  await page.getByRole('option', { name: 'Madre' }).click()
  await linkDialog.getByRole('button', { name: 'Crear vínculo' }).click()

  const links = page
    .getByRole('region', { name: 'Vínculos' })
    .or(page.locator('section', { has: page.getByRole('heading', { name: 'Vínculos' }) }))
  const activeRow = links.getByRole('row', { name: /Marta Ejemplo Ruiz/ })

  await expect(activeRow).toContainText('Activo')
  await expect(activeRow).not.toContainText('Revocado')

  // 5. Revoca el vínculo (con confirmación) y la ficha muestra el estado revocado en texto.
  await page
    .getByRole('button', { name: 'Revocar el acceso de Marta Ejemplo Ruiz a Lucas Ejemplo Ruiz' })
    .click()
  await page
    .getByRole('alertdialog')
    .getByRole('button', { name: 'Revocar el acceso de Marta Ejemplo Ruiz a Lucas Ejemplo Ruiz' })
    .click()

  const revokedRow = links.getByRole('row', { name: /Marta Ejemplo Ruiz/ })

  // WCAG 1.4.1: el estado se distingue por el texto «Revocado», un icono propio y el motivo y la fecha en texto.
  await expect(revokedRow).toContainText('Revocado')
  await expect(revokedRow).toContainText('Resolución judicial')
  await expect(
    revokedRow.locator('[data-slot="fam-unit-status"] svg[aria-hidden="true"]'),
  ).toBeVisible()
  await expect(revokedRow.getByRole('button', { name: /Levantar la revocación/ })).toBeVisible()
})
