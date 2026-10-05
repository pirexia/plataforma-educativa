/**
 * `docs/modulos/REQ-PERM/funcional.md §20.8`, `§20.17.2`-`§20.17.4`: editor de
 * concesiones de un rol — `CA-PERM-103` (permisos que faltan, sin peticiones a
 * ciegas), `CA-PERM-108` (cuerpo del `PUT`, `deny` con `scope: todos`),
 * `CA-PERM-109`/`-110` (ámbitos y motivos, issue #170), `CA-PERM-111` (`403` y
 * `422` por celda), `CA-PERM-112` (confirmación con resumen), `CA-PERM-113`
 * (concurrencia), `CA-PERM-114` (inercia), `CA-PERM-115` (salir con cambios),
 * `CA-PERM-116` (el estado de edición no se pierde ni se guarda en el navegador),
 * `CA-PERM-117` (rol del sistema), `CA-PERM-136` (estructura de la matriz en el
 * editor) y `CA-PERM-137` (`409 administration_capacity_lost`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { RouterView, createMemoryHistory, createRouter, type Router } from 'vue-router'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { i18n, setLocale } from '@/i18n'

const getRole = vi.fn()
const listPermissions = vi.fn()
const getMyEffectivePermissions = vi.fn()
const listModules = vi.fn()
const replaceRolePermissions = vi.fn()
const reloadSession = vi.fn()

vi.mock('../api', () => ({
  getRole: (...args: unknown[]) => getRole(...args),
  listPermissions: (...args: unknown[]) => listPermissions(...args),
  getMyEffectivePermissions: (...args: unknown[]) => getMyEffectivePermissions(...args),
  listModules: (...args: unknown[]) => listModules(...args),
  replaceRolePermissions: (...args: unknown[]) => replaceRolePermissions(...args),
}))

vi.mock('@/session/useSession', async () => {
  const { shallowRef } = await import('vue')
  const user = shallowRef<{
    public_id: string
    permissions: string[]
    roles: { public_id: string; code: string; name: string }[]
  } | null>(null)

  return {
    useSession: () => ({ user }),
    reloadSession: (...args: unknown[]) => reloadSession(...args),
    __setUser: (permissions: string[], roles: string[] = []) => {
      user.value = {
        public_id: 'ME',
        permissions,
        roles: roles.map((id) => ({ public_id: id, code: id.toLowerCase(), name: id })),
      }
    },
  }
})

const session = (await import('@/session/useSession')) as unknown as {
  __setUser: (permissions: string[], roles?: string[]) => void
}
const { default: RolePermissionsView } = await import('./RolePermissionsView.vue')

const ALL = ['rol.leer', 'rol.actualizar', 'permiso.leer']

// --- Datos de prueba ---------------------------------------------------------

function permission(
  code: string,
  moduleCode: string,
  label: string,
  extra: Record<string, unknown> = {},
) {
  const [resource = code, action = 'leer'] = code.split('.')

  return {
    code,
    resource,
    action,
    module_code: moduleCode,
    is_special_category: false,
    applicable_scopes: ['todos'],
    grantable_scopes: ['todos'],
    resource_label: label,
    retired_at: null,
    ...extra,
  }
}

function catalog() {
  return {
    data: [
      permission('auditoria.leer', 'core', 'Auditoría', {
        applicable_scopes: ['todos', 'propios', 'grupo'],
        grantable_scopes: ['todos', 'propios'],
      }),
      permission('usuario.leer', 'core', 'Usuarios'),
      permission('usuario.eliminar', 'core', 'Usuarios'),
      permission('usuario.crear', 'core', 'Usuarios'),
      permission('salud.leer', 'core', 'Salud', { is_special_category: true }),
      permission('mfa.leer', 'auth', 'MFA'),
    ],
  }
}

function effective(code: string, extra: Record<string, unknown> = {}) {
  const [resource = code, action = 'leer'] = code.split('.')

  return {
    code,
    resource,
    action,
    module_code: 'core',
    is_special_category: false,
    decision: 'permitido',
    scopes: [],
    unrestricted: true,
    sources: [],
    ...extra,
  }
}

/** Lo que posee el solicitante: casi todo sin restricción, salvo los casos de #170. */
function mine(overrides: Record<string, Record<string, unknown>> = {}) {
  const base: Record<string, Record<string, unknown>> = {
    'auditoria.leer': { scopes: ['propios'], unrestricted: false },
    'usuario.crear': {
      decision: 'denegado',
      unrestricted: false,
      sources: [
        {
          role: { public_id: 'RX', code: 'rx', name: 'Cuenta restringida' },
          effect: 'deny',
          scope: 'todos',
          inert: false,
          inert_reason: null,
        },
      ],
    },
    'salud.leer': {
      decision: 'denegado',
      unrestricted: false,
      sources: [
        {
          role: { public_id: 'RY', code: 'ry', name: 'Auxiliar' },
          effect: 'allow',
          scope: 'todos',
          inert: true,
          inert_reason: 'inerte_datos_especiales',
        },
      ],
    },
    ...overrides,
  }

  return {
    data: catalog().data.map((entry) => effective(entry.code, base[entry.code] ?? {})),
    meta: { subject: { public_id: 'ME', display_name: 'Ana' }, roles: [], computed_at: 'x' },
  }
}

function grant(code: string, effect: 'allow' | 'deny', scope: string) {
  const [resource = code, action = 'leer'] = code.split('.')

  return { code, resource, action, effect, scope }
}

function role(overrides: Record<string, unknown> = {}) {
  return {
    public_id: 'R1',
    code: 'coordinacion',
    name: 'Coordinación pastoral',
    is_system: false,
    mfa_required: false,
    special_data_access: false,
    users_count: 7,
    permissions: [
      grant('auditoria.leer', 'allow', 'propios'),
      grant('usuario.eliminar', 'deny', 'todos'),
    ],
    ...overrides,
  }
}

// --- Montaje y utilidades ----------------------------------------------------------

const wrappers: VueWrapper[] = []
let router: Router

async function mountEditor(): Promise<VueWrapper> {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/administracion/roles', name: 'core-roles', component: { template: '<div/>' } },
      {
        path: '/administracion/roles/:publicId/permisos',
        name: 'core-role-permissions',
        component: RolePermissionsView,
      },
      { path: '/otra', name: 'otra', component: { template: '<div id="otra"/>' } },
    ],
  })

  await router.push('/administracion/roles/R1/permisos')
  await router.isReady()

  const wrapper = mount(defineComponent({ render: () => h(RouterView) }), {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

async function flush(): Promise<void> {
  await flushPromises()
}

function cellButton(code: string): HTMLButtonElement {
  const found = document.body.querySelector(`button[data-code="${code}"]`)

  if (!found) {
    throw new Error(`No hay celda ${code}`)
  }

  return found as HTMLButtonElement
}

async function openCell(code: string): Promise<void> {
  cellButton(code).focus()
  cellButton(code).click()
  await flush()
}

function dialog(): Element | null {
  return document.body.querySelector('[role="dialog"]')
}

function radio(code: string, value: string): HTMLElement {
  return document.getElementById(`role-cell-${code.replace(/[^a-zA-Z0-9_-]/g, '-')}-${value}`)!
}

function buttonIn(root: ParentNode, label: string): HTMLButtonElement {
  const found = [...root.querySelectorAll('button')].find((b) => b.textContent?.trim() === label)

  if (!found) {
    throw new Error(`No hay botón «${label}»`)
  }

  return found as HTMLButtonElement
}

/** Abre una celda, elige un estado (y ámbito) y pulsa «Aplicar». */
async function setCell(code: string, state: 'none' | 'allow' | 'deny'): Promise<void> {
  await openCell(code)
  radio(code, state).click()
  await flush()
  buttonIn(dialog()!, 'Aplicar').click()
  await flush()
}

async function chooseScope(code: string, scopeLabel: string): Promise<void> {
  await openCell(code)
  radio(code, 'allow').click()
  await flush()
  document.body
    .querySelector('[role="combobox"]')!
    .dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }))
  await flush()

  const option = [...document.body.querySelectorAll('[role="option"]')].find((o) =>
    o.textContent?.startsWith(scopeLabel),
  )!

  option.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerType: 'mouse' }))
  option.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flush()
  buttonIn(dialog()!, 'Aplicar').click()
  await flush()
}

function saveButton(wrapper: VueWrapper) {
  return wrapper.findAll('button').find((b) => b.text() === 'Guardar')!
}

function alertDialog(): Element | null {
  return document.body.querySelector('[role="alertdialog"]')
}

async function confirmSave(wrapper: VueWrapper): Promise<void> {
  saveButton(wrapper).element.focus()
  await saveButton(wrapper).trigger('click')
  await flush()
  buttonIn(alertDialog()!, 'Guardar concesiones de «Coordinación pastoral»').click()
  await flush()
}

function problem(status: number, body: Record<string, unknown>): ApiError {
  return new ApiError(`HTTP ${status}`, status, body)
}

function changesText(wrapper: VueWrapper): string {
  return wrapper.get('[data-slot="role-editor-changes"]').text()
}

beforeEach(() => {
  setLocale('es')
  getRole.mockReset().mockResolvedValue(role())
  listPermissions.mockReset().mockResolvedValue(catalog())
  getMyEffectivePermissions.mockReset().mockResolvedValue(mine())
  listModules.mockReset().mockResolvedValue({
    data: [
      { module_code: 'core', name: 'Núcleo', enabled: true },
      { module_code: 'auth', name: 'Autenticación', enabled: false },
    ],
  })
  replaceRolePermissions.mockReset().mockImplementation((_id: string, entries: unknown[]) =>
    Promise.resolve(
      role({
        permissions: (entries as { code: string; effect: 'allow' | 'deny'; scope: string }[]).map(
          (entry) => grant(entry.code, entry.effect, entry.scope),
        ),
      }),
    ),
  )
  reloadSession.mockReset().mockResolvedValue(undefined)
  session.__setUser(ALL)
  window.localStorage.clear()
  window.sessionStorage.clear()
  Object.assign(Element.prototype, {
    hasPointerCapture: () => false,
    setPointerCapture: () => undefined,
    releasePointerCapture: () => undefined,
    scrollIntoView: () => undefined,
  })
  globalThis.ResizeObserver ??= class {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  }
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

// --- Tests --------------------------------------------------------------------------

describe('CA-PERM-103 (RN-PERM-25, RN-CORE-62): permisos de lectura que faltan', () => {
  it('con rol.actualizar y sin permiso.leer no se pide GET /permissions ni se envía nada; estado «necesitas además» con el permiso que falta', async () => {
    session.__setUser(['rol.actualizar', 'rol.leer'])
    const wrapper = await mountEditor()

    expect(listPermissions).not.toHaveBeenCalled()
    expect(getRole).not.toHaveBeenCalled()
    expect(replaceRolePermissions).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('necesitas además el permiso permiso.leer')
    expect(wrapper.find('[data-slot="role-permission-matrix"]').exists()).toBe(false)
  })

  it('sin rol.leer ni permiso.leer se nombran los dos y no sale ninguna petición', async () => {
    session.__setUser(['rol.actualizar'])
    const wrapper = await mountEditor()

    expect(wrapper.text()).toContain('permiso rol.leer')
    expect(wrapper.text()).toContain('permiso permiso.leer')
    expect(getMyEffectivePermissions).not.toHaveBeenCalled()
  })
})

describe('CA-PERM-136 (§20.8.1, RN-CORE-44): estructura de la matriz en el editor', () => {
  it('una matriz por módulo con caption, columnas = acciones presentes en orden de RPERM-003, recursos th scope="row", celdas inexistentes vacías, sin role="grid"', async () => {
    const wrapper = await mountEditor()

    const tables = wrapper.findAll('table')

    expect(tables).toHaveLength(2)
    expect(tables.map((t) => t.get('caption').text())).toEqual([
      'Concesiones de core para Coordinación pastoral',
      'Concesiones de auth para Coordinación pastoral',
    ])
    expect(tables[0]!.findAll('thead th').map((th) => th.text())).toEqual([
      'Recurso',
      'Crear',
      'Leer',
      'Eliminar',
    ])
    expect(tables[1]!.findAll('thead th').map((th) => th.text())).toEqual(['Recurso', 'Leer'])
    expect(wrapper.findAll('tbody th').every((th) => th.attributes('scope') === 'row')).toBe(true)
    expect(wrapper.find('[role="grid"]').exists()).toBe(false)

    // Auditoría solo tiene «leer»: crear y eliminar llevan el valor vacío común y ningún control.
    const auditoria = tables[0]!.findAll('tbody tr')[0]!

    expect(auditoria.find('th').text()).toBe('Auditoría')
    expect(auditoria.findAll('td')[0]!.find('[data-slot="data-table-empty-value"]').exists()).toBe(
      true,
    )
    expect(auditoria.findAll('td')[0]!.find('button').exists()).toBe(false)
    expect(auditoria.findAll('td')[1]!.findAll('button')).toHaveLength(1)

    // Cada celda con permiso: un único botón con recurso, acción y estado en su nombre.
    expect(cellButton('auditoria.leer').getAttribute('aria-label')).toBe(
      'Auditoría · Leer: Permitir · Propios. Modificar',
    )
    expect(cellButton('usuario.eliminar').getAttribute('aria-label')).toBe(
      'Usuarios · Eliminar: Denegar. Modificar',
    )
    // Sin conceder + «Solo retirar o denegar» (usuario.crear está vetado para el solicitante).
    expect(cellButton('usuario.leer').getAttribute('aria-label')).toBe(
      'Usuarios · Leer: Sin conceder. Modificar',
    )
  })

  it('la etiqueta de categoría especial sale en el recurso; sin resource_label, el código en crudo', async () => {
    listPermissions.mockResolvedValue({
      data: [
        ...catalog().data,
        {
          code: 'nuevo.leer',
          resource: 'nuevo',
          action: 'leer',
          module_code: 'core',
          is_special_category: false,
          applicable_scopes: ['todos'],
          grantable_scopes: ['todos'],
          retired_at: null,
        },
      ],
    })
    const wrapper = await mountEditor()
    const rows = wrapper.findAll('tbody th').map((th) => th.text())

    expect(rows.some((text) => text.includes('Salud') && text.includes('Categoría especial'))).toBe(
      true,
    )
    expect(rows).toContain('nuevo')
  })
})

describe('CA-PERM-108 (RN-PERM-32, RN-PERM-34): cuerpo del PUT', () => {
  it('lo no tocado se envía idéntico, lo nuevo en «Permitir», lo pasado a «Sin conceder» se omite y «Denegar» lleva scope todos', async () => {
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    await setCell('usuario.eliminar', 'none')
    await setCell('usuario.crear', 'deny')

    expect(changesText(wrapper)).toBe('3 cambios sin guardar')

    await confirmSave(wrapper)

    expect(replaceRolePermissions).toHaveBeenCalledExactlyOnceWith('R1', [
      { code: 'auditoria.leer', effect: 'allow', scope: 'propios' },
      { code: 'usuario.leer', effect: 'allow', scope: 'todos' },
      { code: 'usuario.crear', effect: 'deny', scope: 'todos' },
    ])
  })

  it('«Guardar» está deshabilitado sin cambios y no sale ninguna petición (CA-PERM-112)', async () => {
    const wrapper = await mountEditor()

    expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
    expect(changesText(wrapper)).toBe('Sin cambios sin guardar')

    saveButton(wrapper).element.click()
    await flush()

    expect(getRole).toHaveBeenCalledTimes(1)
    expect(replaceRolePermissions).not.toHaveBeenCalled()
    expect(alertDialog()).toBeNull()
  })

  it('con 200, la instantánea pasa a ser la respuesta (sin otra petición), desaparecen los marcadores y hay un mensaje role="status"', async () => {
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')

    expect(cellButton('usuario.leer').textContent).toContain('Modificado')

    await confirmSave(wrapper)

    expect(wrapper.get('[role="status"]').text()).toBe('Concesiones guardadas.')
    expect(changesText(wrapper)).toBe('Sin cambios sin guardar')
    expect(cellButton('usuario.leer').textContent).not.toContain('Modificado')
    expect(cellButton('usuario.leer').getAttribute('aria-label')).toBe(
      'Usuarios · Leer: Permitir · Todos. Modificar',
    )
    expect(getRole).toHaveBeenCalledTimes(2) // carga + comprobación de concurrencia; ninguna tras el 200
    expect(reloadSession).not.toHaveBeenCalled()
  })

  it('si el solicitante es titular del rol, tras guardar recarga /me y sus permisos efectivos', async () => {
    session.__setUser(ALL, ['R1'])
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    await confirmSave(wrapper)

    expect(reloadSession).toHaveBeenCalledTimes(1)
    expect(getMyEffectivePermissions).toHaveBeenCalledTimes(2)
  })
})

describe('CA-PERM-109 / CA-PERM-110 (RN-PERM-33, issue #170): ámbitos y motivos en el editor', () => {
  it('la leyenda de la regla está en el documento con los dos motivos', async () => {
    const wrapper = await mountEditor()
    const legend = wrapper.get('[data-slot="role-editor-legend"]')

    expect(legend.text()).toContain(
      'No puedes conceder un permiso, ni con un ámbito, que tú no tengas. Siempre puedes retirarlo o denegarlo.',
    )
    expect(legend.text()).toContain('«No lo tienes»')
    expect(legend.text()).toContain('«Aún no existe»')
  })

  it('auditoria.leer guardado en propios: propios (actual), todos «no lo tienes» y grupo «aún no existe», con el motivo en el nombre de la opción', async () => {
    await mountEditor()
    await openCell('auditoria.leer')
    document.body
      .querySelector('[role="combobox"]')!
      .dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }))
    await flush()

    const texts = [...document.body.querySelectorAll('[role="option"]')].map((o) =>
      o.textContent!.trim(),
    )

    expect(texts).toEqual([
      'Todos — no puedes concederlo: tú no tienes este permiso con este ámbito',
      'Propios (actual)',
      'Grupo — todavía no se puede conceder: lo aportará un módulo que aún no está disponible',
    ])
  })

  it('un permiso vetado para el solicitante: «Permitir» deshabilitado, nota «lo tienes vetado» asociada al grupo y marca «Solo retirar o denegar» en la celda', async () => {
    await mountEditor()

    expect(cellButton('usuario.crear').textContent).toContain('Solo retirar o denegar')

    await openCell('usuario.crear')

    expect(radio('usuario.crear', 'allow').hasAttribute('data-disabled')).toBe(true)
    expect(radio('usuario.crear', 'deny').hasAttribute('data-disabled')).toBe(false)
    expect(radio('usuario.crear', 'none').hasAttribute('data-disabled')).toBe(false)

    const group = dialog()!.querySelector('[role="radiogroup"]')!
    const note = document.getElementById(group.getAttribute('aria-describedby')!.split(' ')[0]!)!

    expect(note.textContent).toContain(
      'Solo puedes denegar o dejar sin conceder este permiso: lo tienes vetado por una denegación',
    )
  })

  it('un permiso cuya única fuente es allow inerte: la nota dice que no surte efecto con el motivo traducido', async () => {
    await mountEditor()
    await openCell('salud.leer')

    expect(dialog()!.textContent).toContain(
      'Solo puedes denegar o dejar sin conceder este permiso: tu concesión no surte efecto (el rol no tiene acceso a datos de categoría especial)',
    )
  })

  it('un permiso que el solicitante no posee con ningún ámbito: nota «tú no lo tienes»', async () => {
    getMyEffectivePermissions.mockResolvedValue(
      mine({ 'usuario.leer': { decision: 'denegado', unrestricted: false, sources: [] } }),
    )
    await mountEditor()
    await openCell('usuario.leer')

    expect(dialog()!.textContent).toContain(
      'Solo puedes denegar o dejar sin conceder este permiso: tú no lo tienes',
    )
  })

  it('elegir otro ámbito disponible y volver al guardado antes de guardar deja la celda sin modificar', async () => {
    getMyEffectivePermissions.mockResolvedValue(mine({ 'auditoria.leer': { unrestricted: true } }))
    const wrapper = await mountEditor()

    await chooseScope('auditoria.leer', 'Todos')

    expect(changesText(wrapper)).toBe('1 cambio sin guardar')
    expect(cellButton('auditoria.leer').getAttribute('aria-label')).toContain('Permitir · Todos')

    await chooseScope('auditoria.leer', 'Propios')

    expect(changesText(wrapper)).toBe('Sin cambios sin guardar')
  })

  it('si GET /me/effective-permissions falla, el editor no se pinta con todo habilitado: error con «Reintentar»', async () => {
    getMyEffectivePermissions.mockRejectedValue(problem(500, {}))
    const wrapper = await mountEditor()

    expect(wrapper.find('[data-slot="role-permission-matrix"]').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)

    getMyEffectivePermissions.mockResolvedValue(mine())
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Reintentar')!
      .trigger('click')
    await flush()

    expect(wrapper.find('[data-slot="role-permission-matrix"]').exists()).toBe(true)
  })
})

describe('CA-PERM-111 (RN-PERM-36): errores del servidor al guardar', () => {
  it('un 403 cannot_grant_unheld_permission: detail con role="alert" y foco, error en la celda de params.code, y los cambios sin guardar siguen', async () => {
    replaceRolePermissions.mockRejectedValue(
      problem(403, {
        detail: 'No puedes conceder un permiso que no tienes.',
        errors: {
          grant: [
            {
              code: 'core.authorization.cannot_grant_unheld_permission',
              message: 'No tienes este permiso con este ámbito.',
              params: { code: 'usuario.leer', scope: 'todos' },
            },
          ],
        },
      }),
    )
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    await setCell('usuario.eliminar', 'none')
    await confirmSave(wrapper)

    const summary = wrapper.get('[data-slot="role-editor-summary"]')

    expect(summary.attributes('role')).toBe('alert')
    expect(summary.text()).toContain('No puedes conceder un permiso que no tienes.')
    expect(summary.text()).toContain('Permiso: Usuarios · Leer. Ámbito: Todos.')
    expect(document.activeElement).toBe(summary.element)
    expect(cellButton('usuario.leer').textContent).toContain('Error')
    expect(document.getElementById('matrix-cell-error-usuario.leer')!.textContent).toBe(
      'No tienes este permiso con este ámbito.',
    )
    expect(changesText(wrapper)).toBe('2 cambios sin guardar')

    await openCell('usuario.leer')

    expect(dialog()!.querySelector('[role="radiogroup"]')!.getAttribute('aria-invalid')).toBe(
      'true',
    )
  })

  it('el filtro de módulo se ajusta para que la celda con error sea visible', async () => {
    replaceRolePermissions.mockRejectedValue(
      problem(403, {
        detail: 'No puedes.',
        errors: {
          grant: [
            {
              code: 'core.authorization.cannot_grant_unheld_permission',
              message: 'No.',
              params: { code: 'usuario.leer', scope: 'todos' },
            },
          ],
        },
      }),
    )
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    await wrapper.get('#role-editor-module').setValue('auth')
    await flush()

    expect(document.body.querySelector('button[data-code="usuario.leer"]')).toBeNull()

    await confirmSave(wrapper)

    expect((wrapper.get('#role-editor-module').element as HTMLSelectElement).value).toBe('core')
    expect(document.body.querySelector('button[data-code="usuario.leer"]')).not.toBeNull()
  })

  it('un 422 con errors["permissions.1.scope"] lleva el mensaje a la celda del código que ocupaba la posición 1 del cuerpo', async () => {
    replaceRolePermissions.mockRejectedValue(
      problem(422, {
        errors: {
          'permissions.1.scope': [
            {
              code: 'core.validation.scope_resolver_missing',
              message: 'Ese ámbito aún no existe.',
            },
          ],
        },
      }),
    )
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    await confirmSave(wrapper)

    // Cuerpo: [0] auditoria.leer (idéntica), [1] usuario.leer, [2] usuario.eliminar (idéntica).
    expect(document.getElementById('matrix-cell-error-usuario.leer')!.textContent).toBe(
      'Ese ámbito aún no existe.',
    )
    expect(document.getElementById('matrix-cell-error-auditoria.leer')).toBeNull()
    expect(wrapper.get('[data-slot="role-editor-summary"]').attributes('role')).toBe('alert')
  })

  it('un 404 al guardar pinta «no encontrado» con un enlace al listado', async () => {
    replaceRolePermissions.mockRejectedValue(problem(404, {}))
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    await confirmSave(wrapper)

    expect(wrapper.text()).toContain('Página no encontrada')
    expect(
      wrapper
        .findAll('a')
        .find((a) => a.text() === 'Volver al listado de roles')!
        .attributes('href'),
    ).toBe('/administracion/roles')
  })

  it('un 5xx muestra el mensaje genérico y conserva los cambios', async () => {
    replaceRolePermissions.mockRejectedValue(problem(500, {}))
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    await confirmSave(wrapper)

    expect(wrapper.get('[data-slot="role-editor-summary"]').text()).toContain(
      'La acción no se ha podido completar. Inténtalo de nuevo.',
    )
    expect(changesText(wrapper)).toBe('1 cambio sin guardar')
  })

  it('editar una celda con error borra su error', async () => {
    replaceRolePermissions.mockRejectedValue(
      problem(422, {
        errors: { 'permissions.1.scope': [{ code: 'x', message: 'Mal ámbito.' }] },
      }),
    )
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    await confirmSave(wrapper)
    await setCell('usuario.leer', 'none')

    expect(document.getElementById('matrix-cell-error-usuario.leer')).toBeNull()
  })
})

describe('CA-PERM-137 (RN-PERM-36 punto 8, RN-PERM-47): el centro no pierde la administración', () => {
  it('un 409 administration_capacity_lost: detail con role="alert", etiquetas de los permisos, texto de cómo resolverlo, foco al resumen y cambios conservados', async () => {
    replaceRolePermissions.mockRejectedValue(
      problem(409, {
        type: 'urn:pge:error:conflict',
        detail: 'La escritura dejaría al centro sin administración completa.',
        errors: {
          administration_capacity: [
            {
              code: 'core.validation.administration_capacity_lost',
              message: 'Se perdería la capacidad de administración.',
              params: { codes: ['usuario.leer', 'codigo.desconocido'] },
            },
          ],
        },
      }),
    )
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    await confirmSave(wrapper)

    const summary = wrapper.get('[data-slot="role-editor-summary"]')

    expect(summary.attributes('role')).toBe('alert')
    expect(summary.text()).toContain('La escritura dejaría al centro sin administración completa.')
    expect(summary.text()).toContain('Usuarios · Leer')
    expect(summary.text()).toContain('codigo.desconocido')
    expect(summary.text()).toContain(
      'Si guardas esto, nadie del centro conservaría todos los permisos de administración. Concede primero esos permisos a otra persona.',
    )
    expect(document.activeElement).toBe(summary.element)
    expect(changesText(wrapper)).toBe('1 cambio sin guardar')
  })

  it('la interfaz no deshabilita de antemano ninguna celda por esta regla (RN-CORE-61)', async () => {
    await mountEditor()

    for (const code of ['usuario.leer', 'usuario.eliminar', 'auditoria.leer', 'mfa.leer']) {
      expect(cellButton(code).hasAttribute('disabled')).toBe(false)
    }
  })
})

describe('CA-PERM-112 (RN-PERM-36, RN-CORE-64): confirmación con resumen', () => {
  async function scenario() {
    getMyEffectivePermissions.mockResolvedValue(mine({ 'auditoria.leer': { unrestricted: true } }))
    session.__setUser(ALL, ['R1'])
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow') // concedido
    await setCell('mfa.leer', 'allow') // concedido
    await chooseScope('auditoria.leer', 'Todos') // ámbito cambiado
    await setCell('usuario.crear', 'deny') // denegado
    await setCell('usuario.eliminar', 'none') // retirado

    return wrapper
  }

  it('no sale el PUT hasta confirmar; el diálogo muestra los cuatro recuentos, la lista con etiquetas, los titulares y el aviso de titularidad', async () => {
    const wrapper = await scenario()

    saveButton(wrapper).element.focus()
    await saveButton(wrapper).trigger('click')
    await flush()

    const text = alertDialog()!.textContent!

    expect(replaceRolePermissions).not.toHaveBeenCalled()
    expect(text).toContain('Guardar las concesiones de «Coordinación pastoral»')
    expect(text).toContain('Concedidos: 2 · Ámbito cambiado: 1 · Denegados: 1 · Retirados: 1')
    expect(text).toContain('Afecta de inmediato a 7 titulares')
    expect(text).toContain('Eres titular de este rol')
    expect(text).toContain('Usuarios · Leer: Sin conceder → Permitir · Todos')
    expect(text).toContain('Auditoría · Leer: Permitir · Propios → Permitir · Todos')
    expect(text).toContain('Usuarios · Crear: Sin conceder → Denegar')
    expect(text).toContain('Usuarios · Eliminar: Denegar → Sin conceder')
    // Etiquetas, no códigos.
    expect(text).not.toContain('usuario.leer')
  })

  it('Esc cierra sin petición y devuelve el foco a «Guardar»', async () => {
    const wrapper = await scenario()
    const save = saveButton(wrapper).element as HTMLButtonElement

    save.focus()
    await saveButton(wrapper).trigger('click')
    await flush()

    alertDialog()!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flush()

    expect(alertDialog()).toBeNull()
    expect(replaceRolePermissions).not.toHaveBeenCalled()
    expect(document.activeElement).toBe(save)
  })

  it('sin ser titular no aparece el aviso de titularidad', async () => {
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    saveButton(wrapper).element.focus()
    await saveButton(wrapper).trigger('click')
    await flush()

    expect(alertDialog()!.textContent).not.toContain('Eres titular de este rol')
  })
})

describe('CA-PERM-113 (RN-PERM-37, OPEN-PERM-12 = A): concurrencia', () => {
  it('si el rol cambió desde que se abrió, no se abre la confirmación ni sale el PUT, y se avisa con la lista de códigos cambiados', async () => {
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    getRole.mockResolvedValue(
      role({
        permissions: [
          grant('auditoria.leer', 'allow', 'propios'),
          grant('usuario.eliminar', 'deny', 'todos'),
          grant('mfa.leer', 'allow', 'todos'),
        ],
      }),
    )
    saveButton(wrapper).element.focus()
    await saveButton(wrapper).trigger('click')
    await flush()

    const conflict = wrapper.get('[data-slot="role-editor-conflict"]')

    expect(alertDialog()).toBeNull()
    expect(replaceRolePermissions).not.toHaveBeenCalled()
    expect(conflict.text()).toContain(
      'Otra persona ha cambiado las concesiones de este rol desde que lo abriste',
    )
    expect(conflict.text()).toContain('MFA · Leer')
  })

  it('«Recargar» pide confirmación antes de descartar los cambios propios y, al confirmar, recarga el rol', async () => {
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    getRole.mockResolvedValue(role({ permissions: [grant('mfa.leer', 'allow', 'todos')] }))
    saveButton(wrapper).element.focus()
    await saveButton(wrapper).trigger('click')
    await flush()

    await wrapper.get('[data-slot="role-editor-conflict"] button').trigger('click')
    await flush()

    expect(alertDialog()!.textContent).toContain('Recargar y descartar tus cambios')
    expect(changesText(wrapper)).toBe('1 cambio sin guardar')

    buttonIn(alertDialog()!, 'Cancelar').click()
    await flush()

    expect(changesText(wrapper)).toBe('1 cambio sin guardar')

    await wrapper.get('[data-slot="role-editor-conflict"] button').trigger('click')
    await flush()
    buttonIn(alertDialog()!, 'Recargar y descartar').click()
    await flush()

    expect(changesText(wrapper)).toBe('Sin cambios sin guardar')
    expect(wrapper.find('[data-slot="role-editor-conflict"]').exists()).toBe(false)
    expect(cellButton('mfa.leer').getAttribute('aria-label')).toContain('Permitir · Todos')
  })
})

describe('CA-PERM-114 (RN-PERM-35, RN-PERM-10): inercia en la matriz', () => {
  beforeEach(() => {
    getMyEffectivePermissions.mockResolvedValue(
      mine({ 'salud.leer': { decision: 'permitido', unrestricted: true, sources: [] } }),
    )
  })

  it('un permiso de categoría especial en «Permitir» sobre un rol sin el atributo avisa en texto («Inerte» y el aviso completo) y no bloquea guardar', async () => {
    const wrapper = await mountEditor()

    await setCell('salud.leer', 'allow')

    expect(cellButton('salud.leer').textContent).toContain('Inerte')

    await openCell('salud.leer')

    expect(dialog()!.textContent).toContain(
      'Esta concesión no surtirá efecto: el rol no tiene acceso a datos de categoría especial',
    )

    buttonIn(dialog()!, 'Cancelar').click()
    await flush()
    await confirmSave(wrapper)

    expect(replaceRolePermissions).toHaveBeenCalledTimes(1)
  })

  it('con el atributo en el rol, el aviso no aparece', async () => {
    getRole.mockResolvedValue(role({ special_data_access: true }))
    await mountEditor()
    await setCell('salud.leer', 'allow')

    expect(cellButton('salud.leer').textContent).not.toContain('Inerte')

    await openCell('salud.leer')

    expect(dialog()!.textContent).not.toContain('no surtirá efecto')
  })

  it('con modulo.leer, la matriz de un módulo no contratado lo marca y las concesiones en «Permitir» salen inertes; sin él no se pide GET /modules', async () => {
    session.__setUser([...ALL, 'modulo.leer'])
    const wrapper = await mountEditor()
    const notices = wrapper.findAll('[data-slot="role-permission-matrix-notice"]')

    expect(notices).toHaveLength(1)
    expect(notices[0]!.text()).toBe(
      'Módulo no contratado: estas concesiones no surten efecto mientras no se contrate',
    )
    expect(wrapper.findAll('h3').map((h) => h.text())).toEqual(['Núcleo', 'Autenticación'])

    await setCell('mfa.leer', 'allow')

    expect(cellButton('mfa.leer').textContent).toContain('Inerte')

    await openCell('mfa.leer')

    expect(dialog()!.textContent).toContain('no surtirá efecto mientras no se contrate el módulo')
  })

  it('sin modulo.leer no se pide GET /modules ni se marca ningún módulo', async () => {
    const wrapper = await mountEditor()

    expect(listModules).not.toHaveBeenCalled()
    expect(wrapper.findAll('[data-slot="role-permission-matrix-notice"]')).toHaveLength(0)
  })
})

describe('CA-PERM-115 (RN-PERM-38, OPEN-PERM-15 = A): salir con cambios sin guardar', () => {
  it('beforeunload está registrado mientras haya cambios y retirado tras guardar', async () => {
    const add = vi.spyOn(window, 'addEventListener')
    const remove = vi.spyOn(window, 'removeEventListener')
    const wrapper = await mountEditor()

    const registered = () => add.mock.calls.filter(([type]) => type === 'beforeunload')

    expect(registered()).toHaveLength(0)

    await setCell('usuario.leer', 'allow')

    expect(registered()).toHaveLength(1)

    await confirmSave(wrapper)

    expect(
      remove.mock.calls.filter(
        ([type, listener]) => type === 'beforeunload' && listener === registered()[0]![1],
      ),
    ).not.toHaveLength(0)
  })

  it('al navegar a otra ruta con cambios aparece la confirmación; cancelar mantiene la ruta y los cambios; confirmar sale', async () => {
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    router.push('/otra').catch(() => undefined)
    await flush()

    expect(alertDialog()!.textContent).toContain(
      'Tienes 1 cambio sin guardar. Si sales, se perderá.',
    )
    expect(router.currentRoute.value.name).toBe('core-role-permissions')

    buttonIn(alertDialog()!, 'Seguir editando').click()
    await flush()

    expect(router.currentRoute.value.name).toBe('core-role-permissions')
    expect(changesText(wrapper)).toBe('1 cambio sin guardar')

    router.push('/otra').catch(() => undefined)
    await flush()
    buttonIn(alertDialog()!, 'Salir y descartar').click()
    await flush()

    expect(router.currentRoute.value.name).toBe('otra')
  })

  it('sin cambios se navega sin confirmación', async () => {
    await mountEditor()

    await router.push('/otra')
    await flush()

    expect(alertDialog()).toBeNull()
    expect(router.currentRoute.value.name).toBe('otra')
  })
})

describe('CA-PERM-116 (RN-PERM-32, RN-CORE-50, §20.8.1): el estado de edición vive en la vista', () => {
  it('cambiar de módulo, buscar y volver no pierde los cambios ni cambia el contador', async () => {
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')

    await wrapper.get('#role-editor-module').setValue('auth')
    await wrapper.get('#role-editor-search').setValue('mfa')
    await flush()
    await wrapper.get('#role-editor-module').setValue('')
    await wrapper.get('#role-editor-search').setValue('')
    await flush()

    expect(changesText(wrapper)).toBe('1 cambio sin guardar')
    expect(cellButton('usuario.leer').textContent).toContain('Modificado')
  })

  it('«Solo recursos con cambios sin guardar» deja solo las filas modificadas; la búsqueda no distingue tildes ni mayúsculas', async () => {
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    await wrapper.get('input[type="checkbox"]').setValue(true)
    await flush()

    expect(wrapper.findAll('tbody th').map((th) => th.text())).toEqual(['Usuarios'])

    await wrapper.get('input[type="checkbox"]').setValue(false)
    await wrapper.get('#role-editor-search').setValue('AUDITORIA')
    await flush()

    expect(wrapper.findAll('tbody th').map((th) => th.text())).toEqual(['Auditoría'])
  })

  it('sin filas que coincidan sale el estado vacío del filtrado', async () => {
    const wrapper = await mountEditor()

    await wrapper.get('#role-editor-search').setValue('zzzz')
    await flush()

    expect(wrapper.text()).toContain('Ningún recurso coincide')
    expect(wrapper.find('[data-slot="role-permission-matrix"]').exists()).toBe(false)
  })

  it('«Cancelar» o Esc en el panel dejan la celda sin cambios y el foco vuelve a su botón', async () => {
    await mountEditor()

    await openCell('usuario.leer')
    radio('usuario.leer', 'allow').click()
    await flush()
    buttonIn(dialog()!, 'Cancelar').click()
    await flush()

    expect(cellButton('usuario.leer').getAttribute('aria-label')).toContain('Sin conceder')
    expect(document.activeElement).toBe(cellButton('usuario.leer'))

    await openCell('usuario.leer')
    radio('usuario.leer', 'allow').click()
    await flush()
    dialog()!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flush()

    expect(cellButton('usuario.leer').getAttribute('aria-label')).toContain('Sin conceder')
    expect(document.activeElement).toBe(cellButton('usuario.leer'))
  })

  it('ningún almacenamiento del navegador contiene códigos de permiso ni el estado de edición', async () => {
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    await confirmSave(wrapper)
    await setCell('usuario.eliminar', 'none')

    for (const storage of [window.localStorage, window.sessionStorage]) {
      for (let index = 0; index < storage.length; index += 1) {
        const key = storage.key(index)!

        expect(key + storage.getItem(key)).not.toMatch(/usuario|auditoria|mfa\.|salud/)
      }
    }
  })

  it('cambiar de idioma recarga el catálogo (resource_label) sin tocar los cambios sin guardar', async () => {
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')

    listPermissions.mockClear()
    listPermissions.mockResolvedValue({
      data: catalog().data.map((entry) => ({
        ...entry,
        resource_label: `${entry.resource_label} (en)`,
      })),
    })
    setLocale('en')
    await flush()

    expect(listPermissions).toHaveBeenCalledTimes(1)
    expect(changesText(wrapper)).toBe('1 unsaved change')
    expect(wrapper.text()).toContain('Usuarios (en)')
    expect(getRole).toHaveBeenCalledTimes(1)
  })

  it('un código de la instantánea que ya no está en el catálogo se conserva y se avisa aparte', async () => {
    getRole.mockResolvedValue(
      role({
        permissions: [
          grant('auditoria.leer', 'allow', 'propios'),
          grant('antiguo.leer', 'allow', 'todos'),
        ],
      }),
    )
    const wrapper = await mountEditor()

    expect(wrapper.text()).toContain(
      'ya no están en el catálogo y se conservan sin cambios: antiguo.leer',
    )

    await setCell('usuario.leer', 'allow')
    await confirmSave(wrapper)

    expect(replaceRolePermissions.mock.calls[0]![1]).toContainEqual({
      code: 'antiguo.leer',
      effect: 'allow',
      scope: 'todos',
    })
  })
})

describe('CA-PERM-117 (RN-PERM-40, OPEN-PERM-10 = B): rol del sistema', () => {
  it('la matriz sale en solo lectura: texto, sin botones de celda, sin «Guardar» y con el texto que remite a «Clonar»', async () => {
    getRole.mockResolvedValue(role({ is_system: true, name: 'Docente' }))
    const wrapper = await mountEditor()

    expect(wrapper.text()).toContain(
      'Los roles del sistema no se modifican desde aquí. Clónalo para crear una versión propia.',
    )
    expect(wrapper.findAll('table')).toHaveLength(2)
    expect(document.body.querySelectorAll('button[data-code]')).toHaveLength(0)
    expect(wrapper.findAll('button').find((b) => b.text() === 'Guardar')).toBeUndefined()
    expect(wrapper.text()).toContain('Permitir · Propios')
    expect(wrapper.find('[data-slot="role-editor-legend"]').exists()).toBe(false)
    expect(replaceRolePermissions).not.toHaveBeenCalled()
  })
})

describe('estados de carga y error (§12.6)', () => {
  it('un 404 al cargar pinta «no encontrado» con enlace al listado', async () => {
    getRole.mockRejectedValue(problem(404, {}))
    const wrapper = await mountEditor()

    expect(wrapper.text()).toContain('Página no encontrada')
    expect(wrapper.text()).toContain('Volver al listado de roles')
  })

  it('el catálogo vacío sale como estado vacío', async () => {
    listPermissions.mockResolvedValue({ data: [] })
    const wrapper = await mountEditor()

    expect(wrapper.text()).toContain('No hay permisos en el catálogo')
  })

  it('«Guardar» se deshabilita mientras se guarda y una segunda pulsación no repite la petición', async () => {
    let finish: (value: unknown) => void = () => undefined

    replaceRolePermissions.mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const wrapper = await mountEditor()

    await setCell('usuario.leer', 'allow')
    saveButton(wrapper).element.focus()
    await saveButton(wrapper).trigger('click')
    await flush()
    buttonIn(alertDialog()!, 'Guardar concesiones de «Coordinación pastoral»').click()
    await flush()

    expect(saveButton(wrapper).attributes('disabled')).toBeDefined()

    finish(role())
    await flush()

    expect(replaceRolePermissions).toHaveBeenCalledTimes(1)
  })
})
