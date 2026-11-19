/* eslint-disable @typescript-eslint/no-explicit-any -- cuerpos JSON de la API real, sin tipar a propósito. */
import { test, expect, type Page } from '@playwright/test'
import { RealSession } from './fixtures/realSession'
import { BASE_URL, browserState, centreA, closeAll, pageAs, tag } from './fixtures/alumReal'

/**
 * `E2E-CI-d1` (#737, `docs/proceso/e2e-real-ci.md §9.4`, matriz `C-02`..`C-06`), modo `alum` (centro A), navegador real contra la
 * API real (cookie de sesión y CSRF reales; datos sintéticos de `REQ-SEED-005`: `@example.com`, nombres explícitamente ficticios).
 *
 * - `CA-E2ECI-24` (enmendado el 2026-11-18, issue #785): alta de un usuario con un rol elegido en el formulario de alta
 *   (`core-user-new`, `role_ids`); usuario con dos roles, uno que permite y otro que deniega (`RPERM-007`), visto en los permisos
 *   efectivos; y el administrador que intenta desactivarse o darse de baja (`RN-CORE-06`, `409 core.validation.cannot_modify_self`).
 *   «Último administrador» (`RN-CORE-07`) queda fuera: lo cubre Pest.
 * - `CA-E2ECI-26`: invitaciones (`core-invitations`): el administrador las reenvía y revoca; secretaría las ve sin esas acciones
 *   y sus peticiones dan `403`.
 * - `CA-E2ECI-26b`: retirada de un rol desde la región «Roles» de la ficha, con confirmación (`RN-CORE-64`). Asignar desde la ficha y
 *   ver un permiso permitido ya lo cubre `core-roles.spec.ts` (`CA-PERM-133`) y no se repite.
 *
 * **Datos y accesos**: los roles y los usuarios los crea el propio spec (nombres con sufijo único) y los retira al terminar; un
 * usuario creado aquí no tiene contraseña, solo es sujeto. Un único acceso por actor (administrador y secretaría): la API limita
 * los accesos (10 por minuto y centro desde una IP) y `alum-browser` ya gasta varios en el centro A. El centro B no sirve para
 * repartirlos: la SPA que arranca el guion solo atiende al centro A (`VITE_API_URL`). No usa el curso académico, así que no depende
 * del orden respecto de `alum-group-change-real`, que lo cierra en su último test.
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).
 */
test.describe('CA-E2ECI-24 CA-E2ECI-26 CA-E2ECI-26b (E2E-CI-d1): autorización y roles en pantalla contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).',
  )

  test.use({ baseURL: BASE_URL })
  test.describe.configure({ mode: 'serial' })

  const label = tag()
  const createdRoles: string[] = []
  const createdUsers: string[] = []
  let admin: RealSession
  let secretaria: RealSession
  let adminPage: Page
  let secretariaPage: Page
  let adminPublicId = ''

  test.afterAll(async () => {
    // Revierte lo propio (mejor esfuerzo: el centro se retira entero al terminar el guion).
    for (const id of createdUsers) {
      await admin.send('PUT', `users/${id}/roles`, { role_ids: [] })
      await admin.send('DELETE', `users/${id}`)
    }

    for (const id of createdRoles) {
      await admin.send('DELETE', `roles/${id}`)
    }

    await closeAll()
    await secretaria?.dispose()
    await admin?.dispose()
  })

  test.beforeAll(async ({ browser }) => {
    // Los inicios de sesión esperan lo que pida la API si responde 429 (`RealSession.login`).
    test.setTimeout(180_000)
    admin = await RealSession.login(centreA.slug, centreA.adminEmail, centreA.adminPassword)
    secretaria = await RealSession.login(
      centreA.slug,
      centreA.secretariaEmail,
      centreA.staffPassword,
    )
    adminPage = await pageAs(browser, await browserState(admin))
    secretariaPage = await pageAs(browser, await browserState(secretaria))
    adminPublicId = (await admin.json('GET', 'me', 200)).public_id as string
  })

  /** Rol propio del spec con una concesión; `scope` obligatorio (`datos.md §2.2`). */
  async function createRole(
    name: string,
    permission: { code: string; effect: 'allow' | 'deny'; scope: string },
  ): Promise<{ id: string; name: string }> {
    const role = await admin.json('POST', 'roles', 201, {
      code: `e2e_${name.replace(/\W/g, '').toLowerCase()}`.slice(0, 60),
      name,
    })

    createdRoles.push(role.public_id as string)
    await admin.json('PUT', `roles/${role.public_id}/permissions`, 200, {
      permissions: [permission],
    })

    return { id: role.public_id as string, name }
  }

  async function createUser(
    suffix: string,
    options: { roleIds?: string[]; invite?: boolean } = {},
  ): Promise<{ id: string; email: string; name: string }> {
    const email = `e2e-d1-${label}-${suffix}@example.com`
    const user = await admin.json('POST', 'users', 201, {
      email,
      person: { given_name: `Ficticio${suffix}`, family_name_1: `D1${label}` },
      send_invitation: options.invite ?? false,
      ...(options.roleIds ? { role_ids: options.roleIds } : {}),
    })

    createdUsers.push(user.public_id as string)

    return { id: user.public_id as string, email, name: `Ficticio${suffix} D1${label}` }
  }

  test('CA-E2ECI-24 (RPERM-013): el administrador da de alta un usuario eligiendo un rol en el formulario y la ficha lo muestra', async () => {
    expect(1, "ROTURA DELIBERADA CA-E2ECI-46").toBe(2);
    const role = await createRole(`Rol alta ${label}`, {
      code: 'auditoria.leer',
      effect: 'allow',
      scope: 'propios',
    })
    const email = `e2e-d1-${label}-alta@example.com`
    const page = adminPage

    await page.goto('/administracion/usuarios/nuevo')
    await page.locator('#user-form-email').fill(email)
    await page.locator('#user-form-given_name').fill('Ficticia')
    await page.locator('#user-form-family_name_1').fill(`Alta${label}`)
    await page.getByRole('checkbox', { name: role.name }).check()
    await page.getByRole('button', { name: 'Guardar', exact: true }).click()

    await expect(page).toHaveURL(/\/administracion\/usuarios\/[0-9A-Z]{26}$/)
    createdUsers.push(page.url().split('/').pop()!)
    await expect(page.getByRole('heading', { level: 1 })).toContainText(`Alta${label}`)
    await expect(
      page.getByRole('region', { name: 'Roles' }).locator('[data-slot="user-roles"]'),
    ).toContainText(role.name)
  })

  test('CA-E2ECI-24 (RPERM-007): con dos roles, uno que permite y otro que deniega, los permisos efectivos muestran «Denegado»', async () => {
    const allow = await createRole(`Rol permite ${label}`, {
      code: 'auditoria.leer',
      effect: 'allow',
      scope: 'propios',
    })
    const deny = await createRole(`Rol deniega ${label}`, {
      code: 'auditoria.leer',
      effect: 'deny',
      scope: 'todos',
    })
    const user = await createUser('multi', { roleIds: [allow.id, deny.id] })
    const page = adminPage

    await page.goto(`/administracion/usuarios/${user.id}/permisos`)

    const row = page.getByRole('row', { name: /^Auditoría Leer/ })

    await expect(row).toContainText('Denegado')
    await expect(row).toContainText(`Denegado por «${deny.name}»`)
    await expect(row).toContainText(allow.name)
  })

  test('CA-E2ECI-24 (RN-CORE-06): en su propia ficha el administrador no puede desactivarse ni darse de baja (botones deshabilitados) y el servidor responde 409 por HTTP real', async () => {
    const page = adminPage

    await page.goto(`/administracion/usuarios/${adminPublicId}`)
    await expect(page.getByText('No puedes modificar tu propia cuenta desde aquí.')).toBeVisible()

    // La pantalla lo impide (`CA-CORE-211`): ni «Desactivar» ni «Dar de baja» ni los roles se pueden accionar.
    await expect(page.getByRole('button', { name: 'Desactivar', exact: true })).toBeDisabled()
    await expect(page.getByRole('button', { name: 'Dar de baja', exact: true })).toBeDisabled()

    const roles = page.getByRole('region', { name: 'Roles' })

    await expect(roles.getByRole('button', { name: 'Guardar roles' })).toBeDisabled()

    const checkboxes = roles.getByRole('checkbox')

    expect(await checkboxes.count()).toBeGreaterThan(0)

    for (const checkbox of await checkboxes.all()) {
      await expect(checkbox).toBeDisabled()
    }

    // Y el servidor lo decide por sí mismo (`INV-010`), sin pasar por la pantalla: `409 core.validation.cannot_modify_self`
    // (su `detail` traducido, el mismo que muestra la SPA).
    const selfMessage = 'No puedes realizar esta acción sobre tu propia cuenta.'

    const status = await admin.send('POST', `users/${adminPublicId}/status`, { status: 'inactivo' })
    const statusBody = await status.text()

    expect(status.status(), statusBody).toBe(409)
    expect(JSON.parse(statusBody).detail).toBe(selfMessage)

    const remove = await admin.send('DELETE', `users/${adminPublicId}`)
    const removeBody = await remove.text()

    expect(remove.status(), removeBody).toBe(409)
    expect(JSON.parse(removeBody).detail).toBe(selfMessage)

    const own = await admin.json('GET', `users/${adminPublicId}`, 200)

    expect(own.status).toBe('activo')
    expect(own.deleted_at ?? null).toBeNull()
  })

  test('CA-E2ECI-26 (RN-CORE-64): el administrador revoca y reenvía una invitación desde la pantalla y el listado lo refleja', async () => {
    const user = await createUser('inv', { invite: true })
    const revoked = await createUser('invrev', { invite: true })
    const page = adminPage

    // Una invitación ya revocada, para que la pantalla ofrezca también «Reenviar» (secretaría no debe verlo en ninguna).
    const list = await admin.json<any>('GET', 'invitations?per_page=100', 200)
    const mine = list.data.find((i: any) => i.user.email === revoked.email)

    await admin.json('DELETE', `invitations/${mine.public_id}`, 204)

    await page.goto('/administracion/invitaciones')

    const row = (status: string) =>
      page.getByRole('row', { name: new RegExp(user.email) }).filter({ hasText: status })

    await expect(row('Vigente')).toBeVisible()
    await page.getByRole('button', { name: `Revocar la invitación de ${user.email}` }).click()
    await page
      .getByRole('alertdialog')
      .getByRole('button', { name: `Revocar la invitación de ${user.email}` })
      .click()
    await expect(page.getByRole('status')).toContainText(`Invitación de ${user.email} revocada.`)
    await expect(row('Revocada')).toBeVisible()
    await expect(row('Vigente')).toHaveCount(0)

    await page.getByRole('button', { name: `Reenviar la invitación a ${user.email}` }).click()
    await page
      .getByRole('alertdialog')
      .getByRole('button', { name: `Reenviar la invitación a ${user.email}` })
      .click()
    await expect(page.getByRole('status')).toContainText(`Invitación enviada a ${user.email}.`)
    await expect(row('Vigente')).toBeVisible()
  })

  test('CA-E2ECI-26 (RN-CORE-61): secretaría ve la lista sin acciones y sus POST y DELETE dan 403', async () => {
    const page = secretariaPage
    const mine = await admin.json<any>('GET', 'invitations?per_page=100', 200)
    const vigente = mine.data.find(
      (i: any) => i.status === 'vigente' && i.user.email.includes(`-${label}-inv@`),
    )
    const revocada = mine.data.find(
      (i: any) => i.status === 'revocada' && i.user.email.includes(`-${label}-invrev@`),
    )

    expect(vigente, 'invitación vigente del test anterior').toBeTruthy()
    expect(revocada, 'invitación revocada del test anterior').toBeTruthy()

    await page.goto('/administracion/invitaciones')
    await expect(page.getByRole('heading', { level: 1, name: 'Invitaciones' })).toBeVisible()
    await expect(
      page
        .getByRole('row', { name: new RegExp(vigente.user.email) })
        .filter({ hasText: 'Vigente' }),
    ).toBeVisible()
    await expect(page.getByRole('row', { name: new RegExp(revocada.user.email) })).toBeVisible()
    await expect(page.getByRole('button', { name: /^Revocar/ })).toHaveCount(0)
    await expect(page.getByRole('button', { name: /^Reenviar/ })).toHaveCount(0)

    expect((await secretaria.send('GET', 'invitations')).status()).toBe(200)

    const reissue = await secretaria.send('POST', `users/${vigente.user.public_id}/invitations`, {})

    expect(reissue.status(), await reissue.text()).toBe(403)

    const revoke = await secretaria.send('DELETE', `invitations/${vigente.public_id}`)

    expect(revoke.status(), await revoke.text()).toBe(403)

    const after = await admin.json<any>('GET', 'invitations?per_page=100', 200)

    expect(after.data.find((i: any) => i.public_id === vigente.public_id).status).toBe('vigente')
  })

  test('CA-E2ECI-26b (RN-CORE-64): el administrador retira un rol desde la ficha, con confirmación, y deja de tenerlo', async () => {
    const removed = await createRole(`Rol retirado ${label}`, {
      code: 'auditoria.leer',
      effect: 'allow',
      scope: 'propios',
    })
    const kept = await createRole(`Rol conservado ${label}`, {
      code: 'usuario.leer',
      effect: 'allow',
      scope: 'todos',
    })
    const user = await createUser('ret', { roleIds: [removed.id, kept.id] })
    const page = adminPage

    await page.goto(`/administracion/usuarios/${user.id}`)

    const region = page.getByRole('region', { name: 'Roles' })
    const assigned = region.locator('[data-slot="user-roles"]')

    await expect(assigned).toContainText(removed.name)
    await expect(assigned).toContainText(kept.name)

    await region.getByRole('checkbox', { name: removed.name }).uncheck()
    await region.getByRole('button', { name: 'Guardar roles' }).click()

    // Confirmación obligatoria: hasta confirmar no se envía nada.
    const dialog = page.getByRole('alertdialog')

    await expect(dialog).toContainText(removed.name)
    expect((await admin.json<any>('GET', `users/${user.id}/roles`, 200)).data).toHaveLength(2)
    await dialog.getByRole('button', { name: `Guardar los roles de ${user.name}` }).click()

    await expect(page.getByRole('status')).toContainText('Roles guardados.')
    await expect(assigned).not.toContainText(removed.name)
    await expect(assigned).toContainText(kept.name)

    const roles = (await admin.json<any>('GET', `users/${user.id}/roles`, 200)).data as any[]

    expect(roles.map((r) => r.public_id)).toEqual([kept.id])

    // Los permisos efectivos dejan de incluir lo que solo concedía el rol retirado.
    await page.getByRole('link', { name: /^Ver permisos efectivos/ }).click()
    await expect(page.getByRole('row', { name: /^Usuarios Leer/ })).toContainText('Permitido')
    await expect(page.getByRole('row', { name: /^Auditoría Leer/ })).toHaveCount(0)
  })
})
