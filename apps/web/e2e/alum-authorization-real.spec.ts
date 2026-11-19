/* eslint-disable @typescript-eslint/no-explicit-any -- cuerpos JSON de la API real, sin tipar a propósito. */
import { test, expect, type Page, type Request } from '@playwright/test'
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
 * `E2E-CI-d1` (#737, `docs/proceso/e2e-real-ci.md §9.4`, matriz `§4.5`, `D-01` y `D-05`), modo `alum` (centro A): **autorización
 * en pantalla** de `docente`, `secretaria` y `direccion`, en navegador real contra la API real (cookie de sesión y CSRF reales;
 * datos sintéticos de `REQ-SEED-005`).
 *
 * - `CA-E2ECI-22`: el rol sin el permiso de la ruta ve «Sin acceso» (`AppShellLayout`, `CA-CORE-095`) sin que la vista se monte
 *   (ni una petición `fetch`/`xhr` a su recurso, o su formulario sin pintar), la entrada no está en su menú (`CA-CORE-100`, solo
 *   si la ruta tiene entrada) y el *endpoint* principal responde `403` (`CA-CORE-070`). Los pares de conserjería y `core-roles` de
 *   secretaría los cubre `prl-z-authorization-real.spec.ts` y no se repiten.
 * - `CA-E2ECI-22b`: `docente` con `matricula.leer` de ámbito `grupo` en la lista de clase de un grupo en el que no imparte: la
 *   vista se monta, la entrada está en el menú, no hay filas ajenas, el listado ajeno viene vacío y el detalle da `404`.
 * - `CA-E2ECI-23`: `docente` en la estructura académica (`estructura_academica.leer` sin `.crear`): sin «Nueva etapa» y
 *   `POST /education-levels` da `403` (`RN-CORE-61`).
 * - `CA-E2ECI-25` (enmendado el 2026-11-18): una reserva de un docente; otro docente pulsa «Cancelar», el servidor responde
 *   `404` (`reservas.eliminar` de ámbito `propios`) y la pantalla lo muestra sin romperse; el autor sí puede (`CA-ACAD-139`).
 *
 * **Orden y datos**: se llama `alum-authorization-…` para correr **antes** de `alum-group-change-real.spec.ts`, que cierra el curso
 * activo en su último test (Playwright ordena los ficheros alfabéticamente con `--workers=1`); la estructura, el alumno, la
 * asignación docente, el espacio y las reservas son propios (nombres con sufijo único) y se revierten en `afterAll` (mejor esfuerzo); el centro se retira entero al final del guion. Un único acceso
 * por actor (administrador para preparar, `docente`, `docente2`, `secretaria` y `direccion`), porque la API limita los accesos
 * (10 por minuto y centro desde una IP) y `RealSession.login` espera lo que pida el servidor si responde `429`; las llamadas HTTP
 * salen de la sesión de cada actor con su cookie y su CSRF.
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).
 */

/** Peticiones de datos (`fetch`/`xhr`) a un recurso: la navegación de la propia SPA no cuenta. */
function trackData(page: Page, resource: RegExp): { urls: string[]; stop: () => void } {
  const urls: string[] = []
  const onRequest = (request: Request): void => {
    if (['fetch', 'xhr'].includes(request.resourceType()) && resource.test(request.url())) {
      urls.push(request.url())
    }
  }

  page.on('request', onRequest)

  return { urls, stop: () => page.off('request', onRequest) }
}

interface Denied {
  /** Ruta de la SPA y permiso que le falta (`shell.ts`, `meta.permissions`). */
  ruta: string
  permiso: string
  /** Entrada de menú de la ruta, si la tiene (`navigation` de `shell.ts`). */
  menu?: string
  /** Petición de datos que haría la vista al montarse; si no hace ninguna, `montada` dice qué pintaría. */
  recurso?: RegExp
  montada?: (page: Page) => ReturnType<Page['locator']>
  /** *Endpoint* principal y método. */
  api: { method: string; path: string; body?: unknown }
}

test.describe
  .serial('CA-E2ECI-22 CA-E2ECI-22b CA-E2ECI-23 CA-E2ECI-25 (E2E-CI-d1): autorización en pantalla de docente, secretaría y dirección contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).',
  )

  test.use({ baseURL: BASE_URL })

  const label = tag()
  let admin: RealSession
  let docente: RealSession
  let docente2: RealSession
  let secretaria: RealSession
  let direccion: RealSession
  let docentePage: Page
  let docente2Page: Page
  let secretariaPage: Page
  let direccionPage: Page
  let year: YearInfo
  let structure: Structure
  const family = `Ficticio${label}`
  let studentId = ''
  let enrollmentId = ''
  let assignmentId = ''
  let facilityId = ''
  let facilityCode = ''
  const bookings: string[] = []
  // Recursos que un POST de denegación crearía si el permiso se rompiera (curso 2040, usuario `…-dir@example.com`).
  const leaked: string[] = []

  test.afterAll(async () => {
    // Revierte lo propio (mejor esfuerzo: el centro se retira entero al terminar el guion).
    for (const id of bookings) await admin?.send('DELETE', `facility-bookings/${id}`)
    if (facilityId) await admin?.send('DELETE', `facilities/${facilityId}`)
    for (const path of leaked) await admin?.send('DELETE', path)
    if (assignmentId) await admin?.send('DELETE', `teaching-assignments/${assignmentId}`)
    if (enrollmentId) await admin?.send('DELETE', `enrollments/${enrollmentId}`)
    if (studentId) await admin?.send('DELETE', `students/${studentId}`)

    await closeAll()
    await Promise.all(
      [docente, docente2, secretaria, direccion, admin].map((session) => session?.dispose()),
    )
  })

  test.beforeAll(async ({ browser }) => {
    // Los inicios de sesión esperan lo que pida la API si responde 429 (`RealSession.login`).
    test.setTimeout(240_000)
    admin = await RealSession.login(centreA.slug, centreA.adminEmail, centreA.adminPassword)

    year = await ensureActiveYear(admin)
    structure = await createStructure(admin, year.id, label)

    const student = await createStudent(admin, 'Alumno', family)

    studentId = student.id
    enrollmentId = await enroll(admin, student.id, year, structure, structure.groupFrom)

    // Espacio de las reservas (`espacios.crear` solo lo tiene el administrador).
    facilityCode = `E${label}`
    facilityId = (
      await admin.json('POST', 'facilities', 201, {
        facility_type: 'aula',
        code: facilityCode,
        name: `Aula ficticia ${label}`,
      })
    ).public_id as string

    // Un inicio de sesión por actor.
    docente = await RealSession.login(centreA.slug, centreA.docenteEmail, centreA.staffPassword)
    docente2 = await RealSession.login(centreA.slug, centreA.docente2Email, centreA.staffPassword)
    secretaria = await RealSession.login(
      centreA.slug,
      centreA.secretariaEmail,
      centreA.staffPassword,
    )
    direccion = await RealSession.login(centreA.slug, centreA.direccionEmail, centreA.staffPassword)
    docentePage = await pageAs(browser, await browserState(docente))
    docente2Page = await pageAs(browser, await browserState(docente2))
    secretariaPage = await pageAs(browser, await browserState(secretaria))
    direccionPage = await pageAs(browser, await browserState(direccion))
  })

  /**
   * El rol sin el permiso: «Sin acceso» dentro del shell sin montar la vista, entrada fuera del menú y `403` por HTTP real
   * (INV-002: el menú y la pantalla no son la seguridad).
   */
  async function expectDenied(
    page: Page,
    session: RealSession,
    contrast: string,
    denied: Denied,
  ): Promise<void> {
    const data = denied.recurso ? trackData(page, denied.recurso) : null

    await page.goto(denied.ruta)
    await expect(page.getByRole('alert').getByText('Sin acceso')).toBeVisible()
    await expect(page.getByText('No tienes permiso para ver este contenido.')).toBeVisible()
    data?.stop()

    if (data) expect(data.urls, 'la vista no debe pedir su recurso').toEqual([])
    if (denied.montada) await expect(denied.montada(page)).toHaveCount(0)

    const nav = page.getByRole('navigation')

    if (denied.menu) {
      await expect(nav.getByRole('link', { name: denied.menu, exact: true })).toHaveCount(0)
    }

    // Contraste: el menú sí lleva lo que el rol puede ver.
    await expect(nav.getByRole('link', { name: contrast, exact: true }).first()).toBeVisible()

    const response = await session.send(denied.api.method, denied.api.path, denied.api.body)

    // Si la denegación se rompiera y el POST creara algo, se anota para retirarlo en `afterAll`.
    if (denied.api.method === 'POST' && response.status() < 300) {
      const created = (await response.json().catch(() => ({})))?.data?.public_id as
        string | undefined

      if (created) leaked.push(`${denied.api.path}/${created}`)
    }

    expect(
      response.status(),
      `${denied.api.method} ${denied.api.path}: ${await response.text()}`,
    ).toBe(403)
  }

  const DOCENTE_DENIED: Denied[] = [
    {
      ruta: '/administracion/usuarios',
      permiso: 'usuario.leer',
      menu: 'Usuarios',
      recurso: /\/users(\?|$)/,
      api: { method: 'GET', path: 'users' },
    },
    {
      ruta: '/administracion/cursos',
      permiso: 'curso_academico.leer',
      menu: 'Cursos académicos',
      recurso: /\/academic-years(\?|$)/,
      api: { method: 'GET', path: 'academic-years' },
    },
    {
      ruta: '/familias',
      permiso: 'familia.leer',
      menu: 'Familias',
      recurso: /\/family-units(\?|$)/,
      api: { method: 'GET', path: 'family-units' },
    },
  ]

  for (const denied of DOCENTE_DENIED) {
    test(`CA-E2ECI-22 (CA-CORE-095, CA-CORE-100, CA-CORE-070, ${denied.permiso}): el docente en ${denied.ruta} ve «Sin acceso», sin vista, sin entrada de menú y con 403 por HTTP`, async () => {
      await expectDenied(docentePage, docente, 'Reservas', denied)
    })
  }

  const SECRETARIA_DENIED: Denied[] = [
    {
      ruta: '/administracion/auditoria',
      permiso: 'auditoria.leer',
      menu: 'Auditoría',
      recurso: /\/audit-logs(\?|$)/,
      api: { method: 'GET', path: 'audit-logs' },
    },
    {
      ruta: '/administracion/centro',
      permiso: 'configuracion.leer',
      menu: 'Centro',
      recurso: /\/tenant\/settings(\?|$)/,
      api: { method: 'GET', path: 'tenant/settings' },
    },
    {
      // Sin entrada de menú (`ADR-053 §4.4`): acción de «Cursos académicos», que secretaría sí ve (solo lectura).
      ruta: '/administracion/cursos/nuevo',
      permiso: 'curso_academico.crear',
      montada: (page) => page.locator('#curso-form-code'),
      api: {
        method: 'POST',
        path: 'academic-years',
        body: { code: `E2E-${label}`, starts_on: '2040-09-01', ends_on: '2041-08-31' },
      },
    },
    {
      ruta: '/administracion/mfa',
      permiso: 'mfa.leer',
      menu: 'Administración de MFA',
      recurso: /\/mfa-compliance(\?|$)/,
      api: { method: 'GET', path: 'mfa-compliance' },
    },
  ]

  for (const denied of SECRETARIA_DENIED) {
    test(`CA-E2ECI-22 (CA-CORE-095, CA-CORE-100, CA-CORE-070, ${denied.permiso}): la secretaria en ${denied.ruta} ve «Sin acceso», sin vista, sin entrada de menú y con 403 por HTTP`, async () => {
      await expectDenied(secretariaPage, secretaria, 'Usuarios', denied)
    })
  }

  const DIRECCION_DENIED: Denied[] = [
    {
      // Sin entrada de menú: acción de «Usuarios», que dirección sí ve (solo `usuario.leer`).
      ruta: '/administracion/usuarios/nuevo',
      permiso: 'usuario.crear',
      recurso: /\/roles(\?|$)/,
      montada: (page) => page.locator('#user-form-email'),
      api: {
        method: 'POST',
        path: 'users',
        body: {
          email: `e2e-d1-${label}-dir@example.com`,
          person: { given_name: 'Ficticia', family_name_1: `Dir${label}` },
        },
      },
    },
    {
      ruta: '/administracion/auditoria',
      permiso: 'auditoria.leer',
      menu: 'Auditoría',
      recurso: /\/audit-logs(\?|$)/,
      api: { method: 'GET', path: 'audit-logs' },
    },
    {
      ruta: '/configuracion/condiciones-de-uso',
      permiso: 'texto_legal.leer',
      menu: 'Condiciones de uso',
      recurso: /\/terms-versions(\?|$)/,
      api: { method: 'GET', path: 'terms-versions' },
    },
  ]

  for (const denied of DIRECCION_DENIED) {
    test(`CA-E2ECI-22 (CA-CORE-095, CA-CORE-100, CA-CORE-070, ${denied.permiso}): la dirección en ${denied.ruta} ve «Sin acceso», sin vista, sin entrada de menú y con 403 por HTTP`, async () => {
      await expectDenied(direccionPage, direccion, 'Usuarios', denied)
    })
  }

  test('CA-E2ECI-22b (ADR-038 §6.4, RN-ALUM-60, matricula.leer de ámbito grupo): el docente sin asignación en el grupo abre la lista de clase, no ve filas ajenas, el listado sale vacío y el detalle da 404', async () => {
    expect(1, "ROTURA DELIBERADA CA-E2ECI-45").toBe(2);
    // Contraste: el docente con asignación en el grupo (revertida al terminar) sí ve la matrícula.
    const assignment = await admin.json('POST', 'teaching-assignments', 201, {
      teacher: centreA.docenteUser,
      group: structure.groupFrom,
      subject: structure.subject,
    })

    assignmentId = assignment.public_id as string

    const own = await docente.json<any>(
      'GET',
      `enrollments?academic_year=${year.id}&group=${structure.groupFrom}`,
      200,
    )

    expect(own.data.map((e: any) => e.public_id)).toEqual([enrollmentId])
    await docente.json('GET', `enrollments/${enrollmentId}`, 200)

    // El docente sin asignación: la vista se monta y la entrada está en el menú…
    const page = docente2Page

    await page.goto(`/alumnado/listas?academic_year=${year.id}`)
    await expect(page.getByRole('heading', { level: 1, name: 'Listas de clase' })).toBeVisible()
    await expect(page.getByRole('alert').getByText('Sin acceso')).toHaveCount(0)
    await expect(
      page.getByRole('navigation').getByRole('link', { name: 'Listas de clase', exact: true }),
    ).toBeVisible()

    // …pero sin ninguna fila ajena (estado vacío, no un error).
    await expect(page.getByText('No hay matrículas que mostrar')).toBeVisible()
    await expect(page.getByRole('link', { name: new RegExp(family) })).toHaveCount(0)

    // Por HTTP real: listado ajeno vacío (200, nunca 403) y detalle 404 (no se revela que existe).
    const list = await docente2.json<any>(
      'GET',
      `enrollments?academic_year=${year.id}&group=${structure.groupFrom}`,
      200,
    )

    expect(list.data).toEqual([])

    const detail = await docente2.send('GET', `enrollments/${enrollmentId}`)

    expect(detail.status(), await detail.text()).toBe(404)

    const student = await docente2.send('GET', `students/${studentId}`)

    expect(student.status(), await student.text()).toBe(404)
  })

  test('CA-E2ECI-23 (RN-CORE-61, estructura_academica.crear): el docente ve la estructura académica sin «Nueva etapa» y POST /education-levels da 403', async () => {
    const page = docentePage

    await page.goto(`/academico/estructura?academic_year=${year.id}`)
    await expect(
      page.getByRole('heading', { level: 1, name: 'Estructura académica' }),
    ).toBeVisible()
    await expect(page.getByRole('alert').getByText('Sin acceso')).toHaveCount(0)

    // La tabla se montó con la estructura del spec (la ve, solo lectura) y no se ofrece ninguna acción de escritura.
    await expect(page.getByText(`Etapa ficticia ${label}`)).toBeVisible()
    await expect(page.getByRole('button', { name: 'Nueva etapa' })).toHaveCount(0)

    const before = await admin.json<any>(
      'GET',
      `education-levels?academic_year=${year.id}&per_page=100`,
      200,
    )
    const response = await docente.send('POST', 'education-levels', {
      academic_year: year.id,
      code: `X${label}`,
      name_translations: { 'es-ES': `Etapa sin permiso ${label}` },
      stage: 'eso',
      regime: 'concertado',
    })

    expect(response.status(), await response.text()).toBe(403)

    const after = await admin.json<any>(
      'GET',
      `education-levels?academic_year=${year.id}&per_page=100`,
      200,
    )

    expect(after.data).toHaveLength(before.data.length)
  })

  test('CA-E2ECI-25 (CA-ACAD-139, RN-ACAD-142): otro docente pulsa «Cancelar» en la reserva ajena, el servidor responde 404 y la pantalla lo muestra sin romperse; el autor sí puede cancelarla', async () => {
    const created = await docente.json('POST', 'facility-bookings', 201, {
      academic_year: year.id,
      facility: facilityId,
      booked_on: year.startsOn,
      starts_at: '10:00',
      ends_at: '11:00',
    })
    const bookingId = created.public_id as string

    bookings.push(bookingId)

    const cancelLabel = new RegExp(`^Cancelar la reserva de ${facilityCode} `)

    // El otro docente la ve en la pantalla y pulsa «Cancelar».
    const other = docente2Page

    await other.goto(`/academico/reservas?academic_year=${year.id}`)
    await expect(other.getByRole('heading', { level: 1, name: 'Reservas' })).toBeVisible()

    const response = other.waitForResponse(
      (r) =>
        r.url().includes(`/facility-bookings/${bookingId}`) && r.request().method() === 'DELETE',
    )

    await other.getByRole('button', { name: cancelLabel }).click()
    await other
      .getByRole('alertdialog')
      .getByRole('button', { name: 'Cancelar reserva', exact: true })
      .click()
    expect((await response).status()).toBe(404)

    // La pantalla lo muestra (mensaje del servidor en una alerta) y sigue viva con la reserva en su sitio.
    await expect(other.getByRole('alert').filter({ hasText: /\S/ })).toBeVisible()
    await expect(other.getByText('Reserva cancelada.')).toHaveCount(0)
    await expect(other.getByRole('heading', { level: 1, name: 'Reservas' })).toBeVisible()
    await expect(other.getByRole('button', { name: cancelLabel })).toBeVisible()
    await docente.json('GET', `facility-bookings/${bookingId}`, 200)

    // También por HTTP real, sin pasar por la pantalla.
    const direct = await docente2.send('DELETE', `facility-bookings/${bookingId}`)

    expect(direct.status(), await direct.text()).toBe(404)
    await docente.json('GET', `facility-bookings/${bookingId}`, 200)

    // El autor sí puede (`CA-ACAD-139`).
    const page = docentePage

    await page.goto(`/academico/reservas?academic_year=${year.id}`)
    await page.getByRole('button', { name: cancelLabel }).click()
    await page
      .getByRole('alertdialog')
      .getByRole('button', { name: 'Cancelar reserva', exact: true })
      .click()
    await expect(page.getByText('Reserva cancelada.')).toBeVisible()
    await expect(page.getByRole('button', { name: cancelLabel })).toHaveCount(0)

    const gone = await docente.send('GET', `facility-bookings/${bookingId}`)

    expect(gone.status()).toBe(404)
  })
})
