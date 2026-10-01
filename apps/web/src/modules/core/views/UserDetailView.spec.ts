/**
 * `docs/modulos/REQ-CORE/funcional.md §14.4.2`, `§14.18`: ficha de usuario
 * (1.9b) — `CA-CORE-210` (acciones por permiso), `-211` (cuenta propia y
 * `409` del servidor), `-216` (dados de baja), `-219` (`403` de `RPERM-013`
 * junto al selector de roles), `-220` (confirmación accesible), `-221`
 * (mensaje de resultado sin token) y `-263` (`404`/`403`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'

const getUser = vi.fn()
const deleteUser = vi.fn()
const restoreUser = vi.fn()
const updateUserStatus = vi.fn()
const issueInvitation = vi.fn()
const listRoles = vi.fn()
const listUserRoles = vi.fn()
const replaceUserRoles = vi.fn()

vi.mock('../api', () => ({
  getUser: (...args: unknown[]) => getUser(...args),
  deleteUser: (...args: unknown[]) => deleteUser(...args),
  restoreUser: (...args: unknown[]) => restoreUser(...args),
  updateUserStatus: (...args: unknown[]) => updateUserStatus(...args),
  issueInvitation: (...args: unknown[]) => issueInvitation(...args),
  listRoles: (...args: unknown[]) => listRoles(...args),
  listUserRoles: (...args: unknown[]) => listUserRoles(...args),
  replaceUserRoles: (...args: unknown[]) => replaceUserRoles(...args),
}))

vi.mock('@/session/useSession', async () => {
  const { shallowRef } = await import('vue')
  const user = shallowRef<{ public_id: string; permissions: string[] } | null>(null)

  return {
    useSession: () => ({ user }),
    __setSession: (permissions: string[], publicId = 'ME') => {
      user.value = { public_id: publicId, permissions }
    },
  }
})

const session = (await import('@/session/useSession')) as unknown as {
  __setSession: (permissions: string[], publicId?: string) => void
}
const { setFlash } = await import('../composables/useFlash')
const { default: UserDetailView } = await import('./UserDetailView.vue')

function user(overrides: Record<string, unknown> = {}) {
  return {
    public_id: 'U1',
    email: 'ana@example.com',
    status: 'activo',
    person: {
      public_id: 'P1',
      given_name: 'Ana',
      family_name_1: 'López',
      family_name_2: null,
      contact_email: null,
      contact_phone: '699111222',
      document_type: 'DNI',
      document_number: 'ZZ-DOC-1',
      birth_date: '1990-05-05',
      locale: 'es-ES',
    },
    roles: [{ public_id: 'R1', code: 'docente', name: 'Docente' }],
    email_verified_at: null,
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-09-01T10:00:00Z',
    deleted_at: null,
    ...overrides,
  }
}

function problem(status: number, body: Record<string, unknown> = {}, headers?: Headers): ApiError {
  return new ApiError(`HTTP ${status}`, status, { status, ...body }, headers)
}

const wrappers: VueWrapper[] = []

async function mountView(publicId = 'U1'): Promise<VueWrapper> {
  const stub = { template: '<div/>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/administracion/usuarios', name: 'core-users', component: stub },
      {
        path: '/administracion/usuarios/:publicId',
        name: 'core-user-detail',
        component: UserDetailView,
      },
      {
        path: '/administracion/usuarios/:publicId/editar',
        name: 'core-user-edit',
        component: stub,
      },
    ],
  })

  await router.push(`/administracion/usuarios/${publicId}`)
  await router.isReady()

  const wrapper = mount(UserDetailView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

function button(label: string): HTMLButtonElement | undefined {
  return [...document.body.querySelectorAll('button, a')].find(
    (candidate) => (candidate.textContent ?? '').trim() === label,
  ) as HTMLButtonElement | undefined
}

async function click(el: Element | undefined): Promise<void> {
  if (!el) {
    throw new Error('Control inexistente')
  }

  el.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

beforeEach(() => {
  setLocale('es')
  getUser.mockReset().mockResolvedValue(user())
  deleteUser.mockReset().mockResolvedValue(undefined)
  restoreUser.mockReset().mockResolvedValue(user({ status: 'inactivo' }))
  updateUserStatus.mockReset().mockResolvedValue(user({ status: 'inactivo' }))
  issueInvitation
    .mockReset()
    .mockResolvedValue({ public_id: 'I1', expires_at: '2026-12-31T10:00:00Z' })
  listRoles.mockReset().mockResolvedValue({
    data: [
      { public_id: 'R1', code: 'docente', name: 'Docente' },
      { public_id: 'R2', code: 'secretaria', name: 'Secretaría' },
    ],
    meta: { current_page: 1, per_page: 100, total: 2, last_page: 1 },
  })
  listUserRoles
    .mockReset()
    .mockResolvedValue({ data: [{ public_id: 'R1', code: 'docente', name: 'Docente' }] })
  replaceUserRoles
    .mockReset()
    .mockResolvedValue({ data: [{ public_id: 'R1', code: 'docente', name: 'Docente' }] })
  session.__setSession(['usuario.leer'])
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CORE-210 (RN-CORE-61): acciones por permiso, nunca por rol', () => {
  it('con solo usuario.leer no hay ningún control de editar, estado, baja, restaurar ni invitar', async () => {
    getUser.mockResolvedValue(user({ status: 'pendiente' }))
    await mountView()

    for (const label of [
      'Editar',
      'Activar',
      'Desactivar',
      'Dar de baja',
      'Restaurar',
      'Enviar invitación',
    ]) {
      expect(button(label), label).toBeUndefined()
    }

    // RN-CORE-62: sin los permisos, ni GET /users/{id}/roles ni GET /roles.
    expect(listUserRoles).not.toHaveBeenCalled()
    expect(listRoles).not.toHaveBeenCalled()
    expect(getUser).toHaveBeenCalledWith('U1', { include_deleted: false })
  })

  it('con cada permiso aparece su acción y solo la que corresponde al estado', async () => {
    session.__setSession([
      'usuario.leer',
      'usuario.actualizar',
      'usuario.eliminar',
      'invitacion.crear',
    ])
    getUser.mockResolvedValue(user({ status: 'pendiente' }))
    await mountView()

    expect(button('Editar')).toBeDefined()
    expect(button('Dar de baja')).toBeDefined()
    expect(button('Enviar invitación')).toBeDefined()
    // Un pendiente solo sale por canje (RN-CORE-04): ni activar ni desactivar.
    expect(button('Activar')).toBeUndefined()
    expect(button('Desactivar')).toBeUndefined()
    expect(button('Restaurar')).toBeUndefined()
  })
})

describe('CA-CORE-211 (RN-CORE-61): cuenta propia y 409 del servidor', () => {
  it('en la ficha propia estado, baja y roles están deshabilitados con la explicación', async () => {
    session.__setSession(
      [
        'usuario.leer',
        'usuario.actualizar',
        'usuario.eliminar',
        'rol.leer',
        'asignacion_rol.crear',
        'asignacion_rol.leer',
      ],
      'U1',
    )
    await mountView()

    expect(button('Desactivar')!.disabled).toBe(true)
    expect(button('Dar de baja')!.disabled).toBe(true)
    expect(button('Guardar roles')!.disabled).toBe(true)
    expect(document.body.textContent).toContain('No puedes modificar tu propia cuenta desde aquí.')
  })

  it('un 409 del servidor se muestra con role=alert y la ficha no cambia', async () => {
    session.__setSession(['usuario.leer', 'usuario.eliminar'])
    deleteUser.mockRejectedValue(
      problem(409, {
        detail: 'Debe existir al menos un Administrador de Centro activo en todo momento.',
      }),
    )
    await mountView()

    await click(button('Dar de baja'))
    await click(
      [...document.body.querySelectorAll('button')].find(
        (candidate) => candidate.textContent?.trim() === 'Dar de baja a Ana López',
      ),
    )

    const alert = document.body.querySelector('p[role="alert"]')

    expect(alert?.textContent).toContain('Debe existir al menos un Administrador de Centro activo')
    expect(document.body.textContent).toContain('Activo')
    expect(getUser).toHaveBeenCalledTimes(1)
  })
})

describe('CA-CORE-216 (RN-CORE-68): usuarios dados de baja', () => {
  it('con usuario.eliminar la ficha se pide con include_deleted=true y ofrece «Restaurar»', async () => {
    session.__setSession(['usuario.leer', 'usuario.eliminar'])
    getUser.mockResolvedValue(user({ deleted_at: '2026-09-20T10:00:00Z', status: 'inactivo' }))
    await mountView()

    expect(getUser).toHaveBeenCalledWith('U1', { include_deleted: true })
    expect(button('Restaurar')).toBeDefined()
    expect(button('Dar de baja')).toBeUndefined()
    expect(document.body.textContent).toContain('Dado de baja')
  })

  it('restaurar no pide confirmación, avisa de que queda inactivo y ofrece «Activar»', async () => {
    session.__setSession(['usuario.leer', 'usuario.eliminar', 'usuario.actualizar'])
    getUser.mockResolvedValue(user({ deleted_at: '2026-09-20T10:00:00Z', status: 'inactivo' }))
    await mountView()

    await click(button('Restaurar'))

    expect(restoreUser).toHaveBeenCalledWith('U1')
    expect(document.body.querySelector('[role="status"]')?.textContent).toContain('Queda inactivo')
    expect(button('Activar')).toBeDefined()
  })
})

describe('CA-CORE-220 (RN-CORE-64): confirmación accesible', () => {
  it('no sale ninguna petición hasta confirmar; Esc cancela y devuelve el foco; confirmar envía un único DELETE', async () => {
    session.__setSession(['usuario.leer', 'usuario.eliminar'])
    await mountView()

    const trigger = button('Dar de baja')!

    trigger.focus()
    await click(trigger)

    const dialog = document.body.querySelector('[role="alertdialog"]')!

    expect(dialog).not.toBeNull()
    expect(dialog.textContent).toContain('Dar de baja a Ana López')
    expect(dialog.contains(document.activeElement)).toBe(true)
    expect(deleteUser).not.toHaveBeenCalled()

    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()

    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
    expect(deleteUser).not.toHaveBeenCalled()
    expect(document.activeElement).toBe(trigger)

    await click(trigger)
    await click(
      [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
        (candidate) => candidate.textContent?.trim() === 'Dar de baja a Ana López',
      ),
    )

    expect(deleteUser).toHaveBeenCalledTimes(1)
    expect(deleteUser).toHaveBeenCalledWith('U1')
  })

  it('desactivar pide confirmación; activar no', async () => {
    session.__setSession(['usuario.leer', 'usuario.actualizar'])
    await mountView()

    await click(button('Desactivar'))

    expect(updateUserStatus).not.toHaveBeenCalled()
    expect(document.body.querySelector('[role="alertdialog"]')).not.toBeNull()

    await click(
      [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
        (candidate) => candidate.textContent?.trim() === 'Desactivar a Ana López',
      ),
    )

    expect(updateUserStatus).toHaveBeenCalledWith('U1', 'inactivo')

    await click(button('Activar'))

    expect(updateUserStatus).toHaveBeenLastCalledWith('U1', 'activo')
    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
  })

  it('reenviar la invitación pide confirmación y muestra la caducidad; 429 muestra los segundos', async () => {
    session.__setSession(['usuario.leer', 'invitacion.crear'])
    getUser.mockResolvedValue(user({ status: 'pendiente' }))
    await mountView()

    await click(button('Enviar invitación'))
    expect(issueInvitation).not.toHaveBeenCalled()

    const confirm = () =>
      [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
        (candidate) => candidate.textContent?.trim() === 'Enviar invitación a Ana López',
      )

    await click(confirm())

    expect(issueInvitation).toHaveBeenCalledWith('U1')
    expect(document.body.querySelector('[role="status"]')?.textContent).toContain(
      'Invitación enviada',
    )

    issueInvitation.mockRejectedValue(problem(429, {}, new Headers({ 'Retry-After': '120' })))
    await click(button('Enviar invitación'))
    await click(confirm())

    expect(document.body.querySelector('p[role="alert"]')?.textContent).toContain('120')
  })
})

describe('CA-CORE-221 (RN-CORE-66, RN-CORE-19): resultado de una escritura', () => {
  it('el mensaje pendiente aparece con role=status y no hay ningún token en el documento ni en el almacenamiento', async () => {
    setFlash({ key: 'core.users.flash.createdInvited', params: { date: '31/12/2026' } })
    await mountView()

    const status = document.body.querySelector('[role="status"]')

    expect(status?.textContent).toContain(
      'Usuario creado e invitación enviada. Caduca el 31/12/2026.',
    )
    expect(document.body.textContent).not.toMatch(/token/i)
    expect(JSON.stringify({ ...window.localStorage })).not.toMatch(/token/i)
    expect(JSON.stringify({ ...window.sessionStorage })).not.toMatch(/token/i)
  })

  it('el mensaje se consume una sola vez', async () => {
    setFlash({ key: 'core.users.flash.created' })
    await mountView()
    wrappers.pop()!.unmount()
    await mountView()

    expect(document.body.querySelector('[role="status"]')).toBeNull()
  })
})

describe('CA-CORE-263 (CA-CORE-070, CA-CORE-073): 404 y 403 de la ficha', () => {
  it('un 404 pinta «no encontrado» dentro de la vista', async () => {
    getUser.mockRejectedValue(problem(404))
    await mountView('OTRO-CENTRO')

    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain('no encontr')
  })

  it('un 403 pinta «sin acceso»', async () => {
    getUser.mockRejectedValue(problem(403))
    await mountView()

    expect(document.body.querySelector('[role="alert"]')?.textContent?.toLowerCase()).toContain(
      'acceso',
    )
  })
})

describe('OPEN-CORE-43 (A), CA-CORE-219: roles en la ficha', () => {
  const MANAGE = ['usuario.leer', 'rol.leer', 'asignacion_rol.crear', 'asignacion_rol.leer']

  it('con asignacion_rol.leer se pide GET /users/{id}/roles y con rol.leer y asignacion_rol.crear, GET /roles', async () => {
    session.__setSession(MANAGE)
    await mountView()

    expect(listUserRoles).toHaveBeenCalledWith('U1')
    expect(listRoles).toHaveBeenCalledWith({ per_page: 100 })
    expect(document.body.querySelectorAll('input[type="checkbox"]')).toHaveLength(2)
  })

  it('añadir un rol guarda con PUT sin confirmar; quitarlo pide confirmación', async () => {
    session.__setSession(MANAGE)
    await mountView()

    const boxes = [...document.body.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')]

    boxes[1]!.checked = true
    boxes[1]!.dispatchEvent(new Event('change', { bubbles: true }))
    await click(button('Guardar roles'))

    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
    expect(replaceUserRoles).toHaveBeenCalledWith('U1', ['R1', 'R2'])

    replaceUserRoles.mockClear()
    boxes[0]!.checked = false
    boxes[0]!.dispatchEvent(new Event('change', { bubbles: true }))
    await click(button('Guardar roles'))

    expect(replaceUserRoles).not.toHaveBeenCalled()
    expect(document.body.querySelector('[role="alertdialog"]')?.textContent).toContain('Docente')
  })

  it('un 403 de RPERM-013 muestra el detail del servidor junto al campo de roles', async () => {
    session.__setSession(MANAGE)
    replaceUserRoles.mockRejectedValue(
      problem(403, { detail: 'No puedes asignar un rol que concede permisos que tú no tienes.' }),
    )
    await mountView()

    const boxes = [...document.body.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')]

    boxes[1]!.checked = true
    boxes[1]!.dispatchEvent(new Event('change', { bubbles: true }))
    await click(button('Guardar roles'))

    const error = document.body.querySelector('#user-roles-error')

    expect(error?.textContent).toContain('No puedes asignar un rol que concede permisos')
    expect(error?.getAttribute('role')).toBe('alert')
  })
})
