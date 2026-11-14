/**
 * `docs/modulos/REQ-PERM/funcional.md §20.8`, `RN-PERM-32`-`-37`, issue #170.
 * Lógica **pura** del editor de concesiones de un rol (sin Vue, sin red): el
 * estado editable de cada permiso, el conjunto que se envía en el `PUT`, el
 * resumen de la confirmación y qué ámbitos se ofrecen, cuáles se deshabilitan y
 * por qué (`RN-PERM-33`).
 *
 * Todo lo que calcula `scopeOptions`/`allowAvailability` es **comodidad**: el
 * servidor decide (`RN-PERM-24`, `INV-010`) y un `403` de `RPERM-013` se muestra
 * siempre. El estado de edición vive en la vista, indexado por `code`, y no se
 * guarda en ningún almacenamiento (`RN-CORE-50`).
 */
import { scopeRank } from './permissionVocabulary'
import type { EffectivePermission, Permission, RolePermission } from './types'
import type { RolePermissionEntry } from './api'

/** `RN-PERM-32`: tres estados por permiso. «Denegar» no lleva ámbito (`RN-PERM-34`). */
export type CellChoice = { state: 'none' } | { state: 'allow'; scope: string } | { state: 'deny' }

export type Edits = Readonly<Record<string, CellChoice>>

export const NO_GRANT: CellChoice = { state: 'none' }

/** Instantánea de `permissions[]` de `GET /roles/{id}`, indexada por código. */
export function indexSnapshot(grants: readonly RolePermission[]): Map<string, RolePermission> {
  return new Map(grants.map((grant) => [grant.code, grant] as const))
}

export function snapshotChoice(grant: RolePermission | undefined): CellChoice {
  if (!grant) {
    return NO_GRANT
  }

  return grant.effect === 'deny' ? { state: 'deny' } : { state: 'allow', scope: grant.scope }
}

export function choicesEqual(a: CellChoice, b: CellChoice): boolean {
  if (a.state !== b.state) {
    return false
  }

  return a.state === 'allow' && b.state === 'allow' ? a.scope === b.scope : true
}

/** El estado vigente de un permiso: lo editado o, si no se tocó, la instantánea. */
export function currentChoice(
  code: string,
  snapshot: ReadonlyMap<string, RolePermission>,
  edits: Edits,
): CellChoice {
  return edits[code] ?? snapshotChoice(snapshot.get(code))
}

export function isModified(
  code: string,
  snapshot: ReadonlyMap<string, RolePermission>,
  edits: Edits,
): boolean {
  return code in edits && !choicesEqual(edits[code]!, snapshotChoice(snapshot.get(code)))
}

/**
 * Fija el estado de un permiso. Si coincide con la instantánea, la edición
 * **desaparece** («la fila vuelve a contar como no modificada», `RN-PERM-33`) y
 * el permiso se enviará idéntico, sin comprobación de `RPERM-013`.
 */
export function withEdit(
  edits: Edits,
  code: string,
  choice: CellChoice,
  snapshot: ReadonlyMap<string, RolePermission>,
): Edits {
  const next = { ...edits }

  if (choicesEqual(choice, snapshotChoice(snapshot.get(code)))) {
    delete next[code]
  } else {
    next[code] = choice
  }

  return next
}

/** Códigos con cambios sin guardar. */
export function modifiedCodes(
  snapshot: ReadonlyMap<string, RolePermission>,
  edits: Edits,
): string[] {
  return Object.keys(edits).filter((code) => isModified(code, snapshot, edits))
}

export interface ReplaceBody {
  entries: RolePermissionEntry[]
  /** `codes[i]` es el código de `entries[i]`: la correspondencia posición → código de los `422` (`RN-PERM-36` punto 6). */
  codes: string[]
}

/**
 * `RN-PERM-32`: el cuerpo del `PUT` es el estado completo deseado. Una entrada por
 * permiso en «Permitir» o «Denegar» y ninguna por «Sin conceder». Lo no tocado se
 * envía **idéntico** a la instantánea (mismo `effect` y `scope`, aunque un `deny`
 * guardado lleve otro ámbito), para que el servidor no lo compruebe. Los códigos
 * de la instantánea que ya no están en el catálogo cargado se conservan tal cual.
 */
export function buildReplaceBody(
  catalog: readonly Permission[],
  snapshot: ReadonlyMap<string, RolePermission>,
  edits: Edits,
): ReplaceBody {
  const entries: RolePermissionEntry[] = []
  const codes: string[] = []
  const seen = new Set<string>()

  function push(code: string, effect: 'allow' | 'deny', scope: string): void {
    entries.push({ code, effect, scope })
    codes.push(code)
  }

  function emit(code: string): void {
    seen.add(code)

    const edit = edits[code]

    if (edit !== undefined && isModified(code, snapshot, edits)) {
      if (edit.state === 'allow') {
        push(code, 'allow', edit.scope)
      } else if (edit.state === 'deny') {
        // `RN-PERM-34`: la API exige el campo y no lo evalúa en un `deny`.
        push(code, 'deny', 'todos')
      }

      return
    }

    const saved = snapshot.get(code)

    if (saved) {
      push(code, saved.effect, saved.scope)
    }
  }

  for (const permission of catalog) {
    emit(permission.code)
  }

  for (const code of snapshot.keys()) {
    if (!seen.has(code)) {
      emit(code)
    }
  }

  return { entries, codes }
}

export interface Change {
  code: string
  from: CellChoice
  to: CellChoice
}

export interface ChangeSummary {
  /** De «Sin conceder» o «Denegar» a «Permitir». */
  granted: Change[]
  /** De «Permitir» con un ámbito a «Permitir» con otro. */
  scopeChanged: Change[]
  /** A «Denegar», venga de donde venga. */
  denied: Change[]
  /** De «Permitir» o «Denegar» a «Sin conceder». */
  removed: Change[]
}

/** `RN-PERM-36` punto 2: los cuatro recuentos de la confirmación. */
export function summarizeChanges(
  snapshot: ReadonlyMap<string, RolePermission>,
  edits: Edits,
): ChangeSummary {
  const summary: ChangeSummary = { granted: [], scopeChanged: [], denied: [], removed: [] }

  for (const code of modifiedCodes(snapshot, edits)) {
    const from = snapshotChoice(snapshot.get(code))
    const to = edits[code]!
    const change: Change = { code, from, to }

    if (to.state === 'deny') {
      summary.denied.push(change)
    } else if (to.state === 'none') {
      summary.removed.push(change)
    } else if (from.state === 'allow') {
      summary.scopeChanged.push(change)
    } else {
      summary.granted.push(change)
    }
  }

  return summary
}

// --- Ámbitos que se ofrecen (RN-PERM-33, UX de #170) -----------------------

/**
 * `RN-PERM-33`: los cuatro estados de una opción de ámbito.
 * - `current`: el valor guardado; siempre seleccionable (conservarlo no se comprueba).
 * - `available`: concedible y poseído por el solicitante.
 * - `not_held`: concedible, pero el solicitante no lo posee ⇒ deshabilitado.
 * - `no_resolver`: admitido por el permiso y sin resolutor todavía ⇒ deshabilitado.
 */
export type ScopeOptionStatus = 'available' | 'current' | 'not_held' | 'no_resolver'

export interface ScopeOption {
  scope: string
  status: ScopeOptionStatus
  selectable: boolean
}

/**
 * El solicitante posee `scope` del permiso si su fila de `/me/effective-permissions`
 * es `permitido` y `unrestricted` o el ámbito está en `scopes` (`api.md §14.2`).
 */
export function holdsScope(mine: EffectivePermission | undefined, scope: string): boolean {
  return (
    mine !== undefined &&
    mine.decision === 'permitido' &&
    (mine.unrestricted || mine.scopes.includes(scope))
  )
}

/** Todos los `applicable_scopes` del permiso, en el orden del vocabulario, cada uno en su estado. */
export function scopeOptions(
  permission: Permission,
  saved: RolePermission | undefined,
  mine: EffectivePermission | undefined,
): ScopeOption[] {
  const grantable = new Set(permission.grantable_scopes)
  const savedScope = saved?.effect === 'allow' ? saved.scope : null
  // Un valor guardado que el catálogo ya no admite se conserva como opción (no se pierde en silencio).
  const offered =
    savedScope !== null && !permission.applicable_scopes.includes(savedScope)
      ? [...permission.applicable_scopes, savedScope]
      : [...permission.applicable_scopes]

  return offered
    .sort((a, b) => scopeRank(a) - scopeRank(b))
    .map((scope): ScopeOption => {
      if (scope === savedScope) {
        return { scope, status: 'current', selectable: true }
      }

      if (!grantable.has(scope)) {
        return { scope, status: 'no_resolver', selectable: false }
      }

      return holdsScope(mine, scope)
        ? { scope, status: 'available', selectable: true }
        : { scope, status: 'not_held', selectable: false }
    })
}

/** Por qué el solicitante no puede conceder «Permitir» (nota de la fila, `RN-PERM-33` punto 1). */
export type AllowBlockReason =
  { kind: 'not_held' } | { kind: 'vetoed' } | { kind: 'inert'; reasons: string[] }

export interface AllowAvailability {
  enabled: boolean
  /** Solo si `enabled` es `false`. */
  block: AllowBlockReason | null
}

/**
 * «Permitir» queda deshabilitado si el solicitante no posee **ningún** ámbito
 * disponible del permiso **y** la instantánea de la fila no es `allow` (con un
 * `allow` guardado, siempre se puede conservar). El motivo distingue «no lo
 * tienes», «lo tienes vetado por una denegación» y «tu concesión no surte
 * efecto».
 */
export function allowAvailability(
  options: readonly ScopeOption[],
  saved: RolePermission | undefined,
  mine: EffectivePermission | undefined,
): AllowAvailability {
  if (saved?.effect === 'allow' || options.some((option) => option.status === 'available')) {
    return { enabled: true, block: null }
  }

  const sources = mine?.sources ?? []

  if (sources.some((source) => source.effect === 'deny')) {
    return { enabled: false, block: { kind: 'vetoed' } }
  }

  const grants = sources.filter((source) => source.effect === 'allow')

  if (grants.length > 0 && grants.every((source) => source.inert)) {
    const reasons = [
      ...new Set(grants.map((source) => source.inert_reason).filter((r): r is string => !!r)),
    ]

    return { enabled: false, block: { kind: 'inert', reasons } }
  }

  return { enabled: false, block: { kind: 'not_held' } }
}

/**
 * Ámbito con el que arranca «Permitir» al elegirlo: el guardado si el permiso ya
 * estaba concedido; si no, el primer ámbito disponible. `null` si no hay ninguno
 * (entonces «Permitir» está deshabilitado).
 */
export function defaultAllowScope(options: readonly ScopeOption[]): string | null {
  const keep = options.find((option) => option.status === 'current')

  if (keep) {
    return keep.scope
  }

  return options.find((option) => option.status === 'available')?.scope ?? null
}
