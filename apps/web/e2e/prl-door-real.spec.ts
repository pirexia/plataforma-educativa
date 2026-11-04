import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { test, expect, type Page } from '@playwright/test'
import { RealSession } from './fixtures/realSession'

/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.9`, `CA-PRL-012` (E2E real) y la parte de integración de `CA-PRL-014`..`-025`:
 * pantalla de conserjería de `prl` **en navegador real contra la API real** (cookie de sesión y CSRF reales; datos sintéticos
 * de `REQ-SEED-005`: `@example.com`, pasaporte ficticio, fotografía sin rostro). El conserje busca al alumno, abre su lista,
 * verifica por la fotografía y registra la salida (`201` y aviso), y abre la alerta; después el administrador del centro
 * (segundo usuario con `alerta_entrega.actualizar`, `REQ-PRL/permisos.md §8.4`) la encuentra en el listado (nombres del alumno
 * y de quien la abrió, filtro por fecha), registra la llamada y la cierra (`CA-PRL-022` de extremo a extremo).
 * «Quien se presenta no está en la lista» no es alcanzable desde la interfaz (`RN-PRL-03`): se comprueba por la API en el mismo
 * *spec*. La accesibilidad se comprueba con aserciones explícitas (roles, nombres, `alt`, foco y estados por texto e icono,
 * `OPEN-FAM-UNIT-62` A), sin `@axe-core/playwright`.
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:prl` (apps/web/scripts/e2e-real-prl.sh) prepara el centro A con `fam_unit` y
 * `prl` contratados y define las variables. Almacén: el S3 que haya en `minio:9000` (MinIO o el servidor falso de
 * `apps/api/tests/Support/e2e-fake-s3.php`, `D-67`); la URL firmada apunta a `minio:9000`, que el navegador no resuelve, así que
 * se redirige a `127.0.0.1:9000`. Los subdominios `*.plataforma.test` se resuelven a loopback con `--host-resolver-rules`, sin
 * tocar `/etc/hosts`.
 */

const S3_PUBLIC = process.env.E2E_S3_PUBLIC_URL ?? 'http://127.0.0.1:9000'
const S3_INTERNAL = process.env.E2E_S3_INTERNAL_URL ?? 'http://minio:9000'
const SLUG = process.env.E2E_A_SLUG ?? 'demo'
const GRANDPA = { given: 'Abuelo', family: 'Ficticio', document: 'E2EFICT0011', phone: '600555666' }
const GRANDPA_NAME = `${GRANDPA.given} ${GRANDPA.family}`
const PRESENTER = 'Dice llamarse Carlos F. y no figura en la lista (E2E ficticio)'

test.use({
  baseURL: process.env.E2E_BASE_URL ?? `http://${SLUG}.plataforma.test:5173`,
  launchOptions: { args: ['--host-resolver-rules=MAP *.plataforma.test 127.0.0.1'] },
})

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/entrar')
  await page.getByLabel(/correo/i).fill(email)
  await page.getByLabel(/contraseña/i).fill(password)
  await page.getByRole('button', { name: /entrar|acceder/i }).click()
  await expect(page).not.toHaveURL(/\/entrar/)
}

async function redirectS3(page: Page): Promise<void> {
  // La URL firmada apunta al `minio` interno de la red de contenedores: el navegador la pide por loopback.
  await page.route(`${S3_INTERNAL}/**`, async (route) => {
    const response = await route.fetch({
      url: route.request().url().replace(S3_INTERNAL, S3_PUBLIC),
    })

    await route.fulfill({ response })
  })
}

test.describe
  .serial('CA-PRL-012 (REQ-PRL-004): conserjería en navegador real contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real:prl` (apps/web/scripts/e2e-real-prl.sh).',
  )

  let student = ''
  let studentName = ''
  let grandpa = ''
  let strangerPerson = ''
  let conserje: RealSession
  let admin: RealSession

  test.beforeAll(async () => {
    admin = await RealSession.login(
      SLUG,
      process.env.E2E_A_ADMIN_EMAIL ?? '',
      process.env.E2E_A_ADMIN_PASSWORD ?? '',
    )
    const secretaria = await RealSession.login(
      SLUG,
      process.env.E2E_A_SECRETARIA_EMAIL ?? '',
      process.env.E2E_A_STAFF_PASSWORD ?? '',
    )

    conserje = await RealSession.login(
      SLUG,
      process.env.E2E_A_CONSERJE_EMAIL ?? '',
      process.env.E2E_A_STAFF_PASSWORD ?? '',
    )

    student = process.env.E2E_A_STUDENT_2 ?? ''

    const unit = (await admin.json('POST', 'family-units', 201, {})).public_id as string

    studentName = (
      await admin.json('POST', `family-units/${unit}/students`, 201, {
        student_person: student,
      })
    ).display_name as string

    const user = await admin.json('POST', 'users', 201, {
      email: 'e2e-madre-prl@example.com',
      person: { given_name: 'Marta', family_name_1: 'Ficticia', contact_phone: '600111222' },
      send_invitation: false,
      role_ids: [],
    })
    const guardian = (
      await admin.json('POST', 'guardians', 201, { user: user.public_id, family_unit: unit })
    ).public_id as string

    await admin.json('POST', 'guardian-links', 201, {
      guardian,
      student_person: student,
      relationship: 'madre',
      is_legal_guardian: true,
    })
    await admin.json('PATCH', `family-units/${unit}`, 200, { principal_guardian: guardian })

    const text = (
      await admin.json('POST', 'terms-versions', 201, {
        kind: 'autorizacion_recogida',
        title_translations: { 'es-ES': 'Autorización de recogida' },
        body_translations: {
          'es-ES': 'Autorizo a la persona indicada a recoger a mi hijo o hija.',
        },
      })
    ).public_id as string

    await admin.json('POST', `terms-versions/${text}/publish`, 200)

    // Persona → autorización → fotografía (OPEN-FAM-UNIT-48): el abuelo figura en la puerta con foto.
    const person = await secretaria.json('POST', 'pickup-persons', 201, {
      given_name: GRANDPA.given,
      family_name_1: GRANDPA.family,
      document_type: 'pasaporte',
      document_number: GRANDPA.document,
      contact_phone: GRANDPA.phone,
    })

    grandpa = person.person as string
    await secretaria.json('POST', 'pickup-authorizations', 201, {
      student_person: student,
      person: grandpa,
      relationship: 'abuelo',
      kind: 'permanente',
      valid_from: '2020-01-01',
      valid_until: null,
      authorized_by: guardian,
      terms_version: text,
      channel: 'presencial',
    })
    await secretaria.upload('PUT', `pickup-persons/${grandpa}/photo`, 200, {
      file: {
        name: 'foto.jpg',
        mimeType: 'image/jpeg',
        buffer: readFileSync(join(process.cwd(), 'e2e', 'fixtures', 'pickup-photo-e2e.jpg')),
      },
    })

    // Alguien que no figura en ninguna lista (RN-PRL-03).
    strangerPerson = (
      await secretaria.json('POST', 'pickup-persons', 201, {
        given_name: 'Vecino',
        family_name_1: 'Ficticio',
        document_type: 'pasaporte',
        document_number: 'E2EFICT0012',
      })
    ).person as string
    await secretaria.dispose()
  })

  test.afterAll(async () => {
    await Promise.all([admin, conserje].map((s) => s?.dispose()))
  })

  test('CA-PRL-035: el conserje busca, abre la lista, verifica por la foto, registra la salida y abre la alerta', async ({
    page,
  }) => {
    await redirectS3(page)
    await signIn(
      page,
      process.env.E2E_A_CONSERJE_EMAIL ?? '',
      process.env.E2E_A_STAFF_PASSWORD ?? '',
    )

    // ── Menú: «Conserjería» y «Alertas de entrega» solo con sus permisos (el conserje tiene ambos) ──
    const nav = page.getByRole('navigation')

    await nav.getByRole('link', { name: 'Conserjería' }).first().click()
    await expect(page).toHaveURL(/\/conserjeria$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Conserjería' })).toBeVisible()

    // ── Búsqueda: campo con etiqueta, ayuda hasta 2 caracteres, una sola petición con el término completo ──
    const search = page.getByLabel('Buscar alumno por nombre o apellidos')

    await expect(page.getByText('Escribe al menos 2 letras.')).toBeVisible()
    await search.fill(studentName.slice(0, 1))

    const searchRequests: string[] = []

    page.on('request', (request) => {
      if (request.url().includes('/door/students?')) searchRequests.push(request.url())
    })
    await search.fill(studentName.slice(0, 3))
    await expect(page.getByRole('status').filter({ hasText: '1 alumno' })).toBeVisible()
    expect(searchRequests).toHaveLength(1)
    expect(searchRequests[0]).toContain(`q=${encodeURIComponent(studentName.slice(0, 3))}`)

    const link = page.getByRole('link', { name: new RegExp(studentName) })

    await expect(link).toBeVisible()
    await link.click()

    // ── Lista de puerta: una lectura, cabecera con el nombre, aviso de registro y títulos fijos ──
    await expect(page).toHaveURL(/\/conserjeria\/alumnos\/[0-9A-Z]{26}$/)
    await expect(page.getByRole('heading', { level: 1, name: studentName })).toBeVisible()
    await expect(page.getByText('Esta consulta queda registrada con tu usuario')).toBeVisible()
    await expect(page.getByText('Lista calculada a las')).toBeVisible()
    await expect(page).toHaveTitle(/Lista de recogida/)

    const grandpaCard = page.getByRole('listitem').filter({
      has: page.getByRole('heading', { level: 2, name: GRANDPA_NAME }),
    })
    const motherCard = page.getByRole('listitem').filter({
      has: page.getByRole('heading', { level: 2, name: /^Marta/ }),
    })

    // Fotografía con `alt` no vacío, cargada, sin `Referer`; estados por texto además de icono (WCAG 1.1.1, 1.4.1).
    const photo = grandpaCard.getByRole('img', { name: `Fotografía de ${GRANDPA_NAME}` })

    await expect(photo).toBeVisible()
    await expect
      .poll(() => photo.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0))
      .toBe(true)
    await expect(photo).toHaveAttribute('referrerpolicy', 'no-referrer')
    await expect(grandpaCard.getByText('Autorizado/a', { exact: true })).toBeVisible()
    await expect(grandpaCard.getByRole('link', { name: GRANDPA.phone })).toHaveAttribute(
      'href',
      `tel:${GRANDPA.phone}`,
    )
    await expect(grandpaCard.getByText(/•••.*0011/)).toBeVisible()
    await expect(grandpaCard.getByText(GRANDPA.document)).toHaveCount(0)

    await expect(motherCard.getByText('Sin fotografía', { exact: true })).toBeVisible()
    await expect(motherCard.locator('svg[aria-hidden="true"]').first()).toBeVisible()
    await expect(motherCard.getByText('Tutor/a', { exact: true })).toBeVisible()
    await expect(motherCard.getByText('Verifica con el documento.')).toBeVisible()
    await expect(motherCard.getByRole('img')).toHaveCount(0)

    // Ningún dato personal en URL, título ni almacenamiento (UI-3, CA-PRL-015).
    const leaks = await page.evaluate(() => ({
      local: JSON.stringify({ ...window.localStorage }),
      session: JSON.stringify({ ...window.sessionStorage }),
      state: JSON.stringify(window.history.state),
      title: document.title,
      url: window.location.href,
    }))
    const haystack = Object.values(leaks).join('\n')

    for (const needle of [studentName, GRANDPA.given, GRANDPA.document, GRANDPA.phone, 'minio']) {
      expect(haystack, `«${needle}» no debe persistirse`).not.toContain(needle)
    }

    // ── Madre sin foto: solo «Por el documento»; el diálogo atrapa el foco y Esc lo devuelve al botón ──
    const motherButton = motherCard.getByRole('button', { name: /^Entregar a Marta/ })

    await motherButton.focus()
    await page.keyboard.press('Enter')

    const motherDialog = page.getByRole('dialog', { name: /^Entregar a Marta/ })

    await expect(motherDialog).toBeVisible()
    await expect(
      motherDialog.getByRole('radio', { name: 'Por la fotografía', exact: true }),
    ).toBeDisabled()
    await expect(
      motherDialog.getByRole('radio', { name: 'Por la fotografía y el documento' }),
    ).toBeDisabled()
    await expect(motherDialog.getByRole('radio', { name: 'Por el documento' })).toBeEnabled()
    await expect(motherDialog.getByRole('radio', { name: 'Por el documento' })).toBeChecked()
    await expect(
      motherDialog.getByText(
        'Esta persona no tiene fotografía: solo puedes verificar con el documento.',
      ),
    ).toBeVisible()
    await expect(motherDialog.getByRole('img')).toHaveCount(0)
    await expect(motherDialog.getByText('Sin fotografía', { exact: true })).toBeVisible()

    for (let i = 0; i < 10; i++) {
      await page.keyboard.press('Tab')
      expect(await motherDialog.evaluate((el) => el.contains(document.activeElement))).toBe(true)
    }

    await page.keyboard.press('Escape')
    await expect(motherDialog).toBeHidden()
    await expect(motherButton).toBeFocused()

    // ── Abuelo con foto: verifica por la fotografía y registra la salida (201 y aviso) ──
    const grandpaButton = grandpaCard.getByRole('button', { name: `Entregar a ${GRANDPA_NAME}` })

    await grandpaButton.focus()
    await page.keyboard.press('Enter')

    const dialog = page.getByRole('dialog', { name: `Entregar a ${GRANDPA_NAME}` })

    await expect(dialog).toBeVisible()
    await expect(dialog.getByRole('img', { name: `Fotografía de ${GRANDPA_NAME}` })).toBeVisible()
    await expect(
      dialog
        .getByRole('group', { name: 'Cómo has comprobado su identidad' })
        .or(dialog.getByText('Cómo has comprobado su identidad'))
        .first(),
    ).toBeVisible()

    // Sin elegir método, el envío no sale (ayuda en cliente, CA-PRL-018).
    await dialog.getByRole('button', { name: 'Registrar' }).click()
    await expect(dialog.getByText('Elige cómo has comprobado su identidad.')).toBeVisible()

    await dialog.getByRole('radio', { name: 'Por la fotografía', exact: true }).check()

    const created = page.waitForResponse(
      (r) => r.url().includes('/student-movements') && r.request().method() === 'POST',
    )

    await dialog.getByRole('button', { name: 'Registrar' }).click()

    const response = await created

    expect(response.status()).toBe(201)
    expect(response.request().headers()['idempotency-key']).toBeTruthy()

    const movement = await response.json()

    expect(movement).toMatchObject({
      direction: 'salida',
      basis: 'tercero',
      verification_method: 'foto',
      student_person: student,
    })

    await expect(dialog).toBeHidden()
    await expect(
      page
        .getByRole('status')
        .filter({ hasText: /^Salida registrada: .*Abuelo Ficticio a las \d/ }),
    ).toBeVisible()

    // El movimiento figura en el servidor, con la persona que recoge.
    const history = await conserje.json('GET', `student-movements?student_person=${student}`, 200)

    expect(history.data).toHaveLength(1)
    expect(history.data[0].picked_up_by.person).toBe(grandpa)

    // ── Quien no figura (RN-PRL-03): la interfaz no lo permite, la API lo rechaza con el error neutro ──
    const stranger = await conserje.send('POST', 'student-movements', {
      student_person: student,
      direction: 'salida',
      picked_up_by: strangerPerson,
      verification_method: 'documento',
    })

    expect(stranger.status()).toBe(422)
    expect((await stranger.json()).errors.picked_up_by[0].code).toBe(
      'prl.movement.person_not_authorized',
    )
    await expect(page.getByText(/Vecino/)).toHaveCount(0)

    // ── Alerta desde la lista: foco, anuncio assertive, protocolo, teléfono y sin acciones de dirección ──
    const alertButton = page.getByRole('button', { name: 'Quien se presenta no está en la lista' })

    await alertButton.focus()
    await page.keyboard.press('Enter')

    const alertDialog = page.getByRole('dialog', { name: 'Quien se presenta no está en la lista' })

    await expect(alertDialog).toBeVisible()
    await alertDialog.getByRole('button', { name: 'Abrir alerta' }).click()
    await expect(alertDialog.getByText('Describe a quien se presenta.')).toBeVisible()
    await alertDialog
      .getByLabel('Nombre que dice tener y, si lo enseña, su documento')
      .fill(PRESENTER)
    await expect(alertDialog.getByText(`${PRESENTER.length} de 500 caracteres`)).toBeVisible()

    const raised = page.waitForResponse(
      (r) => r.url().endsWith('/pickup-alerts') && r.request().method() === 'POST',
    )

    await alertDialog.getByRole('button', { name: 'Abrir alerta' }).click()
    expect((await raised).status()).toBe(201)
    await expect(alertDialog).toBeHidden()

    const region = page.getByRole('region', { name: 'Alerta' })

    await expect(region).toBeVisible()
    await expect(region.getByText('Abierta', { exact: true })).toBeVisible()
    await expect(region.locator('svg[aria-hidden="true"]').first()).toBeVisible()
    await expect(region.getByRole('heading', { level: 2, name: 'Qué hacer ahora' })).toBeVisible()
    await expect(page.locator('p[aria-live="assertive"]')).toHaveText('Alerta abierta.')
    await expect(region.getByRole('link', { name: '600111222' })).toHaveAttribute(
      'href',
      'tel:600111222',
    )
    await expect(region.getByText('No disponible')).toBeVisible()

    // Conserjería no cierra: indicación de avisar a dirección y ni llamada ni cierre.
    await expect(
      region.getByText('Avisa a dirección para que registre la llamada y cierre la alerta.'),
    ).toBeVisible()
    await expect(region.getByRole('button', { name: 'Cerrar la alerta' })).toHaveCount(0)
    await expect(region.getByRole('button', { name: 'Guardar la llamada' })).toHaveCount(0)

    // Recargar por URL directa: la cabecera no lleva el nombre del alumno (CA-PRL-025).
    await page.reload()
    await expect(page.getByRole('heading', { level: 1, name: 'Lista de recogida' })).toBeVisible()
    await expect(page.getByRole('heading', { level: 1, name: studentName })).toHaveCount(0)
  })

  test('CA-PRL-035: el administrador ve la alerta con nombres, filtra por fecha, registra la llamada y la cierra', async ({
    page,
  }) => {
    test.setTimeout(120_000)

    await signIn(page, process.env.E2E_A_ADMIN_EMAIL ?? '', process.env.E2E_A_ADMIN_PASSWORD ?? '')

    // El conserje abrió una única alerta; de ella salen el nombre de quien la abrió y la fecha civil del centro.
    const opened = (await admin.json('GET', 'pickup-alerts?status=abierta', 200)).data as Array<
      Record<string, string>
    >

    expect(opened).toHaveLength(1)

    const raisedBy = opened[0]!.raised_by_display_name as string
    const raisedAt = opened[0]!.raised_at as string
    const civilDate = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Madrid' }).format(
      new Date(raisedAt),
    )

    expect(raisedBy).toBeTruthy()
    expect(opened[0]!.student_display_name).toBe(studentName)

    // ── Listado: nombres (no «Alumno» ni «Usuario»), estado por texto e icono ──
    await page.goto('/conserjeria/alertas')
    await expect(page.getByRole('heading', { level: 1, name: 'Alertas de entrega' })).toBeVisible()

    const table = page.getByRole('table', { name: 'Alertas de entrega' })
    const row = table.getByRole('row').filter({ hasText: studentName })

    await expect(row).toHaveCount(1)
    await expect(row.getByRole('rowheader').getByRole('link', { name: studentName })).toBeVisible()
    await expect(row.getByText(raisedBy)).toBeVisible()
    await expect(row.getByText('Abierta', { exact: true })).toBeVisible()
    await expect(row.locator('svg[aria-hidden="true"]').first()).toBeVisible()
    await expect(table.getByText('Usuario', { exact: true })).toHaveCount(0)

    // ── Filtro por fecha: el día de apertura la muestra; otro día, el estado vacío; quitar la fecha la devuelve ──
    const dateFilter = page.getByLabel('Fecha de apertura')

    const withDate = page.waitForRequest((r) => r.url().includes(`date=${civilDate}`))

    await dateFilter.fill(civilDate)
    await withDate
    await expect(row).toHaveCount(1)

    await dateFilter.fill('2000-01-01')
    await expect(page.getByText('No hay alertas.')).toBeVisible()
    await expect(row).toHaveCount(0)

    await page.getByRole('button', { name: 'Quitar fecha' }).click()
    await expect(row).toHaveCount(1)

    // ── Detalle: protocolo, teléfonos y acciones de dirección ──
    await row.getByRole('link', { name: studentName }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Alerta' })).toBeVisible()
    await expect(page.getByRole('heading', { level: 2, name: 'Qué hacer ahora' })).toBeVisible()
    await expect(page.getByRole('link', { name: '600111222' })).toHaveAttribute(
      'href',
      'tel:600111222',
    )
    await expect(page.getByText('Avisa a dirección para que registre la llamada')).toHaveCount(0)
    await expect(page.getByText(raisedBy)).toBeVisible()
    await expect(page.getByText(PRESENTER)).toBeVisible()

    // Un único PATCH, solo con `principal_contacted_at`.
    const patches: Array<Record<string, unknown>> = []

    page.on('request', (request) => {
      if (request.method() === 'PATCH') patches.push(request.postDataJSON())
    })

    const call = page.waitForResponse((r) => r.request().method() === 'PATCH')

    await page.getByRole('button', { name: 'Guardar la llamada' }).click()
    expect((await call).status()).toBe(200)
    expect(patches).toHaveLength(1)
    expect(Object.keys(patches[0]!)).toEqual(['principal_contacted_at'])
    await expect(page.getByText('Abierta', { exact: true })).toBeVisible()

    // Cierre: pide resolución, confirma (alertdialog) y, tras el 200, el detalle queda cerrado sin teléfonos ni protocolo.
    await page.getByRole('button', { name: 'Cerrar la alerta' }).click()
    await expect(page.getByText('Elige la resolución.')).toBeVisible()
    expect(patches).toHaveLength(1)

    await page.getByRole('radio', { name: 'No entregado' }).check()
    await page.getByLabel('Notas de la resolución').fill('El alumno esperó en dirección (E2E).')
    await page.getByRole('button', { name: 'Cerrar la alerta' }).click()

    const confirm = page.getByRole('alertdialog')

    await expect(confirm).toContainText('Una alerta cerrada no se puede reabrir.')

    const closing = page.waitForResponse((r) => r.request().method() === 'PATCH')

    await confirm.getByRole('button', { name: 'Cerrar la alerta' }).click()
    expect((await closing).status()).toBe(200)
    expect(patches).toHaveLength(2)
    expect(Object.keys(patches[1]!).sort()).toEqual(['resolution', 'resolution_notes'])

    await expect(page.getByText('Cerrada', { exact: true }).first()).toBeVisible()
    await expect(page.getByText('Cerrada por')).toBeVisible()
    await expect(page.getByText('No entregado')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Qué hacer ahora' })).toHaveCount(0)
    await expect(page.getByRole('link', { name: '600111222' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Cerrar la alerta' })).toHaveCount(0)

    // El listado la muestra cerrada, con la resolución y sin teléfonos.
    await page.goto('/conserjeria/alertas')

    const closedRow = page
      .getByRole('table', { name: 'Alertas de entrega' })
      .getByRole('row')
      .filter({
        hasText: studentName,
      })

    await expect(closedRow.getByText('Cerrada', { exact: true })).toBeVisible()
    await expect(closedRow.getByText('No entregado')).toBeVisible()
    await expect(closedRow.locator('svg[aria-hidden="true"]').first()).toBeVisible()

    // Y el servidor lo confirma: cerrada, por el administrador, sin alertas abiertas.
    const finalAlert = (await admin.json('GET', 'pickup-alerts?status=cerrada', 200)).data[0]

    expect(finalAlert).toMatchObject({ status: 'cerrada', resolution: 'no_entregado' })
    expect(finalAlert.resolved_by_display_name).toBeTruthy()
    expect((await admin.json('GET', 'pickup-alerts?status=abierta', 200)).data).toHaveLength(0)
  })
})
