import { test, expect } from '@playwright/test'
import { RealSession } from './fixtures/realSession'
import {
  BASE_URL,
  centreA,
  createStructure,
  ensureActiveYear,
  closeAll,
  loginState,
  pageAs,
  tag,
  type Structure,
  type YearInfo,
} from './fixtures/alumReal'

/**
 * `docs/modulos/REQ-ALUM/funcional.md §11.8`, `CA-ALUM-072` (E2E real) y `CA-ALUM-071` (accesibilidad en navegador real):
 * las pantallas de alumnado **en navegador real contra la API real** (cookie de sesión y CSRF reales; datos sintéticos de
 * `REQ-SEED-005`: `@example.com`, nombres explícitamente ficticios). Secretaría da de alta un alumno en una unidad familiar,
 * lo matricula, ve la lista de clase y exporta; un docente con asignación en ese grupo ve al alumno y otro sin asignación no.
 *
 * Qué NO se prueba aquí: la descarga del CSV desde S3/MinIO (la exportación se acepta con 202 y se comprueba que no falla, pero
 * la descarga queda «no probada», igual que en `alum-enrollments-real.spec.ts`) ni la fotografía (aplazada a `S-1b`, `ADR-076`).
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh) prepara el centro `demo` con
 * `alum` y `fam_unit`, el personal de prueba (secretaría, docentes) y define las variables.
 */
test.describe('CA-ALUM-072 CA-ALUM-071 (REQ-ALUM-003): pantallas de alumnado en navegador real contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).',
  )

  test.use({ baseURL: BASE_URL })
  test.describe.configure({ mode: 'serial' })

  const label = tag()
  const family = `Ficticio${label}`
  const given = 'Alumno'
  let year: YearInfo
  let structure: Structure
  let unitCode = ''

  const states = {} as Record<
    'secretaria' | 'docente' | 'docente2',
    Awaited<ReturnType<typeof loginState>>
  >

  test.afterAll(closeAll)

  test.beforeAll(async () => {
    // Los inicios de sesión esperan lo que pida la API si responde 429 (`RealSession.login`).
    test.setTimeout(180_000)
    const admin = await RealSession.login(centreA.slug, centreA.adminEmail, centreA.adminPassword)

    year = await ensureActiveYear(admin)
    structure = await createStructure(admin, year.id, label)

    const unit = await admin.json('POST', 'family-units', 201, {})

    unitCode = unit.code as string

    // Asignación docente: solo Daniel (`docente`) en el grupo de origen; Marta (`docente`) no tiene ninguna.
    await admin.json('POST', 'teaching-assignments', 201, {
      teacher: centreA.docenteUser,
      group: structure.groupFrom,
      subject: structure.subject,
    })
    await admin.dispose()

    // Un inicio de sesión por actor: la API limita los intentos de acceso (429).
    states.secretaria = await loginState(centreA.secretariaEmail, centreA.staffPassword)
    states.docente = await loginState(centreA.docenteEmail, centreA.staffPassword)
    states.docente2 = await loginState(centreA.docente2Email, centreA.staffPassword)
  })

  test('CA-ALUM-072: secretaría da de alta un alumno en una unidad familiar y lo matricula en el grupo', async ({
    browser,
  }) => {
    const page = await pageAs(browser, states.secretaria)
    await page.goto(`/alumnado?academic_year=${year.id}`)

    await expect(page.getByRole('heading', { level: 1, name: 'Alumnado' })).toBeVisible()
    await page.getByRole('button', { name: 'Nuevo alumno' }).click()

    const form = page.getByRole('dialog', { name: 'Nuevo alumno' })

    await form.getByLabel('Nombre de pila').fill(given)
    await form.getByLabel('Primer apellido').fill(family)
    await form.getByLabel('Fecha de nacimiento').fill('2015-04-12')
    await form.getByLabel('Buscar por código de unidad').fill(unitCode)
    await form.getByRole('radio', { name: unitCode }).check()
    await form.getByRole('button', { name: 'Dar de alta' }).click()

    // Tras el alta se abre la ficha del alumno recién creado.
    await expect(page).toHaveURL(/\/alumnado\/[0-9A-Z]{26}/)
    await expect(page.getByRole('heading', { level: 2, name: 'Matrículas' })).toBeVisible()
    await expect(page.getByText(family).first()).toBeVisible()

    await page.getByRole('button', { name: 'Matricular', exact: true }).click()

    const enrollment = page.getByRole('dialog', { name: 'Matricular al alumno' })

    await enrollment.getByRole('combobox', { name: 'Curso' }).click()
    await page.getByRole('option', { name: structure.courseName }).click()
    await enrollment.getByRole('combobox', { name: 'Grupo' }).click()
    await page.getByRole('option', { name: structure.groupFromName }).click()
    await enrollment.getByLabel('Fecha de matrícula').fill(year.startsOn)
    await enrollment.getByRole('button', { name: 'Matricular', exact: true }).click()

    await expect(page.getByText('La matrícula se ha creado.')).toBeVisible()
    await expect(
      page.getByRole('region', { name: 'Matrículas' }).getByText(structure.courseName).first(),
    ).toBeVisible()
  })

  test('CA-ALUM-072: secretaría ve la lista de clase con el alumno y exporta (202; descarga S3 no probada)', async ({
    browser,
  }) => {
    const page = await pageAs(browser, states.secretaria)
    await page.goto(`/alumnado/listas?academic_year=${year.id}`)

    await expect(page.getByRole('heading', { level: 1, name: 'Listas de clase' })).toBeVisible()
    await expect(page.getByRole('link', { name: new RegExp(family) })).toBeVisible()

    const [exportResponse] = await Promise.all([
      page.waitForResponse(
        (response) =>
          response.url().includes('/enrollments/exports') && response.request().method() === 'POST',
      ),
      page.getByRole('button', { name: 'Exportar' }).click(),
    ])

    expect(exportResponse.status(), await exportResponse.text()).toBe(202)

    // La exportación se genera en cola y se descarga de S3: aquí solo se comprueba que no falla de entrada.
    await expect(page.getByText('Preparando exportación…')).toBeVisible()
    await expect(page.getByRole('alert')).toHaveCount(0)
    test.info().annotations.push({
      type: 'no-probado',
      description:
        'Exportación aceptada (202). La descarga del CSV desde S3/MinIO NO se ha comprobado en este spec.',
    })
  })

  test('CA-ALUM-072: un docente con asignación en el grupo ve al alumno', async ({ browser }) => {
    const page = await pageAs(browser, states.docente)
    await page.goto(`/alumnado?academic_year=${year.id}`)

    await expect(page.getByRole('link', { name: new RegExp(family) })).toBeVisible()
  })

  test('CA-ALUM-072: un docente sin asignación en el grupo no ve al alumno', async ({
    browser,
  }) => {
    const page = await pageAs(browser, states.docente2)
    await page.goto(`/alumnado?academic_year=${year.id}`)

    await expect(page.getByRole('heading', { level: 1, name: 'Alumnado' })).toBeVisible()
    await expect(page.getByText('No hay alumnos que mostrar')).toBeVisible()
    await expect(page.getByRole('link', { name: new RegExp(family) })).toHaveCount(0)
  })

  test('CA-ALUM-071: el diálogo de alta mueve el foco al abrir y lo devuelve al botón al cerrar', async ({
    browser,
  }) => {
    const page = await pageAs(browser, states.secretaria)
    await page.goto(`/alumnado?academic_year=${year.id}`)

    const trigger = page.getByRole('button', { name: 'Nuevo alumno' })

    await trigger.click()

    const dialog = page.getByRole('dialog', { name: 'Nuevo alumno' })

    await expect(dialog).toBeVisible()
    await expect(dialog.locator(':focus')).toHaveCount(1)

    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(trigger).toBeFocused()
  })

  for (const path of ['/alumnado', '/alumnado/listas', '/alumnado/cambios-de-grupo']) {
    test(`CA-ALUM-071: ${path} no desborda horizontalmente a 320 px`, async ({ browser }) => {
      const page = await pageAs(browser, states.secretaria)
      await page.setViewportSize({ width: 320, height: 800 })
      await page.goto(`${path}?academic_year=${year.id}`)

      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      // A 320 px la tabla pasa a tarjetas (un `h2` por fila) y sin datos sale el estado vacío («No hay …»): esperar a lo que haya.
      await expect(
        page
          .getByRole('heading', { level: 2 })
          .or(page.getByText(/^No hay /))
          .first(),
      ).toBeVisible()

      const overflow = await page.evaluate(() => ({
        scroll: document.documentElement.scrollWidth,
        client: document.documentElement.clientWidth,
      }))

      expect(overflow.scroll).toBeLessThanOrEqual(overflow.client)
    })
  }
})
