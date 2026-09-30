<script setup lang="ts">
/**
 * Área 1 de `/administracion/mfa` (funcional.md §D.9.1): cumplimiento
 * agregado del rol elegido y listado individualizado, filtrable por
 * `state` y paginado (`GET /mfa-compliance`, `GET /mfa-compliance/users`,
 * permiso `mfa.leer`). Cada fila lleva una acción de restablecimiento que
 * delega en quien la embebe (`AdminMfaView`, que la enruta al área 3):
 * esta área no llama a `POST /mfa-resets`, solo elige el objetivo.
 *
 * `REQ-CORE` 1.9 (`docs/modulos/REQ-CORE/funcional.md §13.15`,
 * `OPEN-CORE-28`): el listado individualizado pasa por el componente de
 * tabla de datos de `src/data-table`, con **paridad funcional estricta**:
 * mismas peticiones (`GET /mfa-compliance/users` con `state` por comas y
 * `page`), mismas columnas, misma emisión de `reset-user`, mismo
 * tratamiento del `403` (`emit('forbidden')`) y mismo `refresh()` expuesto.
 * Sin estado en la URL (paridad estricta, `CA-CORE-206`). No corrige el
 * issue #116 (filas antes de elegir rol): el listado sigue sin depender
 * del rol.
 */
import { computed, ref, watch } from 'vue'
import { useT } from '@/i18n'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import { getMfaCompliance, getMfaComplianceUsers } from '../../api'
import { apiErrorStatus } from '../../composables/formErrors'
import type {
  AdminRoleOption,
  MfaComplianceFilterState,
  MfaComplianceSummary,
  MfaComplianceUserEntry,
  MfaComplianceUserSummary,
} from '../../types'

const props = defineProps<{ role: AdminRoleOption | null }>()
const emit = defineEmits<{
  'reset-user': [MfaComplianceUserSummary]
  forbidden: []
}>()

const t = useT()
const { formatDate } = useDataTableFormatters()

const summary = ref<MfaComplianceSummary | null>(null)
const summaryError = ref<string | null>(null)
const table = ref<{ refresh: () => Promise<void> } | null>(null)

const FILTERS: MfaComplianceFilterState[] = [
  'obligated',
  'enrolled',
  'pending',
  'past_deadline',
  'exempt',
]

const filters: DataTableFilter[] = [
  {
    type: 'enum',
    id: 'state',
    labelKey: 'auth.mfaAdmin.compliance.filterLegend',
    options: FILTERS.map((value) => ({
      value,
      labelKey: `auth.mfaAdmin.compliance.state.${value}`,
    })),
  },
]

function fullName(user: MfaComplianceUserSummary): string {
  return [user.given_name, user.family_name_1, user.family_name_2].filter(Boolean).join(' ')
}

// D.9: los estados no se distinguen solo por color — el texto traducido
// va siempre dentro del Badge, el color es un refuerzo, no la señal.
const columns: DataTableColumn<MfaComplianceUserEntry>[] = [
  {
    id: 'user',
    headerKey: 'auth.mfaAdmin.compliance.columnUser',
    value: (entry) => `${fullName(entry.user)} · ${entry.user.email}`,
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'state',
    headerKey: 'auth.mfaAdmin.compliance.columnState',
    value: (entry) => entry.state,
    card: 'subtitle',
  },
  {
    id: 'grace_deadline_at',
    headerKey: 'auth.mfaAdmin.compliance.columnGraceDeadline',
    value: (entry) => formatDate(entry.grace_deadline_at),
    card: 'field',
  },
  {
    id: 'enrolled_methods',
    headerKey: 'auth.mfaAdmin.compliance.columnMethods',
    value: (entry) =>
      entry.enrolled_methods.length > 0
        ? entry.enrolled_methods.map((method) => t(`auth.mfa.method.${method}`)).join(', ')
        : null,
    card: 'field',
  },
  {
    id: 'required_by_roles',
    headerKey: 'auth.mfaAdmin.compliance.columnRoles',
    value: (entry) => entry.required_by_roles.join(', ') || null,
    card: 'field',
  },
  {
    id: 'actions',
    headerKey: 'auth.mfaAdmin.compliance.columnActions',
    hideable: false,
    card: 'actions',
  },
]

/** `GET /mfa-compliance/users` (api.md §C.1): `state` por comas y `page`. El `403` se reenvía a quien embebe el área. */
const fetchUsers: DataTableFetcher<MfaComplianceUserEntry> = async (query) => {
  try {
    return await getMfaComplianceUsers({
      state: query.filters.state
        ? (query.filters.state.split(',') as MfaComplianceFilterState[])
        : undefined,
      page: query.page,
      per_page: query.per_page,
    })
  } catch (err) {
    if (apiErrorStatus(err) === 403) {
      emit('forbidden')
    }
    throw err
  }
}

async function loadSummary(): Promise<void> {
  if (!props.role) {
    summary.value = null
    return
  }

  summaryError.value = null

  try {
    summary.value = await getMfaCompliance({ role: props.role.public_id })
  } catch (err) {
    if (apiErrorStatus(err) === 403) {
      // D.9 regla 1: el 403 se muestra tal cual, no se oculta ni redirige.
      summaryError.value = t('auth.mfaAdmin.forbidden')
      emit('forbidden')
      return
    }
    summaryError.value = t('auth.common.unexpectedError')
  }
}

watch(
  () => props.role?.public_id,
  () => {
    void loadSummary()
  },
  { immediate: true },
)

// El listado individualizado no depende del rol elegido (api.md §C.5: sin
// parámetro `role`): la tabla lo carga una sola vez y se refiltra por `state`.
const emptyTitle = computed(() => t('auth.mfaAdmin.compliance.empty'))

defineExpose({
  refresh: () => Promise.all([loadSummary(), table.value?.refresh()]),
})
</script>

<template>
  <section class="flex flex-col gap-4">
    <h2 class="text-sm font-semibold">{{ t('auth.mfaAdmin.compliance.title') }}</h2>

    <p v-if="!role" class="text-muted-foreground text-sm">
      {{ t('auth.mfaAdmin.compliance.chooseRoleHint') }}
    </p>

    <p v-if="summaryError" role="alert" class="text-destructive text-sm">{{ summaryError }}</p>

    <dl v-if="role && summary" class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
      <div class="border-border rounded-lg border px-3 py-2">
        <dt class="text-muted-foreground text-xs">{{ t('auth.mfaAdmin.compliance.total') }}</dt>
        <dd class="text-base font-semibold">{{ summary.users_total }}</dd>
      </div>
      <div class="border-border rounded-lg border px-3 py-2">
        <dt class="text-muted-foreground text-xs">{{ t('auth.mfaAdmin.compliance.enrolled') }}</dt>
        <dd class="text-base font-semibold">{{ summary.users_enrolled }}</dd>
      </div>
      <div class="border-border rounded-lg border px-3 py-2">
        <dt class="text-muted-foreground text-xs">{{ t('auth.mfaAdmin.compliance.obligated') }}</dt>
        <dd class="text-base font-semibold">{{ summary.users_obligated }}</dd>
      </div>
      <div class="border-border rounded-lg border px-3 py-2">
        <dt class="text-muted-foreground text-xs">{{ t('auth.mfaAdmin.compliance.inGrace') }}</dt>
        <dd class="text-base font-semibold">{{ summary.users_in_grace }}</dd>
      </div>
      <div class="border-border rounded-lg border px-3 py-2">
        <dt class="text-muted-foreground text-xs">{{ t('auth.mfaAdmin.compliance.enforced') }}</dt>
        <dd class="text-base font-semibold">{{ summary.users_enforced }}</dd>
      </div>
      <div class="border-border rounded-lg border px-3 py-2">
        <dt class="text-muted-foreground text-xs">{{ t('auth.mfaAdmin.compliance.exempt') }}</dt>
        <dd class="text-base font-semibold">{{ summary.users_exempt }}</dd>
      </div>
    </dl>

    <DataTable
      ref="table"
      table-id="auth.mfa_compliance"
      :caption="t('auth.mfaAdmin.compliance.tableCaption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchUsers"
      :filters="filters"
      :row-key="(entry: MfaComplianceUserEntry) => entry.user.public_id"
      :empty-title="emptyTitle"
      :card-heading-level="3"
    >
      <template #cell-state="{ row }">
        <Badge :variant="row.state === 'past_deadline' ? 'destructive' : 'secondary'">
          {{ t(`auth.mfaAdmin.compliance.state.${row.state}`) }}
        </Badge>
      </template>
      <template #cell-actions="{ row }">
        <Button
          type="button"
          variant="outline"
          size="sm"
          :aria-label="t('auth.mfaAdmin.compliance.resetActionFor', { name: fullName(row.user) })"
          @click="emit('reset-user', row.user)"
        >
          {{ t('auth.mfaAdmin.compliance.resetAction') }}
        </Button>
      </template>
    </DataTable>
  </section>
</template>
