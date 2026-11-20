import { test, expect } from '@playwright/test'
import { RealSession } from './fixtures/realSession'
import {
  BASE_URL,
  centreA,
  closeAll,
  pageAs,
  signIn,
  ulid,
  tag,
  tutorGuardian,
} from './fixtures/alumReal'

/**
 * `docs/proceso/e2e-real-ci.md §9.4`, `CA-E2ECI-31` (E2E-CI-d2, filas F-06 y T-07 de `e2e-real-ci-matriz.md §4.4`; `RN-FAM-UNIT-61`,
 * `ADR-067`, `OPEN-E2ECI-25` = B): publicadas las condiciones de uso, el tutor `e2e-tutor` (con un vínculo activo) antes de aceptar
 * recibe `403 terms-acceptance-required` en su API (HTTP real) y, al iniciar sesión en el **navegador** por la pantalla de acceso,
 * llega al muro `/condiciones-de-uso`, acepta y continúa. Es el único test de navegador con el tutor (excepción nominal a
 * `OPEN-E2ECI-13`, decisión del usuario del 2026-11-20).
 *
 * **ORDEN**: publicar `condiciones_uso` deja al tutor bajo el muro para el resto de la ejecución (el guion no lo recrea). Playwright
 * ordena los ficheros por nombre dentro de una invocación, y los demás specs que usan al tutor (`alum-enrollments-real`,
 * `alum-tutor-http-real` en `alum-http`; `alum-guardian-revocation-real`, `alum-image-consent-real` en `alum-browser`) ordenan antes que
 * este. Si otro spec que use a `e2e-tutor` se añade, su nombre debe ordenar antes de `alum-terms-wall-real`.
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).
 */
test.describe('CA-E2ECI-31 (RN-FAM-UNIT-61, REQ-FAM-UNIT-003): el muro de condiciones de uso del tutor', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).',
  )

  test.use({ baseURL: BASE_URL })
  test.afterAll(closeAll)

  test('CA-E2ECI-31 F-06 T-07: con las condiciones publicadas el tutor recibe 403 terms-acceptance-required, llega al muro, acepta y continúa', async ({
    browser,
  }) => {
    test.setTimeout(240_000)
    const label = tag()
    const admin = await RealSession.login(centreA.slug, centreA.adminEmail, centreA.adminPassword)
    let tutor: RealSession | null = null

    try {
      // El tutor tiene que tener un vínculo activo: es lo único que lo somete al muro (RN-FAM-UNIT-61).
      const { guardian, unit } = await tutorGuardian(admin)
      const student = await admin.json(
        'POST',
        'students',
        201,
        {
          given_name: 'Alumno',
          family_name_1: `Muro${label}`,
          birth_date: '2016-05-05',
          family_unit: unit,
        },
        { 'Idempotency-Key': ulid() },
      )

      await admin.json('POST', 'guardian-links', 201, {
        guardian,
        student_person: student.person.public_id,
        relationship: 'padre',
        is_legal_guardian: true,
      })

      // Sin condiciones publicadas, el tutor trabaja con normalidad (sirve de contraste).
      tutor = await RealSession.login(centreA.slug, centreA.tutorEmail, centreA.staffPassword)
      await tutor.json('GET', `students/${student.public_id}`, 200)
      expect((await tutor.json('GET', 'me/terms', 200)).required).toBe(false)

      // El administrador publica las condiciones de uso.
      const title = `Condiciones de uso ficticias ${label}`
      const body = `Texto ficticio de las condiciones de uso ${label}.`
      const terms = await admin.json('POST', 'terms-versions', 201, {
        kind: 'condiciones_uso',
        title_translations: { 'es-ES': title },
        body_translations: { 'es-ES': body },
      })

      await admin.json('POST', `terms-versions/${terms.public_id}/publish`, 200)

      // T-07: antes de aceptar, su API responde 403 terms-acceptance-required fuera de la lista exenta; la exenta sigue sirviendo.
      const blocked = await tutor.send('GET', `students/${student.public_id}`)

      expect(blocked.status()).toBe(403)
      expect(JSON.stringify(await blocked.json())).toContain('terms-acceptance-required')

      const pending = await tutor.json('GET', 'me/terms', 200)

      expect(pending.required).toBe(true)
      expect(pending.version.public_id).toBe(terms.public_id)

      // F-06: inicia sesión en el navegador, llega al muro y no puede salir a otra ruta sin aceptar.
      const page = await pageAs(browser, { cookies: [], origins: [] })

      await signIn(page, centreA.tutorEmail, centreA.staffPassword)
      await expect(page).toHaveURL(/\/condiciones-de-uso$/)
      await expect(
        page.getByRole('heading', { level: 1, name: 'Condiciones de uso' }),
      ).toBeVisible()
      await expect(page.getByRole('heading', { level: 2, name: title })).toBeVisible()
      await expect(page.getByTestId('terms-body')).toContainText(body)
      await expect(page.getByRole('navigation')).toHaveCount(0)

      await page.goto('/')
      await expect(page).toHaveURL(/\/condiciones-de-uso$/)

      // Acepta y continúa: sale del muro y su API vuelve a responder.
      await page.getByRole('button', { name: 'Acepto las condiciones de uso' }).click()
      await expect(page).not.toHaveURL(/\/condiciones-de-uso/)
      await expect(page.getByRole('heading', { level: 1, name: 'Condiciones de uso' })).toHaveCount(
        0,
      )

      await tutor.json('GET', `students/${student.public_id}`, 200)
      expect((await tutor.json('GET', 'me/terms', 200)).required).toBe(false)
    } finally {
      await Promise.all([admin.dispose(), tutor?.dispose()])
    }
  })
})
