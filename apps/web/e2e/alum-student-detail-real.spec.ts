import { test, expect, request as playwrightRequest } from '@playwright/test'
import { RealSession } from './fixtures/realSession'
import { pngWidth, syntheticPng } from './fixtures/syntheticPng'
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
 * `docs/proceso/e2e-real-ci.md §9.4`, `CA-E2ECI-30` (E2E-CI-d2, filas L-03 y L-04 de `e2e-real-ci-matriz.md §4.4`; `REQ-ALUM-001`,
 * `REQ-ALUM-003`): secretaría edita, da de baja, restaura y cambia la fotografía de un alumno **por pantalla** (ficha
 * `/alumnado/:publicId` y listado con «Incluir bajas»), en navegador real contra la API real, y cada cambio se comprueba en la ficha
 * y en una lectura HTTP real; un docente con `alumno.leer` de ámbito `grupo` ve la ficha de un alumno de su grupo sin ninguna de esas
 * acciones y la API le responde `403`. El HTTP de editar, dar de baja y restaurar ya lo cubre `alum-students-real.spec.ts` y el de la
 * fotografía `alum-photo-real.spec.ts`: aquí solo la pantalla. Datos sintéticos de `REQ-SEED-005`.
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh; levanta Garage).
 */
test.describe('CA-E2ECI-30 (REQ-ALUM-001, REQ-ALUM-003): ficha del alumno en pantalla (editar, baja, restaurar, fotografía) y ámbito del docente', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real, Garage y un centro de pruebas: ejecútalo con `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).',
  )

  test.use({ baseURL: BASE_URL })
  test.describe.configure({ mode: 'serial' })

  const label = tag()
  const family = `Ficha${label}`
  const groupFamily = `Grupo${label}`
  let admin: RealSession
  let secretaria: RealSession
  let docente: RealSession
  let year: YearInfo
  let structure: Structure
  let studentId = ''
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

    // El alumno de secretaría no tiene matrícula (con una matrícula activa la baja se rechaza, `RN-ALUM-05`).
    studentId = (await createStudent(admin, 'Alumno', family)).id

    // El del docente sí: matriculado en un grupo que imparte, con una fotografía para ver que tampoco puede cambiarla.
    groupStudentId = (await createStudent(admin, 'Alumno', groupFamily)).id
    await enroll(admin, groupStudentId, year, structure, structure.groupFrom)
    await admin.json('POST', 'teaching-assignments', 201, {
      teacher: centreA.docenteUser,
      group: structure.groupFrom,
      subject: structure.subject,
    })
    await admin.upload('PUT', `students/${groupStudentId}/photo`, 200, {
      file: { name: 'foto-ficticia.png', mimeType: 'image/png', buffer: syntheticPng(64, 48) },
    })
  })

  /** Anchura del PNG guardado, descargado del almacén por la URL firmada que da la API (`ADR-076 §2.3`). */
  async function storedPhotoWidth(): Promise<number> {
    const signed = await secretaria.json('GET', `students/${studentId}/photo`, 200)
    const store = await playwrightRequest.newContext()
    const image = await store.get(signed.url as string)

    expect(image.status(), 'la URL firmada de la fotografía debe servirse del almacén').toBe(200)

    const width = pngWidth(await image.body())

    await store.dispose()

    return width
  }

  test('CA-E2ECI-30 L-03: secretaría corrige los datos del alumno en la ficha y la lectura HTTP lo confirma', async ({
    browser,
  }) => {
    const page = await pageAs(browser, await browserState(secretaria))

    await page.goto(`/alumnado/${studentId}`)
    await expect(page.getByRole('heading', { level: 1, name: new RegExp(family) })).toBeVisible()
    await page.getByRole('button', { name: 'Corregir datos' }).click()

    const form = page.getByRole('dialog', { name: 'Corregir datos del alumno' })

    await form.getByLabel('Nombre de pila').fill('Alumna')
    await form.getByRole('button', { name: 'Guardar cambios' }).click()

    await expect(page.getByText('Los datos del alumno se han guardado.')).toBeVisible()
    await expect(page.getByRole('heading', { level: 1, name: new RegExp(family) })).toContainText(
      'Alumna',
    )
    await expect(page.getByText('Alumna', { exact: true })).toBeVisible()
    expect((await secretaria.json('GET', `students/${studentId}`, 200)).given_name).toBe('Alumna')
  })

  test('CA-E2ECI-30 L-04: secretaría sube, sustituye y quita la fotografía en la ficha y el almacén lo refleja', async ({
    browser,
  }) => {
    const page = await pageAs(browser, await browserState(secretaria))

    await page.goto(`/alumnado/${studentId}`)
    await expect(page.getByText('El alumno no tiene fotografía.')).toBeVisible()
    await secretaria.json('GET', `students/${studentId}/photo`, 404)

    // Subir.
    await page.getByLabel('Fichero de imagen').setInputFiles({
      name: 'foto-ficticia.png',
      mimeType: 'image/png',
      buffer: syntheticPng(64, 48),
    })
    await page.getByRole('button', { name: 'Subir fotografía' }).click()
    await expect(page.getByText('La fotografía se ha guardado.')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Quitar' })).toBeVisible()
    expect((await secretaria.json('GET', `students/${studentId}`, 200)).has_photo).toBe(true)
    expect(await storedPhotoWidth()).toBe(64)

    // Sustituir por otra imagen distinta: el almacén sirve la nueva.
    await page.getByRole('button', { name: 'Sustituir', exact: true }).click()
    await page.getByLabel('Fichero de imagen').setInputFiles({
      name: 'otra-foto-ficticia.png',
      mimeType: 'image/png',
      buffer: syntheticPng(96, 72),
    })
    await page.getByRole('button', { name: 'Sustituir fotografía' }).click()
    await expect.poll(storedPhotoWidth).toBe(96)

    // Quitar (con confirmación): la ficha vuelve a «sin fotografía» y la API da 404.
    await page.getByRole('button', { name: 'Quitar', exact: true }).click()
    await page
      .getByRole('alertdialog')
      .getByRole('button', { name: /^Quitar la fotografía de / })
      .click()
    await expect(page.getByText('La fotografía se ha quitado.')).toBeVisible()
    await expect(page.getByText('El alumno no tiene fotografía.')).toBeVisible()
    await secretaria.json('GET', `students/${studentId}/photo`, 404)
    expect((await secretaria.json('GET', `students/${studentId}`, 200)).has_photo).toBe(false)
  })

  test('CA-E2ECI-30 L-03: secretaría da de baja al alumno desde la ficha y lo restaura desde el listado', async ({
    browser,
  }) => {
    const page = await pageAs(browser, await browserState(secretaria))

    await page.goto(`/alumnado/${studentId}`)
    await page.getByRole('button', { name: 'Dar de baja', exact: true }).click()
    await page
      .getByRole('alertdialog')
      .getByRole('button', { name: /^Dar de baja a / })
      .click()

    // Tras la baja se vuelve al listado y la API ya no sirve la ficha.
    await expect(page).toHaveURL(/\/alumnado(\?|$)/)
    await expect(page.getByText('El alumno se ha dado de baja.')).toBeVisible()
    await secretaria.json('GET', `students/${studentId}`, 404)

    // Con «Incluir bajas» aparece marcado como baja y se restaura (con confirmación).
    await page.getByRole('searchbox').fill(family)
    await page.getByLabel('Incluir bajas').check()

    const row = page.getByRole('row').filter({ hasText: family })

    await expect(row.getByText('(baja)')).toBeVisible()
    await row.getByRole('button', { name: /^Restaurar/ }).click()
    await page
      .getByRole('alertdialog')
      .getByRole('button', { name: /^Restaurar a / })
      .click()
    await expect(page.getByText('El alumno se ha restaurado.')).toBeVisible()

    // La ficha vuelve a existir y conserva lo corregido antes.
    const restored = await secretaria.json('GET', `students/${studentId}`, 200)

    expect(restored.given_name).toBe('Alumna')
    await page.goto(`/alumnado/${studentId}`)
    await expect(page.getByRole('heading', { level: 1, name: new RegExp(family) })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Corregir datos' })).toBeVisible()
  })

  test('CA-E2ECI-30 L-03 L-04: un docente con ámbito grupo ve la ficha de su alumno sin acciones de edición, baja ni fotografía, y la API responde 403', async ({
    browser,
  }) => {
    const page = await pageAs(browser, await browserState(docente))

    await page.goto(`/alumnado/${groupStudentId}`)
    await expect(
      page.getByRole('heading', { level: 1, name: new RegExp(groupFamily) }),
    ).toBeVisible()
    await expect(page.getByRole('heading', { level: 2, name: 'Fotografía' })).toBeVisible()

    // El alumno tiene fotografía, pero el docente solo puede mostrarla: ni corregir, ni dar de baja, ni sustituir o quitar.
    await expect(page.getByRole('button', { name: 'Corregir datos' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Dar de baja' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Mostrar fotografía' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Sustituir', exact: true })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Quitar', exact: true })).toHaveCount(0)
    await expect(page.getByLabel('Fichero de imagen')).toHaveCount(0)

    // La interfaz oculta; la seguridad es del servidor (INV-002): las tres escrituras son 403 y nada cambia.
    await docente.json('GET', `students/${groupStudentId}`, 200)
    await docente.json('PATCH', `students/${groupStudentId}`, 403, { given_name: 'Intruso' })
    await docente.json('DELETE', `students/${groupStudentId}`, 403)
    await docente.upload('PUT', `students/${groupStudentId}/photo`, 403, {
      file: { name: 'foto-ficticia.png', mimeType: 'image/png', buffer: syntheticPng(32, 32) },
    })

    const after = await admin.json('GET', `students/${groupStudentId}`, 200)

    expect(after.given_name).toBe('Alumno')
    expect(after.has_photo).toBe(true)
  })
})
