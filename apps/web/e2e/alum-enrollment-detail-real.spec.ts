import { test, expect } from '@playwright/test'
import { RealSession } from './fixtures/realSession'
import {
  BASE_URL,
  browserState,
  centreA,
  closeAll,
  createStructure,
  createStudent,
  enroll,
  ensureActiveYear,
  pageAs,
  tag,
  type Structure,
  type YearInfo,
} from './fixtures/alumReal'

/**
 * `docs/proceso/e2e-real-ci.md §9.4`, `CA-E2ECI-31c` (E2E-CI-d2, fila L-07 de `e2e-real-ci-matriz.md §4.4`; `REQ-ALUM-003`,
 * `RN-ALUM-05`, `RN-ALUM-22`): secretaría da de baja (`withdrawal`) una matrícula activa y anula otra desde la ficha del alumno
 * **por pantalla**, contra la API real; la ficha refleja cada estado y una lectura HTTP real de `GET /enrollments/{id}` lo confirma;
 * un docente con `matricula.leer` de ámbito `grupo` ve las matrículas de su alumno sin ninguna de las acciones y la API le responde
 * `403`. El HTTP de baja y anulación ya lo cubre `alum-enrollments-real.spec.ts` (`CA-ALUM-024`, `CA-ALUM-031`): aquí solo la
 * pantalla. Datos sintéticos de `REQ-SEED-005`.
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).
 */
test.describe('CA-E2ECI-31c (REQ-ALUM-003): baja y anulación de matrícula desde la ficha del alumno y ámbito del docente', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).',
  )

  test.use({ baseURL: BASE_URL })
  test.describe.configure({ mode: 'serial' })

  const label = tag()
  const family = `Matricula${label}`
  const groupFamily = `Docente${label}`
  let admin: RealSession
  let secretaria: RealSession
  let docente: RealSession
  let year: YearInfo
  let structure: Structure
  let studentId = ''
  let firstEnrollment = ''
  let groupEnrollment = ''
  let groupStudentId = ''

  test.afterAll(async () => {
    await closeAll()
    await Promise.all([admin, secretaria, docente].map((session) => session?.dispose()))
  })

  test.beforeAll(async () => {
    // Los inicios de sesión esperan lo que pida la API si responde 429 (`RealSession.login`).
    test.setTimeout(240_000)
    admin = await RealSession.login(centreA.slug, centreA.adminEmail, centreA.adminPassword)
    secretaria = await RealSession.login(
      centreA.slug,
      centreA.secretariaEmail,
      centreA.staffPassword,
    )
    docente = await RealSession.login(centreA.slug, centreA.docenteEmail, centreA.staffPassword)

    year = await ensureActiveYear(admin)
    structure = await createStructure(admin, year.id, label)
    studentId = (await createStudent(admin, 'Alumno', family)).id
    firstEnrollment = await enroll(admin, studentId, year, structure, structure.groupFrom)

    groupStudentId = (await createStudent(admin, 'Alumno', groupFamily)).id
    groupEnrollment = await enroll(admin, groupStudentId, year, structure, structure.groupFrom)
    await admin.json('POST', 'teaching-assignments', 201, {
      teacher: centreA.docenteUser,
      group: structure.groupFrom,
      subject: structure.subject,
    })
  })

  test('CA-E2ECI-31c L-07: secretaría da de baja la matrícula activa desde la ficha y la lectura HTTP la muestra en baja', async ({
    browser,
  }) => {
    const page = await pageAs(browser, await browserState(secretaria))

    await page.goto(`/alumnado/${studentId}`)

    const enrollments = page.getByRole('region', { name: 'Matrículas' })
    const row = enrollments.getByRole('row').filter({ hasText: structure.courseName })

    await expect(row.getByText('Activa')).toBeVisible()
    await row.getByRole('button', { name: /^Dar de baja la matrícula/ }).click()

    const form = page.getByRole('dialog', { name: 'Dar de baja la matrícula' })

    await form.getByLabel('Fecha de baja').fill(year.startsOn)
    await form.getByRole('combobox', { name: 'Motivo de la baja' }).click()
    await page.getByRole('option', { name: 'Traslado' }).click()
    await form.getByRole('button', { name: 'Dar de baja', exact: true }).click()

    await expect(page.getByText('La matrícula se ha dado de baja.')).toBeVisible()
    await expect(row.getByText('Baja', { exact: true })).toBeVisible()
    await expect(row.getByRole('button', { name: /^Dar de baja la matrícula/ })).toHaveCount(0)

    const stored = await secretaria.json('GET', `enrollments/${firstEnrollment}`, 200)

    expect(stored.status).toBe('baja')
    expect(stored.withdrawn_on).toBe(year.startsOn)
    expect(stored.withdrawal_reason).toBe('traslado')
  })

  test('CA-E2ECI-31c L-07: secretaría anula otra matrícula desde la ficha, la ficha la quita y GET /enrollments/{id} responde 404', async ({
    browser,
  }) => {
    // Con la anterior en baja, el alumno admite una matrícula nueva en el mismo curso.
    const second = await enroll(admin, studentId, year, structure, structure.groupTo)
    const page = await pageAs(browser, await browserState(secretaria))

    await page.goto(`/alumnado/${studentId}`)

    const enrollments = page.getByRole('region', { name: 'Matrículas' })

    await expect(enrollments.getByRole('row').filter({ hasText: /Activa/ })).toBeVisible()
    await expect(enrollments.getByRole('row').filter({ hasText: /Baja/ })).toBeVisible()

    await enrollments
      .getByRole('row')
      .filter({ hasText: /Activa/ })
      .getByRole('button', { name: /^Anular matrícula/ })
      .click()
    await page
      .getByRole('alertdialog')
      .getByRole('button', { name: /^Anular matrícula de / })
      .click()

    await expect(page.getByText('La matrícula se ha anulado.')).toBeVisible()
    await expect(enrollments.getByRole('row').filter({ hasText: /Activa/ })).toHaveCount(0)
    // La de baja se conserva entera.
    await expect(enrollments.getByRole('row').filter({ hasText: /Baja/ })).toBeVisible()

    await secretaria.json('GET', `enrollments/${second}`, 404)
    expect((await secretaria.json('GET', `enrollments/${firstEnrollment}`, 200)).status).toBe(
      'baja',
    )
  })

  test('CA-E2ECI-31c L-07: un docente con ámbito grupo ve la matrícula de su alumno sin acciones y la API responde 403', async ({
    browser,
  }) => {
    const page = await pageAs(browser, await browserState(docente))

    await page.goto(`/alumnado/${groupStudentId}`)

    const enrollments = page.getByRole('region', { name: 'Matrículas' })

    await expect(
      enrollments.getByRole('row').filter({ hasText: structure.courseName }),
    ).toBeVisible()
    await expect(page.getByRole('button', { name: /^Dar de baja la matrícula/ })).toHaveCount(0)
    await expect(page.getByRole('button', { name: /^Anular matrícula/ })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Matricular', exact: true })).toHaveCount(0)

    // La interfaz oculta; la seguridad es del servidor (INV-002): baja y anulación son 403 y la matrícula sigue activa.
    await docente.json('GET', `enrollments/${groupEnrollment}`, 200)
    await docente.json('POST', `enrollments/${groupEnrollment}/withdrawal`, 403, {
      withdrawn_on: year.startsOn,
      withdrawal_reason: 'traslado',
    })
    await docente.json('DELETE', `enrollments/${groupEnrollment}`, 403)

    expect((await admin.json('GET', `enrollments/${groupEnrollment}`, 200)).status).toBe('activa')
  })
})
