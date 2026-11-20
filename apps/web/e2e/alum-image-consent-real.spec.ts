import { test, expect } from '@playwright/test'
import { RealSession } from './fixtures/realSession'
import { downloadExportCsv } from './fixtures/dataExport'
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
 * `docs/proceso/e2e-real-ci.md §9.4`, `CA-E2ECI-29` (E2E-CI-d2, filas F-04 y F-05 de `e2e-real-ci-matriz.md §4.4`; `REQ-FAM-UNIT-004`):
 * el administrador redacta y publica **en pantalla** el texto del consentimiento de imagen (nivel `web`; pantalla
 * `/configuracion/condiciones-de-uso`, que gestiona los tres tipos de texto legal: aquí NO se publica `condiciones_uso`, porque su muro
 * dejaría al tutor sin acceso al resto de specs de `alum`, tramo 3); secretaría registra una decisión presencial (por HTTP real: la SPA
 * no registra decisiones, `api/consent.ts`) y exporta el informe **en pantalla** (`/familias/consentimientos`); el tutor ve la decisión
 * por HTTP real y el CSV se descarga de Garage por la URL firmada (`ADR-076 §2.3`). Datos sintéticos de `REQ-SEED-005`. El alumno y su
 * vínculo son propios de este spec; el tutor se reutiliza de `tutorGuardian` (solo puede ser tutor una vez).
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh; levanta Garage y el worker).
 */
test.describe('CA-E2ECI-29 (REQ-FAM-UNIT-004): texto de consentimiento de imagen, decisión presencial e informe exportado', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real, Garage y un centro de pruebas: ejecútalo con `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).',
  )

  test.use({ baseURL: BASE_URL })
  test.describe.configure({ mode: 'serial' })

  const label = tag()
  const family = `Consentido${label}`
  const titleText = `Consentimiento de imagen web ficticio ${label}`
  let admin: RealSession
  let secretaria: RealSession
  let tutor: RealSession
  let guardian = ''
  let studentPerson = ''
  let textId = ''
  let textVersion = 0

  test.afterAll(async () => {
    await closeAll()
    await Promise.all([admin, secretaria, tutor].map((session) => session?.dispose()))
  })

  test.beforeAll(async () => {
    // Los inicios de sesión esperan lo que pida la API si responde 429 (`RealSession.login`).
    test.setTimeout(180_000)
    admin = await RealSession.login(centreA.slug, centreA.adminEmail, centreA.adminPassword)
    secretaria = await RealSession.login(
      centreA.slug,
      centreA.secretariaEmail,
      centreA.staffPassword,
    )
    tutor = await RealSession.login(centreA.slug, centreA.tutorEmail, centreA.staffPassword)

    const tutorData = await tutorGuardian(admin)
    const student = await admin.json(
      'POST',
      'students',
      201,
      {
        given_name: 'Alumno',
        family_name_1: family,
        birth_date: '2016-03-03',
        family_unit: tutorData.unit,
      },
      { 'Idempotency-Key': ulid() },
    )

    guardian = tutorData.guardian
    studentPerson = student.person.public_id as string
    await admin.json('POST', 'guardian-links', 201, {
      guardian,
      student_person: studentPerson,
      relationship: 'madre',
      is_legal_guardian: true,
    })
  })

  test('CA-E2ECI-29 F-05: el administrador redacta y publica el texto de consentimiento de imagen desde la pantalla de textos legales', async ({
    browser,
  }) => {
    const page = await pageAs(browser, await browserState(admin))

    await page.goto('/configuracion/condiciones-de-uso')
    await expect(page.getByRole('heading', { level: 1, name: 'Condiciones de uso' })).toBeVisible()
    // Sin versiones previas la tabla vacía ofrece una segunda acción «Nueva versión» (puede aparecer o no al pulsar): la primera es la de la cabecera.
    await page.getByRole('button', { name: 'Nueva versión' }).first().click()

    const form = page.getByRole('dialog', { name: 'Nueva versión' })

    await form.getByRole('combobox', { name: 'Tipo' }).click()
    await page.getByRole('option', { name: 'Consentimiento de imagen: web' }).click()
    await form.locator('#fam-unit-terms-title-es-ES').fill(titleText)
    await form
      .locator('#fam-unit-terms-body-es-ES')
      .fill('Autorizo la publicación de la imagen de mi hijo o hija en la web del centro.')
    await form.getByRole('button', { name: 'Crear', exact: true }).click()

    const row = page.getByRole('row').filter({ hasText: 'Consentimiento de imagen: web' })

    await expect(row.filter({ hasText: 'Borrador' }).first()).toBeVisible()

    // Publicar el borrador recién creado (el de mayor versión del tipo): su botón lleva el nombre de la versión.
    const drafts = await admin.json(
      'GET',
      'terms-versions?kind=consentimiento_imagen_web&status=borrador',
      200,
    )
    const draft = (
      drafts.data as Array<{ public_id: string; version_number: number; title: string }>
    ).sort((a, b) => b.version_number - a.version_number)[0]

    textId = draft.public_id
    textVersion = draft.version_number
    await page
      .getByRole('button', {
        name: `Publicar Consentimiento de imagen: web · versión ${textVersion}`,
      })
      .click()
    await page
      .getByRole('dialog', { name: /^Publicar Consentimiento de imagen: web/ })
      .getByRole('button', { name: 'Publicar', exact: true })
      .click()
    await expect(page.getByText('Versión publicada.')).toBeVisible()

    // Lectura HTTP real: el texto está publicado y el tutor puede leerlo (RN-FAM-UNIT-75).
    expect((await admin.json('GET', `terms-versions/${textId}`, 200)).status).toBe('publicada')

    const texts = (await tutor.json('GET', 'image-consent-texts', 200)).data as Array<{
      level: string
      title: string | null
      terms_version: { public_id: string } | null
    }>
    const web = texts.find((text) => text.level === 'web')

    expect(web?.terms_version?.public_id).toBe(textId)
    expect(web?.title).toBe(titleText)
  })

  test('CA-E2ECI-29 F-04: secretaría registra una decisión presencial y el tutor la ve por HTTP', async () => {
    const decided = await secretaria.json('POST', 'image-consent-decisions', 201, {
      student_person: studentPerson,
      level: 'web',
      decision: 'autorizo',
      on_behalf_of: guardian,
      channel: 'presencial',
      consent_text_version: textId,
    })

    expect(decided.decision.channel).toBe('presencial')
    expect(decided.decision.decision).toBe('autorizo')

    // El tutor ve el estado vigente (autorizo en web, pendiente en redes) y la decisión en el histórico, con el canal.
    const consents = (await tutor.json('GET', 'image-consents', 200)).data as Array<{
      student_person: string
      level: string
      state: string
    }>
    const mine = consents.filter((consent) => consent.student_person === studentPerson)

    expect(mine.find((consent) => consent.level === 'web')?.state).toBe('autorizo')
    expect(mine.find((consent) => consent.level === 'redes_sociales')?.state).toBe('pendiente')

    const history = (
      await tutor.json('GET', `image-consent-decisions?student_person=${studentPerson}`, 200)
    ).data as Array<{ decision: string; channel: string; level: string }>

    expect(history).toHaveLength(1)
    expect(history[0]).toMatchObject({ decision: 'autorizo', channel: 'presencial', level: 'web' })
  })

  test('CA-E2ECI-29 F-04: secretaría ve el consentimiento en pantalla, exporta el informe y el CSV se descarga de Garage', async ({
    browser,
  }) => {
    const page = await pageAs(browser, await browserState(secretaria))

    await page.goto('/familias/consentimientos')
    await expect(
      page.getByRole('heading', { level: 1, name: 'Consentimientos de imagen' }),
    ).toBeVisible()

    // El alumno de este spec aparece en el listado con su estado vigente.
    const web = page
      .getByRole('row')
      .filter({ hasText: family })
      .filter({ hasText: 'Web del centro' })

    await expect(web.getByText('Autorizo', { exact: true })).toBeVisible()

    const [exportResponse] = await Promise.all([
      page.waitForResponse(
        (response) =>
          response.url().includes('/image-consents/exports') &&
          response.request().method() === 'POST',
      ),
      page.getByRole('button', { name: 'Exportar' }).click(),
    ])

    expect(exportResponse.status(), await exportResponse.text()).toBe(202)
    await expect(page.getByText('Preparando exportación…')).toBeVisible()
    await expect(page.getByRole('alert')).toHaveCount(0)

    // El worker escribe el CSV en Garage y se descarga por la URL firmada (ADR-076 §2.3): el alumno y su decisión están en él.
    const csv = await downloadExportCsv(
      { send: (method, path) => secretaria.send(method, path) },
      ((await exportResponse.json()) as { public_id: string }).public_id,
    )
    const lines = csv.trim().split(/\r?\n/)

    expect(lines[0]).toBe(
      'family_unit_code,student_person_public_id,student_display_name,level,state,last_decision_at',
    )

    const rows = lines.filter((line) => line.includes(studentPerson)).map((line) => line.split(','))

    expect(rows.find((cells) => cells[3] === 'web')?.[4]).toBe('autorizo')
    expect(rows.find((cells) => cells[3] === 'redes_sociales')?.[4]).toBe('pendiente')
  })
})
