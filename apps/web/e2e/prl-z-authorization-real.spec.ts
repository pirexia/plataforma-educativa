/* eslint-disable @typescript-eslint/no-explicit-any -- cuerpos JSON de la API real, sin tipar a propósito. */
import {
  test,
  expect,
  type Browser,
  type BrowserContext,
  type Page,
  type Request,
} from '@playwright/test'
import { RealSession } from './fixtures/realSession'
import { browserState } from './fixtures/alumReal'

/**
 * `E2E-CI-d1` (#737, `docs/proceso/e2e-real-ci.md §9.4`, matriz `§4.5`), modo `door` (centro A): **autorización en pantalla**
 * de `conserjeria_pas` y `secretaria`, en navegador real y por HTTP real (cookie de sesión y CSRF reales; datos sintéticos de
 * `REQ-SEED-005`).
 *
 * - `CA-E2ECI-22`: el rol sin el permiso de la ruta ve «Sin acceso» (`AppShellLayout`, `CA-CORE-095`) sin que la vista se monte
 *   (ninguna petición `fetch`/`xhr` a su recurso), la entrada no está en su menú (`CA-CORE-100`) y el *endpoint* principal
 *   responde `403` (`CA-CORE-070`): conserje en `acad-structure`, `core-users` y `fam-unit-list`; secretaría en `core-roles`.
 * - `CA-E2ECI-23`: en una pantalla que sí puede abrir, la acción sin permiso no se ofrece (`RN-CORE-61`) y su *endpoint* da
 *   `403`: secretaría en la puerta (sin `entrega_menor.crear`) y conserje en el detalle de una alerta (sin
 *   `alerta_entrega.actualizar`). La alerta es la CERRADA de `prl-door-real` (abrir otra interferiría con los *specs* que cuentan
 *   alertas abiertas), así que aquí no se afirma la ausencia de «Cerrar la alerta» ni de «Guardar la llamada» (tampoco se
 *   ofrecen en una alerta cerrada con permiso: sería tautológico); esa ausencia con la alerta abierta la cubre
 *   `prl-door-real.spec.ts` (CA-PRL-035). Lo que cuenta aquí es el `403` del `PATCH` y que el estado no cambie.
 *
 * **Orden y datos**: Playwright ejecuta los ficheros por orden alfabético con `--workers=1`; este se llama `prl-z-…` para correr
 * **después** de `prl-door-real.spec.ts`, cuyos datos reutiliza (el alumno 2 con su tutora y la alerta que el administrador cerró)
 * en vez de crear los suyos, que romperían a `prl-door-api` y `prl-door-real` (el alumno 2 solo cabe en una unidad y esos *specs*
 * cuentan alertas abiertas). Un único acceso por actor (conserje y secretaría) con `RealSession.login`, que espera el
 * `Retry-After` si la API limita los accesos (429: 10 por minuto y centro desde una IP; el centro A ya gasta 8 en los otros dos
 * *specs*); la pestaña reutiliza esa sesión (`browserState`) y las llamadas HTTP salen de ella con su cookie y su CSRF.
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:prl` (apps/web/scripts/e2e-real-prl.sh).
 */

const SLUG = process.env.E2E_A_SLUG ?? 'demo'
const BASE_URL = process.env.E2E_BASE_URL ?? `http://${SLUG}.plataforma.test:5173`

test.use({
  baseURL: BASE_URL,
  launchOptions: { args: ['--host-resolver-rules=MAP *.plataforma.test 127.0.0.1'] },
})

type Http = (method: string, path: string, body?: unknown) => Promise<{ status: number; json: any }>

/**
 * Sesión de un actor: un único acceso por la API real (`RealSession.login` espera el `Retry-After` si responde 429), la pestaña
 * del navegador con esa misma sesión (`browserState`) y `http`, que lanza la petición con su cookie de sesión y su CSRF.
 */
async function openActor(
  browser: Browser,
  email: string,
  password: string,
): Promise<{ context: BrowserContext; page: Page; http: Http; session: RealSession }> {
  const session = await RealSession.login(SLUG, email, password)
  const context = await browser.newContext({
    baseURL: BASE_URL,
    locale: 'es-ES',
    storageState: await browserState(session),
  })
  const page = await context.newPage()

  const http: Http = async (method, path, body) => {
    const response = await session.send(method, path, body)
    const text = await response.text()

    return { status: response.status(), json: text === '' ? null : JSON.parse(text) }
  }

  return { context, page, http, session }
}

/** Rutas de la SPA sin el permiso del conserje: `shell.ts` de cada módulo (`meta.permissions`), entrada de menú y *endpoint*. */
const CONSERJE_DENIED = [
  {
    ruta: '/academico/estructura',
    permiso: 'estructura_academica.leer',
    menu: 'Estructura académica',
    api: 'education-levels',
    recurso: /\/education-levels(\?|$)/,
  },
  {
    ruta: '/administracion/usuarios',
    permiso: 'usuario.leer',
    menu: 'Usuarios',
    api: 'users',
    recurso: /\/users(\?|$)/,
  },
  {
    ruta: '/familias',
    permiso: 'familia.leer',
    menu: 'Familias',
    api: 'family-units',
    recurso: /\/family-units(\?|$)/,
  },
] as const

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

test.describe
  .serial('CA-E2ECI-22 y CA-E2ECI-23 (E2E-CI-d1): autorización en pantalla de conserjería y secretaría (modo door)', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real:prl` (apps/web/scripts/e2e-real-prl.sh).',
  )

  let studentPerson = ''
  let alertId = ''
  let conserje: Awaited<ReturnType<typeof openActor>>
  let secretaria: Awaited<ReturnType<typeof openActor>>

  test.beforeAll(async ({ browser }) => {
    conserje = await openActor(
      browser,
      process.env.E2E_A_CONSERJE_EMAIL ?? '',
      process.env.E2E_A_STAFF_PASSWORD ?? '',
    )
    secretaria = await openActor(
      browser,
      process.env.E2E_A_SECRETARIA_EMAIL ?? '',
      process.env.E2E_A_STAFF_PASSWORD ?? '',
    )

    // Datos de `prl-door-real` (corre antes): el alumno 2, buscable en la puerta, y la alerta que se cerró.
    const found = await conserje.http('GET', 'door/students?q=Mat')

    expect(found.status).toBe(200)
    expect(
      found.json.data,
      'falta el alumno de prl-door-real: ejecuta antes ese spec',
    ).toHaveLength(1)
    studentPerson = found.json.data[0].student_person as string

    const alerts = await conserje.http('GET', 'pickup-alerts?status=cerrada')

    expect(alerts.status).toBe(200)
    const own = (alerts.json.data as Array<Record<string, string>>).find(
      (a) => a.student_person === studentPerson,
    )

    expect(own, 'falta la alerta de prl-door-real: ejecuta antes ese spec').toBeDefined()
    alertId = own!.public_id as string
  })

  test.afterAll(async () => {
    await Promise.all([
      conserje?.context.close(),
      secretaria?.context.close(),
      conserje?.session.dispose(),
      secretaria?.session.dispose(),
    ])
  })

  for (const denied of CONSERJE_DENIED) {
    test(`CA-E2ECI-22 (CA-CORE-095, CA-CORE-100, CA-CORE-070, ${denied.permiso}): el conserje en ${denied.ruta} ve «Sin acceso», sin vista, sin entrada de menú y con 403 por HTTP`, async () => {
      const data = trackData(conserje.page, denied.recurso)

      await conserje.page.goto(denied.ruta)

      // «Sin acceso» dentro del shell; la vista no se monta: ni una petición a su recurso.
      await expect(conserje.page.getByRole('alert').getByText('Sin acceso')).toBeVisible()
      await expect(
        conserje.page.getByText('No tienes permiso para ver este contenido.'),
      ).toBeVisible()
      data.stop()
      expect(data.urls, 'la vista no debe pedir su recurso').toEqual([])

      // El menú del conserje no lleva la entrada, pero sí «Conserjería».
      const nav = conserje.page.getByRole('navigation')

      await expect(nav.getByRole('link', { name: denied.menu, exact: true })).toHaveCount(0)
      await expect(nav.getByRole('link', { name: 'Conserjería' }).first()).toBeVisible()

      // La API real lo deniega igual (INV-002): el menú y la pantalla no son la seguridad.
      expect((await conserje.http('GET', denied.api)).status).toBe(403)
    })
  }

  test('CA-E2ECI-22 (CA-CORE-095, CA-CORE-100, CA-CORE-070, rol.leer): la secretaria en /administracion/roles ve «Sin acceso», sin vista, sin entrada de menú y con 403 por HTTP', async () => {
    const data = trackData(secretaria.page, /\/roles(\?|$)/)

    await secretaria.page.goto('/administracion/roles')
    await expect(secretaria.page.getByRole('alert').getByText('Sin acceso')).toBeVisible()
    data.stop()
    expect(data.urls, 'la vista no debe pedir su recurso').toEqual([])

    const nav = secretaria.page.getByRole('navigation')

    await expect(nav.getByRole('link', { name: 'Roles', exact: true })).toHaveCount(0)
    // Contraste: sí tiene `usuario.leer`, así que «Usuarios» sigue en su menú.
    await expect(nav.getByRole('link', { name: 'Usuarios', exact: true }).first()).toBeVisible()

    expect((await secretaria.http('GET', 'roles')).status).toBe(403)
  })

  test('CA-E2ECI-23 (RN-CORE-61, entrega_menor.crear): la secretaria ve la puerta del alumno sin acciones de salida y POST /student-movements da 403', async () => {
    await secretaria.page.goto(`/conserjeria/alumnos/${studentPerson}`)

    // La lista se montó y tiene una tarjeta (la tutora), pero ninguna acción de registrar salida ni entrada.
    await expect(secretaria.page.getByRole('heading', { level: 2, name: /^Marta/ })).toBeVisible()
    await expect(secretaria.page.getByRole('button', { name: /^Entregar a/ })).toHaveCount(0)
    await expect(
      secretaria.page.getByRole('button', { name: 'Registrar entrada fuera de horario' }),
    ).toHaveCount(0)
    await expect(
      secretaria.page.getByRole('button', { name: 'Quien se presenta no está en la lista' }),
    ).toHaveCount(0)

    const list = await secretaria.http('GET', `door/students/${studentPerson}/pickup-list`)

    expect(list.status).toBe(200)

    const response = await secretaria.http('POST', 'student-movements', {
      student_person: studentPerson,
      direction: 'salida',
      picked_up_by: list.json.data[0].person,
      verification_method: 'documento',
    })

    expect(response.status).toBe(403)
  })

  test('CA-E2ECI-23 (RN-CORE-61, alerta_entrega.actualizar): el conserje abre el detalle de la alerta sin acciones de cierre y PATCH /pickup-alerts/{id} da 403 sin cambiar nada', async () => {
    await conserje.page.goto(`/conserjeria/alertas/${alertId}`)
    await expect(conserje.page.getByRole('heading', { level: 1, name: 'Alerta' })).toBeVisible()

    const before = await conserje.http('GET', `pickup-alerts/${alertId}`)
    const patch = await conserje.http('PATCH', `pickup-alerts/${alertId}`, {
      resolution: 'no_entregado',
      resolution_notes: 'Intento sin permiso (E2E-CI-d1).',
    })
    const after = await conserje.http('GET', `pickup-alerts/${alertId}`)

    expect(patch.status).toBe(403)
    expect(after.json).toEqual(before.json)
  })
})
