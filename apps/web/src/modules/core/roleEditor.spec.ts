/**
 * `docs/modulos/REQ-PERM/funcional.md §20.8`: lógica pura del editor de
 * concesiones — `RN-PERM-32` (estado, cuerpo del `PUT`, intactas idénticas),
 * `RN-PERM-33` (cuatro estados de ámbito, «Permitir» deshabilitado y su motivo,
 * issue #170), `RN-PERM-34` (`deny` con `scope: todos`) y `RN-PERM-36`
 * (recuentos de la confirmación). Cubre `CA-PERM-108`, `CA-PERM-109`,
 * `CA-PERM-110` y `CA-PERM-112` en su parte de lógica.
 */
import { describe, expect, it } from 'vitest'
import {
  allowAvailability,
  buildReplaceBody,
  currentChoice,
  defaultAllowScope,
  holdsScope,
  indexSnapshot,
  isModified,
  modifiedCodes,
  scopeOptions,
  summarizeChanges,
  withEdit,
  type Edits,
} from './roleEditor'
import type { EffectivePermission, Permission, RolePermission } from './types'

function permission(code: string, extra: Partial<Permission> = {}): Permission {
  const [resource = code, action = 'leer'] = code.split('.')

  return {
    code,
    resource,
    action,
    module_code: 'core',
    is_special_category: false,
    applicable_scopes: ['todos'],
    grantable_scopes: ['todos'],
    retired_at: null,
    ...extra,
  }
}

function grant(code: string, effect: 'allow' | 'deny', scope: string): RolePermission {
  const [resource = code, action = 'leer'] = code.split('.')

  return { code, resource, action, effect, scope }
}

function mine(code: string, extra: Partial<EffectivePermission> = {}): EffectivePermission {
  const [resource = code, action = 'leer'] = code.split('.')

  return {
    code,
    resource,
    action,
    module_code: 'core',
    is_special_category: false,
    decision: 'denegado',
    scopes: [],
    unrestricted: false,
    sources: [],
    ...extra,
  }
}

const NONE: Edits = {}

describe('RN-PERM-32: estado de edición', () => {
  const snapshot = indexSnapshot([grant('auditoria.leer', 'allow', 'propios')])

  it('lo no tocado vale lo de la instantánea y lo que no existe en ella es «Sin conceder»', () => {
    expect(currentChoice('auditoria.leer', snapshot, NONE)).toEqual({
      state: 'allow',
      scope: 'propios',
    })
    expect(currentChoice('usuario.leer', snapshot, NONE)).toEqual({ state: 'none' })
  })

  it('devolver una fila a su valor guardado antes de guardar la deja como no modificada (#170)', () => {
    let edits = withEdit(NONE, 'auditoria.leer', { state: 'allow', scope: 'todos' }, snapshot)

    expect(isModified('auditoria.leer', snapshot, edits)).toBe(true)
    expect(modifiedCodes(snapshot, edits)).toEqual(['auditoria.leer'])

    edits = withEdit(edits, 'auditoria.leer', { state: 'allow', scope: 'propios' }, snapshot)

    expect(isModified('auditoria.leer', snapshot, edits)).toBe(false)
    expect(modifiedCodes(snapshot, edits)).toEqual([])
    expect(edits).toEqual({})
  })

  it('un deny guardado y vuelto a «Denegar» cuenta como no modificado aunque su ámbito guardado no sea todos', () => {
    const withDeny = indexSnapshot([grant('usuario.eliminar', 'deny', 'grupo')])
    const edits = withEdit(NONE, 'usuario.eliminar', { state: 'deny' }, withDeny)

    expect(edits).toEqual({})
  })
})

describe('CA-PERM-108 (RN-PERM-32, RN-PERM-34): cuerpo del PUT', () => {
  const catalog = [
    permission('auditoria.leer', { applicable_scopes: ['todos', 'propios'] }),
    permission('configuracion.actualizar'),
    permission('usuario.leer'),
  ]
  const snapshot = indexSnapshot([
    grant('auditoria.leer', 'allow', 'propios'),
    grant('configuracion.actualizar', 'deny', 'todos'),
  ])

  it('envía lo no tocado idéntico, lo nuevo en «Permitir» y omite lo pasado a «Sin conceder»', () => {
    let edits = withEdit(NONE, 'usuario.leer', { state: 'allow', scope: 'todos' }, snapshot)
    edits = withEdit(edits, 'configuracion.actualizar', { state: 'none' }, snapshot)

    const body = buildReplaceBody(catalog, snapshot, edits)

    expect(body.entries).toEqual([
      { code: 'auditoria.leer', effect: 'allow', scope: 'propios' },
      { code: 'usuario.leer', effect: 'allow', scope: 'todos' },
    ])
    expect(body.entries.map((entry) => entry.code)).not.toContain('configuracion.actualizar')
    expect(body.codes).toEqual(['auditoria.leer', 'usuario.leer'])
  })

  it('una elección «Denegar» se envía con scope todos', () => {
    const edits = withEdit(NONE, 'usuario.leer', { state: 'deny' }, snapshot)

    expect(buildReplaceBody(catalog, snapshot, edits).entries).toContainEqual({
      code: 'usuario.leer',
      effect: 'deny',
      scope: 'todos',
    })
  })

  it('un deny guardado con otro ámbito se reenvía idéntico si no se toca', () => {
    const odd = indexSnapshot([grant('usuario.leer', 'deny', 'grupo')])

    expect(buildReplaceBody(catalog, odd, NONE).entries).toEqual([
      { code: 'usuario.leer', effect: 'deny', scope: 'grupo' },
    ])
  })

  it('un código de la instantánea que ya no está en el catálogo se conserva tal cual', () => {
    const retired = indexSnapshot([
      grant('antiguo.leer', 'allow', 'todos'),
      grant('usuario.leer', 'allow', 'todos'),
    ])
    const body = buildReplaceBody(catalog, retired, NONE)

    expect(body.entries).toEqual([
      { code: 'usuario.leer', effect: 'allow', scope: 'todos' },
      { code: 'antiguo.leer', effect: 'allow', scope: 'todos' },
    ])
    expect(body.codes[1]).toBe('antiguo.leer')
  })

  it('sin cambios, el cuerpo es exactamente la instantánea', () => {
    expect(buildReplaceBody(catalog, snapshot, NONE).entries).toEqual([
      { code: 'auditoria.leer', effect: 'allow', scope: 'propios' },
      { code: 'configuracion.actualizar', effect: 'deny', scope: 'todos' },
    ])
  })
})

describe('CA-PERM-112 (RN-PERM-36): recuentos de la confirmación', () => {
  it('cuenta concedidos, cambiados de ámbito, denegados y retirados', () => {
    const snapshot = indexSnapshot([
      grant('a.leer', 'allow', 'todos'),
      grant('b.leer', 'allow', 'todos'),
      grant('c.leer', 'deny', 'todos'),
    ])
    let edits: Edits = NONE

    edits = withEdit(edits, 'n1.leer', { state: 'allow', scope: 'todos' }, snapshot)
    edits = withEdit(edits, 'n2.leer', { state: 'allow', scope: 'todos' }, snapshot)
    edits = withEdit(edits, 'a.leer', { state: 'allow', scope: 'propios' }, snapshot)
    edits = withEdit(edits, 'n3.leer', { state: 'deny' }, snapshot)
    edits = withEdit(edits, 'c.leer', { state: 'none' }, snapshot)

    const summary = summarizeChanges(snapshot, edits)

    expect(summary.granted.map((c) => c.code)).toEqual(['n1.leer', 'n2.leer'])
    expect(summary.scopeChanged.map((c) => c.code)).toEqual(['a.leer'])
    expect(summary.denied.map((c) => c.code)).toEqual(['n3.leer'])
    expect(summary.removed.map((c) => c.code)).toEqual(['c.leer'])
  })

  it('pasar de «Denegar» a «Permitir» cuenta como concedido', () => {
    const snapshot = indexSnapshot([grant('c.leer', 'deny', 'todos')])
    const edits = withEdit(NONE, 'c.leer', { state: 'allow', scope: 'todos' }, snapshot)

    expect(summarizeChanges(snapshot, edits).granted).toHaveLength(1)
  })
})

describe('CA-PERM-109 (RN-PERM-33, issue #170): cuatro estados de ámbito', () => {
  const auditoria = permission('auditoria.leer', {
    applicable_scopes: ['grupo', 'propios', 'todos'],
    grantable_scopes: ['todos', 'propios'],
  })
  const iHold = mine('auditoria.leer', { decision: 'permitido', scopes: ['propios'] })

  it('guardado `todos`: (actual) y seleccionable; propios disponible; grupo «aún no existe»; en el orden del vocabulario', () => {
    const options = scopeOptions(auditoria, grant('auditoria.leer', 'allow', 'todos'), iHold)

    expect(options).toEqual([
      { scope: 'todos', status: 'current', selectable: true },
      { scope: 'propios', status: 'available', selectable: true },
      { scope: 'grupo', status: 'no_resolver', selectable: false },
    ])
  })

  it('sin concesión guardada, `todos` está deshabilitado: «no lo tienes»', () => {
    const options = scopeOptions(auditoria, undefined, iHold)

    expect(options.find((o) => o.scope === 'todos')).toEqual({
      scope: 'todos',
      status: 'not_held',
      selectable: false,
    })
  })

  it('con unrestricted se poseen todos los ámbitos concedibles', () => {
    const wide = mine('auditoria.leer', { decision: 'permitido', unrestricted: true })

    expect(holdsScope(wide, 'propios')).toBe(true)
    expect(scopeOptions(auditoria, undefined, wide).map((o) => o.status)).toEqual([
      'available',
      'available',
      'no_resolver',
    ])
  })

  it('una fila denegada o ausente no posee ningún ámbito', () => {
    expect(
      holdsScope(mine('auditoria.leer', { decision: 'denegado', scopes: ['todos'] }), 'todos'),
    ).toBe(false)
    expect(holdsScope(undefined, 'todos')).toBe(false)
  })

  it('un valor guardado `deny` no marca ninguna opción como actual', () => {
    const options = scopeOptions(auditoria, grant('auditoria.leer', 'deny', 'todos'), iHold)

    expect(options.some((o) => o.status === 'current')).toBe(false)
  })

  it('un ámbito guardado que el catálogo ya no admite se conserva como opción actual', () => {
    const options = scopeOptions(
      permission('x.leer'),
      grant('x.leer', 'allow', 'departamento'),
      undefined,
    )

    expect(options).toContainEqual({ scope: 'departamento', status: 'current', selectable: true })
  })

  it('el ámbito por defecto de «Permitir» es el guardado y, si no, el primero disponible', () => {
    expect(
      defaultAllowScope(scopeOptions(auditoria, grant('auditoria.leer', 'allow', 'todos'), iHold)),
    ).toBe('todos')
    expect(defaultAllowScope(scopeOptions(auditoria, undefined, iHold))).toBe('propios')
    expect(defaultAllowScope(scopeOptions(auditoria, undefined, undefined))).toBeNull()
  })
})

describe('CA-PERM-109/110 (RN-PERM-33 punto 1): «Permitir» deshabilitado y su motivo', () => {
  const usuario = permission('usuario.crear')

  it('sin posesión y sin concesión guardada: deshabilitado, «no lo tienes»', () => {
    const options = scopeOptions(usuario, undefined, undefined)

    expect(allowAvailability(options, undefined, undefined)).toEqual({
      enabled: false,
      block: { kind: 'not_held' },
    })
  })

  it('con una fuente deny del solicitante: «vetado por una denegación»', () => {
    const vetoed = mine('usuario.crear', {
      sources: [
        {
          role: { public_id: 'R', code: 'x', name: 'Cuenta restringida' },
          effect: 'deny',
          scope: 'todos',
          inert: false,
          inert_reason: null,
        },
      ],
    })

    expect(
      allowAvailability(scopeOptions(usuario, undefined, vetoed), undefined, vetoed).block,
    ).toEqual({
      kind: 'vetoed',
    })
  })

  it('con solo fuentes allow inertes: «no surte efecto» con el motivo, sin repetidos', () => {
    const inert = mine('usuario.crear', {
      sources: [
        {
          role: { public_id: 'R', code: 'x', name: 'A' },
          effect: 'allow',
          scope: 'todos',
          inert: true,
          inert_reason: 'inerte_datos_especiales',
        },
        {
          role: { public_id: 'S', code: 'y', name: 'B' },
          effect: 'allow',
          scope: 'todos',
          inert: true,
          inert_reason: 'inerte_datos_especiales',
        },
      ],
    })

    expect(
      allowAvailability(scopeOptions(usuario, undefined, inert), undefined, inert).block,
    ).toEqual({
      kind: 'inert',
      reasons: ['inerte_datos_especiales'],
    })
  })

  it('se habilita si hay algún ámbito disponible o si la instantánea ya es allow (aunque no lo posea)', () => {
    const held = mine('usuario.crear', { decision: 'permitido', unrestricted: true })

    expect(allowAvailability(scopeOptions(usuario, undefined, held), undefined, held).enabled).toBe(
      true,
    )

    const saved = grant('usuario.crear', 'allow', 'todos')

    expect(
      allowAvailability(scopeOptions(usuario, saved, undefined), saved, undefined).enabled,
    ).toBe(true)
  })

  it('una instantánea deny no habilita «Permitir» sin posesión', () => {
    const saved = grant('usuario.crear', 'deny', 'todos')

    expect(
      allowAvailability(scopeOptions(usuario, saved, undefined), saved, undefined).enabled,
    ).toBe(false)
  })
})
