<script setup lang="ts">
/**
 * `/administracion/roles/:publicId` (`docs/modulos/REQ-PERM/funcional.md §20.5`,
 * `RN-PERM-26`/`-28`/`-39`/`-40`, `OPEN-CORE-36` = A resuelto por 1.5b).
 * Ficha de un rol: datos, recuento de titulares (`users_count`, S-PERM-1),
 * concesiones y acciones.
 *
 * - **Permiso, nunca código de rol** (`RN-PERM-46`, `RN-CORE-61`): cada acción
 *   se muestra si y solo si `/me.permissions` contiene el permiso de su
 *   *endpoint*; «Editar concesiones» y «Eliminar» además solo en roles
 *   personalizados (`is_system` es un dato, no un código).
 * - **Sin peticiones a ciegas** (`RN-CORE-62`): `GET /permissions` solo con
 *   `permiso.leer` (marca de categoría especial); sin él la columna ni se pinta.
 * - **Concesiones en modo `local`** de `src/data-table` (`RN-PERM-43`): es un campo
 *   de un recurso (`permissions[]`), no un *endpoint* paginado.
 * - **Baja** (`RN-PERM-39`): confirmación (`RN-CORE-64`); con titulares conocidos
 *   el botón se deshabilita con su explicación (comodidad: el servidor decide).
 * - Etiquetas de recurso traducidas por el servidor (`RN-PERM-28`): al cambiar de
 *   idioma se vuelve a pedir la ficha (`RN-CORE-63`).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { i18n, useT } from '@/i18n'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/components/useConfirm'
import {
  DataTable,
  DataTableEmptyValue,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFilter,
  type DataTableLocalFetcher,
} from '@/data-table'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import { deleteRole, getRole, listPermissions } from '../api'
import {
  problemDetail,
  problemErrorEntryWithCode,
  problemRetryAfter,
  problemStatus,
} from '../composables/problem'
import { setFlash, takeFlash } from '../composables/useFlash'
import { usePermissions } from '../composables/usePermissions'
import { compareActions, usePermissionVocabulary } from '../permissionVocabulary'
import type { Permission, PublicId, Role, RolePermission } from '../types'

const t = useT()
const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const { formatNumber } = useDataTableFormatters()
const vocabulary = usePermissionVocabulary()
const confirmation = useConfirm()

const publicId = computed<PublicId>(() => String(route.params.publicId))

const loading = ref(true)
const loadError = ref<ShellErrorState | null>(null)
const role = ref<Role | null>(null)
const catalog = ref<Permission[] | null>(null)
const table = ref<{ refresh: () => Promise<void> } | null>(null)

const busy = ref(false)
const actionError = ref<string | null>(null)
const assignmentsCount = ref<number | null>(null)
const message = ref<string | null>(null)

const grants = computed<RolePermission[]>(() => role.value?.permissions ?? [])

/** Códigos de categoría especial; solo existen si se cargó `GET /permissions` (`RN-CORE-62`). */
const specialCodes = computed(
  () => new Set((catalog.value ?? []).filter((p) => p.is_special_category).map((p) => p.code)),
)

const usersCount = computed(() => role.value?.users_count)
const hasHolders = computed(() => usersCount.value !== undefined && usersCount.value > 0)

const canEditData = computed(() => can('rol.actualizar'))
const canEditGrants = computed(() => can('rol.actualizar') && role.value?.is_system === false)
const canClone = computed(() => can('rol.crear'))
const canDelete = computed(() => can('rol.eliminar') && role.value?.is_system === false)
const canViewUsers = computed(
  () => can('usuario.leer') && (usersCount.value === undefined || usersCount.value > 0),
)

const hasActions = computed(
  () =>
    canEditData.value ||
    canEditGrants.value ||
    canClone.value ||
    canDelete.value ||
    canViewUsers.value,
)

const yesNo = (value: boolean): string => (value ? t('core.roles.yes') : t('core.roles.no'))

async function fetchAll(): Promise<void> {
  role.value = await getRole(publicId.value)

  // RN-CORE-62: sin `permiso.leer` no se pide el catálogo. Su fallo no tumba la ficha (es enriquecimiento).
  if (can('permiso.leer')) {
    try {
      catalog.value = (await listPermissions()).data
    } catch {
      catalog.value = null
    }
  } else {
    catalog.value = null
  }
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null

  try {
    await fetchAll()
  } catch (err) {
    role.value = null
    loadError.value = resolveErrorState(err)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  const flash = takeFlash()

  if (flash) {
    message.value = t(flash.key, flash.params ?? {})
  }

  void load()
})

// RN-PERM-28 / RN-CORE-63: las etiquetas de recurso las traduce el servidor.
watch(
  () => i18n.global.locale.value,
  async () => {
    if (role.value === null) {
      return
    }

    try {
      await fetchAll()
      await table.value?.refresh()
    } catch {
      // Un fallo al refrescar etiquetas no tumba la ficha ya pintada.
    }
  },
)

// --- Tabla de concesiones (modo `local`, RN-PERM-43/44) ---------------------

function resourceLabel(grant: RolePermission): string {
  return vocabulary.resource(grant.resource, grant.resource_label)
}

const columns = computed<DataTableColumn<RolePermission>[]>(() => {
  const list: DataTableColumn<RolePermission>[] = [
    {
      id: 'resource',
      headerKey: 'core.roles.grants.columns.resource',
      value: resourceLabel,
      sortable: true,
      rowHeader: true,
      hideable: false,
      card: 'title',
    },
    {
      id: 'action',
      headerKey: 'core.roles.grants.columns.action',
      value: (grant) => vocabulary.action(grant.action),
      compare: (a, b) => compareActions(a.action, b.action),
      sortable: true,
      card: 'subtitle',
    },
    {
      id: 'effect',
      headerKey: 'core.roles.grants.columns.effect',
      value: (grant) => vocabulary.effect(grant.effect),
      sortable: true,
      card: 'field',
    },
    {
      id: 'scope',
      headerKey: 'core.roles.grants.columns.scope',
      // `RN-PERM-34`: un `deny` es ciego al ámbito.
      value: (grant) =>
        grant.effect === 'deny' ? t('core.roles.grants.anyScope') : vocabulary.scope(grant.scope),
      sortable: true,
      card: 'field',
    },
  ]

  if (catalog.value !== null) {
    list.push({
      id: 'special',
      headerKey: 'core.roles.grants.columns.special',
      value: (grant) =>
        specialCodes.value.has(grant.code) ? t('core.roles.grants.specialBadge') : null,
      card: 'field',
    })
  }

  list.push({
    id: 'code',
    headerKey: 'core.roles.grants.columns.code',
    value: (grant) => grant.code,
    sortable: true,
    defaultHidden: true,
    card: 'field',
  })

  return list
})

const filters = computed<DataTableFilter<RolePermission>[]>(() => [
  {
    type: 'enum',
    id: 'effect',
    labelKey: 'core.roles.grants.filters.effect',
    options: [
      { value: 'allow', label: vocabulary.effect('allow') },
      { value: 'deny', label: vocabulary.effect('deny') },
    ],
    rowValue: (grant) => grant.effect,
  },
])

function searchText(grant: RolePermission): string {
  return [resourceLabel(grant), vocabulary.action(grant.action), grant.code].join(' ')
}

const fetchGrants: DataTableLocalFetcher<RolePermission> = () =>
  Promise.resolve({ data: grants.value })

// --- Acciones -----------------------------------------------------------------

function fail(err: unknown): void {
  if (problemStatus(err) === 429) {
    const seconds = problemRetryAfter(err)

    actionError.value =
      seconds !== null
        ? t('core.roles.errors.tooManyRequestsWithSeconds', { seconds })
        : t('core.roles.errors.tooManyRequests')

    return
  }

  actionError.value = problemDetail(err) ?? t('core.roles.errors.unexpected')
}

async function remove(): Promise<void> {
  const current = role.value

  if (!current || busy.value) {
    return
  }

  const confirmed = await confirmation.ask({
    title: t('core.roles.detail.confirmDelete.title', { name: current.name }),
    description: t('core.roles.detail.confirmDelete.description'),
    confirmLabel: t('core.roles.detail.confirmDelete.confirm', { name: current.name }),
    destructive: true,
  })

  if (!confirmed) {
    return
  }

  busy.value = true
  actionError.value = null
  assignmentsCount.value = null
  message.value = null

  try {
    await deleteRole(current.public_id)
    setFlash({ key: 'core.roles.flash.deleted', params: { name: current.name } })
    await router.push({ name: 'core-roles' })
  } catch (err) {
    const entry = problemErrorEntryWithCode(err, 'role', 'core.validation.role_has_assignments')

    // `errors.role[0].params.users_count` (api.md §9.2.1): el recuento real, que el servidor conoce mejor.
    if (problemStatus(err) === 409 && entry !== null) {
      const count = Number(entry.params?.users_count)

      assignmentsCount.value = Number.isFinite(count) ? count : null
      actionError.value = problemDetail(err) ?? entry.message

      if (Number.isFinite(count)) {
        current.users_count = count
      }
    } else {
      fail(err)
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-5xl flex-col gap-6 px-4 py-6">
    <LoadingState v-if="loading" />

    <ErrorState v-else-if="loadError" :state="loadError" @retry="load" />

    <template v-else-if="role">
      <header class="flex flex-col gap-1">
        <h1 class="text-lg font-semibold">{{ role.name }}</h1>
        <p class="text-muted-foreground text-sm">
          {{ t('core.roles.detail.code') }}:
          <code class="font-mono text-xs">{{ role.code }}</code>
        </p>
      </header>

      <p
        v-if="message"
        role="status"
        class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
      >
        {{ message }}
      </p>

      <div v-if="actionError" role="alert" class="text-destructive flex flex-col gap-1 text-sm">
        <p>{{ actionError }}</p>
        <p v-if="assignmentsCount !== null">
          {{ t('core.roles.errors.hasAssignments', { count: formatNumber(assignmentsCount) }) }}
        </p>
      </div>

      <section class="flex flex-col gap-2" aria-labelledby="role-data-title">
        <h2 id="role-data-title" class="text-sm font-semibold">
          {{ t('core.roles.detail.data') }}
        </h2>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('core.roles.detail.type') }}</dt>
            <dd>
              {{ role.is_system ? t('core.roles.type.system') : t('core.roles.type.custom') }}
            </dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('core.roles.detail.mfaRequired') }}</dt>
            <dd>{{ yesNo(role.mfa_required) }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('core.roles.detail.specialData') }}</dt>
            <dd>{{ yesNo(role.special_data_access) }}</dd>
          </div>
          <div v-if="role.users_count !== undefined">
            <dt class="text-muted-foreground text-xs">{{ t('core.roles.detail.users') }}</dt>
            <dd data-slot="role-users-count">{{ formatNumber(role.users_count) }}</dd>
          </div>
        </dl>
      </section>

      <section v-if="hasActions" class="flex flex-col gap-2" aria-labelledby="role-actions-title">
        <h2 id="role-actions-title" class="text-sm font-semibold">
          {{ t('core.roles.detail.actions') }}
        </h2>

        <div class="flex flex-wrap gap-2">
          <Button v-if="canEditData" variant="outline" as-child>
            <RouterLink :to="{ name: 'core-role-edit', params: { publicId: role.public_id } }">
              {{ t('core.roles.detail.editData') }}
            </RouterLink>
          </Button>

          <Button v-if="canEditGrants" variant="outline" as-child>
            <RouterLink
              :to="{ name: 'core-role-permissions', params: { publicId: role.public_id } }"
            >
              {{ t('core.roles.detail.editGrants') }}
            </RouterLink>
          </Button>

          <Button v-if="canClone" :variant="role.is_system ? 'default' : 'outline'" as-child>
            <RouterLink :to="{ name: 'core-role-clone', params: { publicId: role.public_id } }">
              {{ t('core.roles.detail.clone') }}
            </RouterLink>
          </Button>

          <Button v-if="canViewUsers" variant="outline" as-child>
            <RouterLink :to="{ name: 'core-users', query: { role: role.public_id } }">
              {{ t('core.roles.detail.viewUsers') }}
            </RouterLink>
          </Button>

          <Button
            v-if="canDelete"
            type="button"
            variant="destructive"
            :disabled="busy || hasHolders"
            :aria-describedby="hasHolders ? 'role-delete-note' : undefined"
            @click="remove"
          >
            {{ t('core.roles.detail.delete') }}
          </Button>
        </div>

        <p
          v-if="canDelete && hasHolders"
          id="role-delete-note"
          class="text-muted-foreground text-xs"
        >
          {{ t('core.roles.detail.deleteBlocked', { count: formatNumber(usersCount!) }) }}
        </p>
      </section>

      <section class="flex flex-col gap-3" aria-labelledby="role-grants-title">
        <h2 id="role-grants-title" class="text-sm font-semibold">
          {{ t('core.roles.detail.grantsTitle') }}
        </h2>

        <p v-if="role.is_system" class="border-border bg-muted rounded-lg border px-3 py-2 text-sm">
          {{ t('core.roles.systemNotice') }}
        </p>

        <DataTable
          ref="table"
          table-id="core.role_grants"
          :caption="t('core.roles.grants.caption', { name: role.name })"
          :columns="columns"
          mode="local"
          :fetcher="fetchGrants"
          :row-key="(grant: RolePermission) => grant.code"
          :filters="filters"
          searchable
          :search-text="searchText"
          hide-single-page-footer
          :empty-title="t('core.roles.grants.empty.title')"
          :empty-text="t('core.roles.grants.empty.text')"
          :card-heading-level="3"
        >
          <template #cell-special="{ row }">
            <template v-if="specialCodes.has(row.code)">
              <Badge variant="outline">{{ t('core.roles.grants.specialBadge') }}</Badge>
              <span
                v-if="!role.special_data_access"
                class="text-muted-foreground mt-1 block text-xs"
              >
                {{ t('core.roles.grants.specialInert') }}
              </span>
            </template>
            <DataTableEmptyValue v-else />
          </template>
        </DataTable>
      </section>
    </template>

    <ConfirmDialog
      :open="confirmation.open.value"
      :request="confirmation.request.value"
      @confirm="confirmation.confirm"
      @cancel="confirmation.cancel"
    />
  </div>
</template>
