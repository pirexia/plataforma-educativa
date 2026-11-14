<script setup lang="ts">
/**
 * `/administracion/roles/:publicId/permisos` (`docs/modulos/REQ-PERM/funcional.md
 * §20.8`, `RN-PERM-24`/`-25`/`-32`-`-38`, `RN-PERM-40`, `RN-PERM-47`, issue #170).
 * Editor de las concesiones de un rol: la matriz recurso × acción
 * (`RolePermissionMatrix`, rejilla de edición aprobada en `RN-CORE-53`) con un
 * panel de edición por celda (`RoleCellPanel`).
 *
 * - **Nada se envía hasta «Guardar»** (`RN-PERM-36`): el estado de edición vive
 *   aquí, indexado por `code`, nunca en el DOM ni en ningún almacenamiento
 *   (`RN-CORE-50`); cambiar de módulo, buscar o filtrar no lo pierde.
 * - **Qué se ofrece** (`RN-PERM-33`, #170): los ámbitos que el solicitante no posee
 *   aparecen **deshabilitados y explicados**, salvo el valor ya guardado. Se calcula
 *   con `GET /me/effective-permissions` (sin permiso) y es **comodidad**: el servidor
 *   decide. Si esa petición falla, el editor **no** se pinta con todo habilitado.
 * - **Sin peticiones a ciegas** (`RN-CORE-62`, `RN-PERM-25`): hacen falta `rol.leer`
 *   y `permiso.leer` (la ruta, *anyOf*, solo exige `rol.actualizar`); sin ellos, un
 *   estado propio y ninguna petición. `GET /modules` solo con `modulo.leer`.
 * - **Guardar**: comprobación de concurrencia (`RN-PERM-37`), confirmación con
 *   resumen (`RN-CORE-64`), `PUT` del conjunto **completo** con lo intacto idéntico
 *   (`RN-PERM-32`), y los errores `403`/`422`/`404`/`409` de `RN-PERM-36`, que
 *   conservan los cambios sin guardar.
 * - **Salir con cambios** (`RN-PERM-38`): guarda de navegación y `beforeunload`
 *   solo mientras haya cambios.
 * - **Roles del sistema** (`RN-PERM-40`): la misma matriz en solo lectura.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute } from 'vue-router'
import { i18n, useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/components/useConfirm'
import { normalizeSearchText } from '@/data-table'
import EmptyState from '@/layouts/components/EmptyState.vue'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import { SearchX } from '@lucide/vue'
import { reloadSession } from '@/session/useSession'
import {
  getMyEffectivePermissions,
  getRole,
  listModules,
  listPermissions,
  replaceRolePermissions,
  type RolePermissionEntry,
} from '../api'
import RoleCellPanel from '../components/roles/RoleCellPanel.vue'
import RolePermissionMatrix, {
  type MatrixCellView,
  type MatrixModuleView,
} from '../components/roles/RolePermissionMatrix.vue'
import {
  problemDetail,
  problemErrorEntryWithCode,
  problemFieldErrors,
  problemRetryAfter,
  problemStatus,
} from '../composables/problem'
import { usePermissions } from '../composables/usePermissions'
import { usePermissionVocabulary } from '../permissionVocabulary'
import {
  allowAvailability,
  buildReplaceBody,
  currentChoice,
  indexSnapshot,
  isModified,
  modifiedCodes,
  scopeOptions,
  summarizeChanges,
  withEdit,
  type CellChoice,
  type Change,
  type Edits,
} from '../roleEditor'
import { buildGrid } from '../rolePermissionGrid'
import type {
  EffectivePermission,
  ModuleSubscription,
  Permission,
  PublicId,
  Role,
  RolePermission,
} from '../types'

const t = useT()
const route = useRoute()
const { can, me } = usePermissions()
const vocabulary = usePermissionVocabulary()
const confirmation = useConfirm()

const publicId = computed<PublicId>(() => String(route.params.publicId))

// --- Estado de carga -----------------------------------------------------------

/** `RN-PERM-25`: la ruta exige `rol.actualizar`; el contenido necesita además estos dos de lectura. */
const missingPermissions = computed(() =>
  ['rol.leer', 'permiso.leer'].filter((permission) => !can(permission)),
)

const loading = ref(true)
const loadError = ref<ShellErrorState | null>(null)
const role = ref<Role | null>(null)
const catalog = ref<Permission[]>([])
const mine = ref<Map<string, EffectivePermission>>(new Map())
/** `null` ⇒ sin `modulo.leer` (o fallo): no se marca ningún módulo (`RN-PERM-35`). */
const modules = ref<Map<string, ModuleSubscription> | null>(null)

const readonly = computed(() => role.value?.is_system === true)
const snapshot = computed(() => indexSnapshot(role.value?.permissions ?? []))

// --- Estado de edición (RN-PERM-32) ---------------------------------------------

const edits = ref<Edits>({})
const dirtyCodes = computed(() => modifiedCodes(snapshot.value, edits.value))
const dirtyCount = computed(() => dirtyCodes.value.length)

const moduleFilter = ref('')
const search = ref('')
const onlyChanged = ref(false)

const activeCode = ref<string | null>(null)
const panelOpen = ref(false)

const saving = ref(false)
const message = ref<string | null>(null)
const cellErrors = ref<Record<string, string>>({})

interface Summary {
  /** `detail` del servidor (`ADR-038 §6.3`). */
  detail: string | null
  /** Mensajes de `422` que no caen en una celda. */
  lines: string[]
  grant: { permission: string; scope: string } | null
  /** Etiquetas de los permisos de `RN-PERM-47` (`409`). */
  capacity: string[] | null
}

const summary = ref<Summary | null>(null)
const summaryEl = ref<HTMLElement | null>(null)
const conflict = ref<string[] | null>(null)

const locale = computed(() => i18n.global.locale.value)

// --- Carga ----------------------------------------------------------------------

async function fetchModules(): Promise<void> {
  // RN-CORE-62: sin `modulo.leer` no se pide ni se marca nada.
  if (!can('modulo.leer')) {
    modules.value = null

    return
  }

  try {
    modules.value = new Map((await listModules()).data.map((item) => [item.module_code, item]))
  } catch {
    modules.value = null
  }
}

async function load(): Promise<void> {
  if (missingPermissions.value.length > 0) {
    loading.value = false

    return
  }

  loading.value = true
  loadError.value = null

  try {
    const [loadedRole, permissions, effective] = await Promise.all([
      getRole(publicId.value),
      listPermissions(),
      getMyEffectivePermissions(),
    ])

    role.value = loadedRole
    catalog.value = permissions.data
    mine.value = new Map(effective.data.map((entry) => [entry.code, entry]))
    edits.value = {}
    cellErrors.value = {}
    summary.value = null
    conflict.value = null
    message.value = null

    await fetchModules()
  } catch (err) {
    role.value = null
    loadError.value = resolveErrorState(err)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})

// `RN-CORE-63`/`RN-PERM-28`: las etiquetas de recurso y de módulo las traduce el servidor. La
// recarga **no** toca el estado de edición, que está indexado por `code` (`§20.16`).
watch(locale, async () => {
  if (role.value === null) {
    return
  }

  try {
    catalog.value = (await listPermissions()).data
    await fetchModules()
  } catch {
    // Un fallo al refrescar etiquetas no tumba el editor ya pintado.
  }
})

// --- Etiquetas y vista de la matriz ------------------------------------------------

function stateText(choice: CellChoice): string {
  if (choice.state === 'allow') {
    return t('core.roles.editor.state.allow', { scope: vocabulary.scope(choice.scope) })
  }

  return choice.state === 'deny' ? vocabulary.effect('deny') : t('core.roles.editor.state.none')
}

const listFormat = computed(
  () => new Intl.ListFormat(locale.value, { style: 'narrow', type: 'unit' }),
)

function moduleNotContracted(moduleCode: string): boolean {
  return modules.value?.get(moduleCode)?.enabled === false
}

function moduleTitle(moduleCode: string): string {
  return modules.value?.get(moduleCode)?.name ?? moduleCode
}

/** `RN-PERM-35`: una concesión de categoría especial en un rol sin el atributo, o de un módulo no contratado, es inerte. */
function inertWarnings(permission: Permission, choice: CellChoice): string[] {
  if (choice.state !== 'allow') {
    return []
  }

  const notes: string[] = []

  if (permission.is_special_category && role.value?.special_data_access !== true) {
    notes.push(t('core.roles.editor.notes.specialInert'))
  }

  if (moduleNotContracted(permission.module_code)) {
    notes.push(t('core.roles.editor.notes.moduleInert'))
  }

  return notes
}

function allowBlockNote(permission: Permission): string | null {
  const saved = snapshot.value.get(permission.code)
  const mineRow = mine.value.get(permission.code)
  const availability = allowAvailability(scopeOptions(permission, saved, mineRow), saved, mineRow)

  if (availability.enabled || availability.block === null) {
    return null
  }

  if (availability.block.kind === 'vetoed') {
    return t('core.roles.editor.notes.onlyVetoed')
  }

  if (availability.block.kind === 'inert') {
    return t('core.roles.editor.notes.onlyInert', {
      reason: availability.block.reasons.map((reason) => vocabulary.inertReason(reason)).join(', '),
    })
  }

  return t('core.roles.editor.notes.onlyNotHeld')
}

function permissionTitle(permission: Permission): string {
  return `${vocabulary.resource(permission.resource, permission.resource_label)} · ${vocabulary.action(permission.action)}`
}

function cellView(permission: Permission): MatrixCellView {
  const choice = currentChoice(permission.code, snapshot.value, edits.value)
  const marks: string[] = []

  if (isModified(permission.code, snapshot.value, edits.value)) {
    marks.push(t('core.roles.editor.marks.modified'))
  }

  if (inertWarnings(permission, choice).length > 0) {
    marks.push(t('core.roles.editor.marks.inert'))
  }

  if (!readonly.value && allowBlockNote(permission) !== null) {
    marks.push(t('core.roles.editor.marks.onlyRemove'))
  }

  const error = cellErrors.value[permission.code] ?? null

  if (error !== null) {
    marks.push(t('core.roles.editor.marks.error'))
  }

  const params = {
    resource: vocabulary.resource(permission.resource, permission.resource_label),
    action: vocabulary.action(permission.action),
    state: stateText(choice),
  }

  return {
    code: permission.code,
    name:
      marks.length > 0
        ? t('core.roles.editor.cell.nameMarks', {
            ...params,
            marks: listFormat.value.format(marks),
          })
        : t('core.roles.editor.cell.name', params),
    stateText: params.state,
    marks,
    error,
  }
}

function rowChanged(codes: string[]): boolean {
  return codes.some((code) => isModified(code, snapshot.value, edits.value))
}

const matrixModules = computed<MatrixModuleView[]>(() => {
  const role_ = role.value
  const needle = normalizeSearchText(search.value.trim(), locale.value)

  if (role_ === null) {
    return []
  }

  return buildGrid(catalog.value, locale.value)
    .filter((module) => moduleFilter.value === '' || module.moduleCode === moduleFilter.value)
    .map((module): MatrixModuleView => {
      const title = moduleTitle(module.moduleCode)

      return {
        code: module.moduleCode,
        title,
        caption: t('core.roles.editor.matrix.caption', { module: title, role: role_.name }),
        scrollLabel: t('core.roles.editor.matrix.scrollLabel', { module: title }),
        notice: moduleNotContracted(module.moduleCode)
          ? t('core.roles.editor.matrix.notContracted')
          : null,
        actions: module.actions.map((action) => ({ id: action, label: vocabulary.action(action) })),
        rows: module.rows
          .filter((row) => {
            const matches =
              needle === '' || normalizeSearchText(row.label, locale.value).includes(needle)
            const codes = [...row.cells.values()].map((permission) => permission.code)

            return matches && (!onlyChanged.value || rowChanged(codes))
          })
          .map((row) => ({
            resource: row.resource,
            label: row.label,
            special: row.special,
            cells: Object.fromEntries(
              [...row.cells.entries()].map(([action, permission]) => [
                action,
                cellView(permission),
              ]),
            ),
          })),
      }
    })
    .filter((module) => module.rows.length > 0)
})

const moduleOptions = computed(() =>
  [...new Set(catalog.value.map((permission) => permission.module_code))].map((code) => ({
    code,
    title: moduleTitle(code),
  })),
)

/** Códigos de la instantánea que ya no están en el catálogo cargado: se conservan y se avisa aparte (`RN-PERM-32`). */
const orphanCodes = computed(() => {
  const known = new Set(catalog.value.map((permission) => permission.code))

  return [...snapshot.value.keys()].filter((code) => !known.has(code))
})

// --- Panel de edición de celda --------------------------------------------------------

const permissionByCode = computed(
  () => new Map(catalog.value.map((permission) => [permission.code, permission] as const)),
)

const activePermission = computed(() =>
  activeCode.value === null ? null : (permissionByCode.value.get(activeCode.value) ?? null),
)

const panel = computed(() => {
  const permission = activePermission.value

  if (permission === null) {
    return null
  }

  const saved = snapshot.value.get(permission.code)
  const mineRow = mine.value.get(permission.code)
  const options = scopeOptions(permission, saved, mineRow)
  const choice = currentChoice(permission.code, snapshot.value, edits.value)
  const notes = [allowBlockNote(permission), ...inertWarnings(permission, choice)].filter(
    (note): note is string => note !== null,
  )

  return {
    title: permissionTitle(permission),
    code: permission.code,
    choice,
    options,
    allowEnabled: allowAvailability(options, saved, mineRow).enabled,
    notes,
    error: cellErrors.value[permission.code] ?? null,
  }
})

function openCell(code: string): void {
  activeCode.value = code
  panelOpen.value = true
}

function applyCell(choice: CellChoice): void {
  if (activeCode.value === null) {
    return
  }

  const code = activeCode.value

  edits.value = withEdit(edits.value, code, choice, snapshot.value)

  // El error del servidor hablaba del estado anterior de la celda.
  if (code in cellErrors.value) {
    const rest = { ...cellErrors.value }

    delete rest[code]
    cellErrors.value = rest
  }

  panelOpen.value = false
}

// --- Guardar (RN-PERM-36/37) -------------------------------------------------------------

function grantKey(grant: RolePermission): string {
  return `${grant.effect}:${grant.scope}`
}

/** `RN-PERM-37`: códigos cuyas concesiones difieren entre lo abierto y lo que hay ahora en el servidor. */
function changedSinceOpened(
  before: readonly RolePermission[],
  after: readonly RolePermission[],
): string[] {
  const left = new Map(before.map((grant) => [grant.code, grantKey(grant)]))
  const right = new Map(after.map((grant) => [grant.code, grantKey(grant)]))
  const codes = new Set([...left.keys(), ...right.keys()])

  return [...codes].filter((code) => left.get(code) !== right.get(code)).sort()
}

function permissionLabel(code: string): string {
  const permission = permissionByCode.value.get(code)

  return permission ? permissionTitle(permission) : code
}

function changeLine(change: Change): string {
  return t('core.roles.editor.confirm.item', {
    name: permissionLabel(change.code),
    from: stateText(change.from),
    to: stateText(change.to),
  })
}

const isHolder = computed(
  () =>
    role.value !== null &&
    (me.value?.roles ?? []).some((r) => r.public_id === role.value!.public_id),
)

async function focusSummary(): Promise<void> {
  await nextTick()
  summaryEl.value?.focus()
}

/** `RN-PERM-36` punto 5: la celda del error debe ser visible. */
function revealCode(code: string): void {
  const permission = permissionByCode.value.get(code)

  if (!permission) {
    return
  }

  if (moduleFilter.value !== '' && moduleFilter.value !== permission.module_code) {
    moduleFilter.value = permission.module_code
  }

  search.value = ''
  onlyChanged.value = false
}

async function save(): Promise<void> {
  const current = role.value

  if (current === null || dirtyCount.value === 0 || saving.value || readonly.value) {
    return
  }

  summary.value = null
  conflict.value = null
  message.value = null
  saving.value = true

  let sentCodes: string[] = []

  try {
    // 1. `RN-PERM-37`: otra persona pudo cambiar el rol desde que se abrió.
    const fresh = await getRole(current.public_id)
    const changed = changedSinceOpened(current.permissions ?? [], fresh.permissions ?? [])

    if (changed.length > 0) {
      conflict.value = changed

      return
    }

    // 2. Confirmación con resumen (`RN-CORE-64`).
    const changes = summarizeChanges(snapshot.value, edits.value)
    const count = fresh.users_count ?? current.users_count ?? 0
    const counts = t('core.roles.editor.confirm.counts', {
      granted: changes.granted.length,
      scopeChanged: changes.scopeChanged.length,
      denied: changes.denied.length,
      removed: changes.removed.length,
    })
    const consequence = t('core.roles.editor.confirm.consequence', { count }, count)
    const holder = isHolder.value ? ` ${t('core.roles.editor.confirm.holder')}` : ''

    const confirmed = await confirmation.ask({
      title: t('core.roles.editor.confirm.title', { name: current.name }),
      description: `${counts}. ${consequence}.${holder}`,
      details: [
        ...changes.granted,
        ...changes.scopeChanged,
        ...changes.denied,
        ...changes.removed,
      ].map(changeLine),
      confirmLabel: t('core.roles.editor.confirm.confirm', { name: current.name }),
      destructive: changes.removed.length + changes.denied.length > 0,
    })

    if (!confirmed) {
      return
    }

    // 3. `PUT` del conjunto completo (`RN-PERM-32`).
    const body = buildReplaceBody(catalog.value, snapshot.value, edits.value)

    sentCodes = body.codes

    const saved = await replaceRolePermissions(
      current.public_id,
      body.entries as RolePermissionEntry[],
    )

    // 4. `200`: la instantánea pasa a ser la respuesta, sin otra petición.
    role.value = saved
    edits.value = {}
    cellErrors.value = {}
    message.value = t('core.roles.editor.saved')

    if (isHolder.value) {
      // Su propio menú y su propia posesión pueden haber cambiado (`ADR-053 §6`).
      await Promise.all([reloadSession(), refreshMine()])
    }
  } catch (err) {
    await handleSaveError(err, sentCodes)
  } finally {
    saving.value = false
  }
}

async function refreshMine(): Promise<void> {
  try {
    mine.value = new Map(
      (await getMyEffectivePermissions()).data.map((entry) => [entry.code, entry]),
    )
  } catch {
    // La posesión se recalcula al recargar el editor; un fallo aquí no deshace lo guardado.
  }
}

async function handleSaveError(err: unknown, sentCodes: string[]): Promise<void> {
  const status = problemStatus(err)
  const next: Summary = { detail: problemDetail(err), lines: [], grant: null, capacity: null }

  if (status === 404) {
    // `RN-PERM-36` punto 7: el rol ya no existe.
    role.value = null
    loadError.value = { kind: 'not-found' }

    return
  }

  if (status === 403) {
    // `RPERM-013`: `errors.grant[0].params.code` (api.md §9.2.1).
    const entry = problemErrorEntryWithCode(
      err,
      'grant',
      'core.authorization.cannot_grant_unheld_permission',
    )

    if (entry) {
      const code = String(entry.params?.code ?? '')

      next.grant = { permission: code, scope: String(entry.params?.scope ?? '') }
      cellErrors.value = code ? { [code]: entry.message } : {}
      revealCode(code)
    }
  } else if (status === 422) {
    // Cada `permissions.<i>.<campo>` se lleva a la celda del código que ocupaba esa posición.
    const errors: Record<string, string> = {}

    for (const [field, messages] of Object.entries(problemFieldErrors(err))) {
      const match = /^permissions\.(\d+)\./.exec(field)
      const code = match ? sentCodes[Number(match[1])] : undefined

      if (code) {
        errors[code] = [errors[code], ...messages].filter(Boolean).join(' ')
      } else {
        next.lines.push(...messages)
      }
    }

    cellErrors.value = errors

    const first = Object.keys(errors)[0]

    if (first) {
      revealCode(first)
    }
  } else if (status === 409) {
    const entry = problemErrorEntryWithCode(
      err,
      'administration_capacity',
      'core.validation.administration_capacity_lost',
    )

    if (entry) {
      const codes = Array.isArray(entry.params?.codes) ? (entry.params.codes as unknown[]) : []

      next.capacity = codes.map((code) => permissionLabel(String(code)))
    }
  } else if (status === 429) {
    const seconds = problemRetryAfter(err)

    next.detail =
      seconds !== null
        ? t('core.roles.errors.tooManyRequestsWithSeconds', { seconds })
        : t('core.roles.errors.tooManyRequests')
  } else if (next.detail === null) {
    next.detail = t('core.roles.errors.unexpected')
  }

  summary.value = next
  await focusSummary()
}

async function reloadFromServer(): Promise<void> {
  const confirmed = await confirmation.ask({
    title: t('core.roles.editor.conflict.reloadConfirm.title'),
    description: t('core.roles.editor.conflict.reloadConfirm.description'),
    confirmLabel: t('core.roles.editor.conflict.reloadConfirm.confirm'),
    destructive: true,
  })

  if (confirmed) {
    await load()
  }
}

// --- Salir con cambios sin guardar (RN-PERM-38) ---------------------------------------------

onBeforeRouteLeave(async () => {
  if (dirtyCount.value === 0) {
    return true
  }

  return confirmation.ask({
    title: t('core.roles.editor.leave.title'),
    description: t(
      'core.roles.editor.leave.description',
      { count: dirtyCount.value },
      dirtyCount.value,
    ),
    confirmLabel: t('core.roles.editor.leave.confirm'),
    cancelLabel: t('core.roles.editor.leave.cancel'),
    destructive: true,
  })
})

/** Registrado **solo mientras haya cambios** y retirado al guardar, recargar o salir. */
function onBeforeUnload(event: BeforeUnloadEvent): void {
  event.preventDefault()
  event.returnValue = ''
}

watch(
  dirtyCount,
  (count) => {
    if (count > 0) {
      window.addEventListener('beforeunload', onBeforeUnload)
    } else {
      window.removeEventListener('beforeunload', onBeforeUnload)
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', onBeforeUnload)
})

const selectClass =
  'border-input bg-background flex h-8 w-full min-w-0 rounded-lg border px-2.5 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-3 [@media(any-pointer:coarse)]:min-h-11'
</script>

<template>
  <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-6">
    <p
      v-if="missingPermissions.length > 0"
      class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
    >
      <template v-for="permission in missingPermissions" :key="permission">
        <span class="block">{{ t('core.roles.editor.missing', { permission }) }}</span>
      </template>
    </p>

    <LoadingState v-else-if="loading" />

    <template v-else-if="loadError">
      <ErrorState :state="loadError" @retry="load" />
      <p v-if="loadError.kind === 'not-found'" class="text-center text-sm">
        <RouterLink
          :to="{ name: 'core-roles' }"
          class="text-primary-on-background underline-offset-4 hover:underline"
        >
          {{ t('core.roles.editor.backToList') }}
        </RouterLink>
      </p>
    </template>

    <template v-else-if="role">
      <header class="flex flex-col gap-1">
        <h1 class="text-lg font-semibold">
          {{ t('core.roles.editor.title', { name: role.name }) }}
        </h1>
        <p v-if="!readonly" class="text-muted-foreground text-sm">
          {{ t('core.roles.editor.intro') }}
        </p>
        <p v-else class="border-border bg-muted rounded-lg border px-3 py-2 text-sm">
          {{ t('core.roles.systemNotice') }}
        </p>
      </header>

      <p
        v-if="message"
        role="status"
        class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
      >
        {{ message }}
      </p>

      <div
        v-if="summary"
        ref="summaryEl"
        role="alert"
        tabindex="-1"
        data-slot="role-editor-summary"
        class="border-destructive text-destructive flex flex-col gap-1 rounded-lg border px-3 py-2 text-sm"
      >
        <p class="font-medium">{{ t('core.roles.editor.errors.summary') }}</p>
        <p v-if="summary.detail">{{ summary.detail }}</p>
        <p v-if="summary.grant">
          {{
            t('core.roles.editor.errors.grantDetail', {
              permission: permissionLabel(summary.grant.permission),
              scope: vocabulary.scope(summary.grant.scope),
            })
          }}
        </p>
        <ul v-if="summary.lines.length > 0" class="list-disc pl-5">
          <li v-for="line in summary.lines" :key="line">{{ line }}</li>
        </ul>
        <template v-if="summary.capacity">
          <p>{{ t('core.roles.editor.errors.capacity') }}</p>
          <p class="font-medium">{{ t('core.roles.editor.errors.capacityList') }}</p>
          <ul class="list-disc pl-5">
            <li v-for="label in summary.capacity" :key="label">{{ label }}</li>
          </ul>
        </template>
      </div>

      <div
        v-if="conflict"
        role="alert"
        data-slot="role-editor-conflict"
        class="border-border bg-muted flex flex-col gap-2 rounded-lg border px-3 py-2 text-sm"
      >
        <p class="font-medium">{{ t('core.roles.editor.conflict.title') }}</p>
        <p>{{ t('core.roles.editor.conflict.codes') }}</p>
        <ul class="list-disc pl-5">
          <li v-for="code in conflict" :key="code">{{ permissionLabel(code) }}</li>
        </ul>
        <div>
          <Button type="button" variant="outline" @click="reloadFromServer">
            {{ t('core.roles.editor.conflict.reload') }}
          </Button>
        </div>
      </div>

      <section
        v-if="!readonly"
        class="border-border flex flex-col gap-1 rounded-lg border px-3 py-2 text-sm"
        aria-labelledby="role-editor-legend-title"
        data-slot="role-editor-legend"
      >
        <h2 id="role-editor-legend-title" class="font-medium">
          {{ t('core.roles.editor.legend.title') }}
        </h2>
        <p>{{ t('core.roles.editor.legend.rule') }}</p>
        <ul class="text-muted-foreground list-disc pl-5">
          <li>{{ t('core.roles.editor.legend.notHeld') }}</li>
          <li>{{ t('core.roles.editor.legend.noResolver') }}</li>
        </ul>
      </section>

      <p
        v-if="orphanCodes.length > 0"
        class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
      >
        {{ t('core.roles.editor.orphans', { codes: orphanCodes.join(', ') }) }}
      </p>

      <EmptyState
        v-if="catalog.length === 0"
        :icon="SearchX"
        :title="t('core.roles.editor.empty.title')"
        :text="t('core.roles.editor.empty.text')"
      />

      <template v-else>
        <div class="flex flex-wrap items-end gap-3">
          <div class="flex min-w-40 flex-col gap-1">
            <Label for="role-editor-module">{{ t('core.roles.editor.toolbar.module') }}</Label>
            <select id="role-editor-module" v-model="moduleFilter" :class="selectClass">
              <option value="">{{ t('core.roles.editor.toolbar.allModules') }}</option>
              <option v-for="option in moduleOptions" :key="option.code" :value="option.code">
                {{ option.title }}
              </option>
            </select>
          </div>

          <div class="flex min-w-48 flex-1 flex-col gap-1">
            <Label for="role-editor-search">{{ t('core.roles.editor.toolbar.search') }}</Label>
            <Input
              id="role-editor-search"
              v-model="search"
              type="search"
              autocomplete="off"
              :placeholder="t('core.roles.editor.toolbar.searchPlaceholder')"
            />
          </div>

          <label
            v-if="!readonly"
            class="flex min-h-8 items-center gap-2 text-sm [@media(any-pointer:coarse)]:min-h-11"
          >
            <input
              v-model="onlyChanged"
              type="checkbox"
              class="accent-primary-on-background size-4"
            />
            {{ t('core.roles.editor.toolbar.onlyChanged') }}
          </label>
        </div>

        <div v-if="!readonly" class="flex flex-wrap items-center gap-3">
          <p aria-live="polite" class="text-sm" data-slot="role-editor-changes">
            {{ t('core.roles.editor.changes', { count: dirtyCount }, dirtyCount) }}
          </p>
          <!--
            Sin `disabled` mientras se guarda: un botón deshabilitado pierde el foco y, al
            cerrar la confirmación con `Esc`, el foco no podría volver a «Guardar» (`CA-PERM-112`).
            `save()` ignora las pulsaciones mientras hay una operación en curso.
          -->
          <Button
            type="button"
            :disabled="dirtyCount === 0"
            :aria-busy="saving ? 'true' : undefined"
            @click="save"
          >
            {{ t('core.roles.editor.save') }}
          </Button>
        </div>

        <RolePermissionMatrix
          v-if="matrixModules.length > 0"
          :modules="matrixModules"
          :readonly="readonly"
          @edit="openCell"
        />
        <EmptyState
          v-else
          :icon="SearchX"
          :title="t('core.roles.editor.noMatches.title')"
          :text="t('core.roles.editor.noMatches.text')"
        />
      </template>

      <RoleCellPanel
        v-if="panel && !readonly"
        v-model:open="panelOpen"
        :title="panel.title"
        :code="panel.code"
        :choice="panel.choice"
        :options="panel.options"
        :allow-enabled="panel.allowEnabled"
        :notes="panel.notes"
        :error="panel.error"
        @apply="applyCell"
      />
    </template>

    <ConfirmDialog
      :open="confirmation.open.value"
      :request="confirmation.request.value"
      @confirm="confirmation.confirm"
      @cancel="confirmation.cancel"
    />
  </div>
</template>
