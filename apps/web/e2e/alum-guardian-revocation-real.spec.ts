import { test, expect } from '@playwright/test'
import { RealSession } from './fixtures/realSession'
import {
  BASE_URL,
  centreA,
  closeAll,
  pageAs,
  browserState,
  tag,
  tutorGuardian,
  ulid,
} from './fixtures/alumReal'

/**
 * `docs/proceso/e2e-real-ci.md §9.4`, `CA-E2ECI-28` (E2E-CI-d2, fila F-03 de `e2e-real-ci-matriz.md §4.4`): el administrador revoca el
 * vínculo de un tutor desde la ficha de familia **en navegador real contra la API real**, la ficha lo muestra revocado, la petición
 * siguiente del tutor (HTTP real, el tutor no entra en el navegador: `OPEN-E2ECI-13`) sobre ese alumno responde `404`
 * (`RN-FAM-UNIT-36`) y, al levantar la revocación, vuelve a `200`. Datos sintéticos de `REQ-SEED-005`. El alumno y el vínculo son propios de
 * este spec; el tutor se reutiliza de `tutorGuardian` (solo puede ser tutor una vez).
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).
 */
test.describe('CA-E2ECI-28 (REQ-FAM-UNIT-004): revocar y levantar el vínculo desde la ficha de familia', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).',
  )

  test.use({ baseURL: BASE_URL })
  test.afterAll(closeAll)

  test('CA-E2ECI-28: el administrador revoca el vínculo en pantalla, el tutor recibe 404 y al levantarla vuelve a 200', async ({
    browser,
  }) => {
    test.setTimeout(180_000)
    const label = tag()
    const family = `Revocable${label}`
    const admin = await RealSession.login(centreA.slug, centreA.adminEmail, centreA.adminPassword)
    const tutor = await RealSession.login(centreA.slug, centreA.tutorEmail, centreA.staffPassword)

    try {
      const { guardian, unit } = await tutorGuardian(admin)
      const student = await admin.json(
        'POST',
        'students',
        201,
        {
          given_name: 'Alumno',
          family_name_1: family,
          birth_date: '2016-02-02',
          family_unit: unit,
        },
        { 'Idempotency-Key': ulid() },
      )
      await admin.json('POST', 'guardian-links', 201, {
        guardian,
        student_person: student.person.public_id,
        relationship: 'madre',
        is_legal_guardian: true,
      })
      await admin.json('PATCH', `family-units/${unit}`, 200, { principal_guardian: guardian })

      // Antes de revocar, el tutor ve al alumno.
      await tutor.json('GET', `students/${student.public_id}`, 200)

      const page = await pageAs(browser, await browserState(admin))

      await page.goto(`/familias/${unit}`)

      const links = page.getByRole('region', { name: 'Vínculos' })
      const row = links.getByRole('row').filter({ hasText: family })

      await expect(row.getByText('Activo')).toBeVisible()

      // Revocar: la acción pide confirmación y la ficha muestra el vínculo revocado.
      await row.getByRole('button', { name: /^Revocar el acceso de .* a Alumno / }).click()
      await page
        .getByRole('alertdialog')
        .getByRole('button', { name: /^Revocar el acceso de / })
        .click()
      await expect(row.getByText('Revocado', { exact: true })).toBeVisible()
      await expect(row.getByText(/Revocado por: Resolución judicial/)).toBeVisible()

      // El efecto es inmediato para el tutor: el alumno ya no existe para él.
      await tutor.json('GET', `students/${student.public_id}`, 404)

      // Levantar la revocación (con su confirmación) devuelve el acceso.
      await row.getByRole('button', { name: /^Levantar la revocación de / }).click()
      await page
        .getByRole('alertdialog')
        .getByRole('button', { name: /^Levantar la revocación de / })
        .click()
      await expect(row.getByText('Activo')).toBeVisible()
      await expect(row.getByText('Revocado', { exact: true })).toHaveCount(0)

      await tutor.json('GET', `students/${student.public_id}`, 200)
    } finally {
      await Promise.all([admin.dispose(), tutor.dispose()])
    }
  })
})
