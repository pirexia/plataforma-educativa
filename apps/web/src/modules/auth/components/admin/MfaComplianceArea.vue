<script setup lang="ts">
/**
 * Área 1 de `/administracion/mfa` (funcional.md §D.9.1): cumplimiento
 * agregado del rol elegido y listado individualizado, filtrable por
 * `state` y paginado (`GET /mfa-compliance`, `GET /mfa-compliance/users`,
 * permiso `mfa.leer`). Cada fila lleva una acción de restablecimiento que
 * delega en quien la embebe (`AdminMfaView`, que la enruta al área 3):
 * esta área no llama a `POST /mfa-resets`, solo elige el objetivo.
 *
 * Tabla con `@tanstack/vue-table` (modelo de filas headless) + los
 * componentes de tabla de shadcn-vue (`CLAUDE.md §1`) — filtrado y
 * paginación son del servidor; TanStack aporta el modelo de columnas
 * consistente con el resto de tablas que este paso introduce.
 */
import { computed, h, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { createColumnHelper, FlexRender, getCoreRowModel, useVueTable } from '@tanstack/vue-table'
import { useT } from '@/i18n'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
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
const { locale } = useI18n()

const summary = ref<MfaComplianceSummary | null>(null)
const summaryError = ref<string | null>(null)

const FILTERS: MfaComplianceFilterState[] = [
  'obligated',
  'enrolled',
  'pending',
  'past_deadline',
  'exempt',
]

const activeFilters = ref<Set<MfaComplianceFilterState>>(new Set())
const rows = ref<MfaComplianceUserEntry[]>([])
const page = ref(1)
const lastPage = ref(1)
const loadingRows = ref(false)
const rowsError = ref<string | null>(null)

const dateFormatter = computed(() => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }))

function formatDate(value: string | null): string {
  return value ? dateFormatter.value.format(new Date(value)) : '—'
}

function fullName(user: MfaComplianceUserSummary): string {
  return [user.given_name, user.family_name_1, user.family_name_2].filter(Boolean).join(' ')
}

const columnHelper = createColumnHelper<MfaComplianceUserEntry>()

// D.9: los estados no se distinguen solo por color — el texto traducido
// va siempre dentro del Badge, el color es un refuerzo, no la señal.
const columns = [
  columnHelper.accessor((entry) => `${fullName(entry.user)} · ${entry.user.email}`, {
    id: 'user',
    header: () => t('auth.mfaAdmin.compliance.columnUser'),
  }),
  columnHelper.accessor('state', {
    id: 'state',
    header: () => t('auth.mfaAdmin.compliance.columnState'),
    cell: (info) =>
      h(Badge, { variant: info.getValue() === 'past_deadline' ? 'destructive' : 'secondary' }, () =>
        t(`auth.mfaAdmin.compliance.state.${info.getValue()}`),
      ),
  }),
  columnHelper.accessor('grace_deadline_at', {
    id: 'grace_deadline_at',
    header: () => t('auth.mfaAdmin.compliance.columnGraceDeadline'),
    cell: (info) => formatDate(info.getValue()),
  }),
  columnHelper.accessor('enrolled_methods', {
    id: 'enrolled_methods',
    header: () => t('auth.mfaAdmin.compliance.columnMethods'),
    cell: (info) =>
      info.getValue().length > 0
        ? info
            .getValue()
            .map((method) => t(`auth.mfa.method.${method}`))
            .join(', ')
        : '—',
  }),
  columnHelper.accessor('required_by_roles', {
    id: 'required_by_roles',
    header: () => t('auth.mfaAdmin.compliance.columnRoles'),
    cell: (info) => info.getValue().join(', ') || '—',
  }),
  columnHelper.display({
    id: 'actions',
    header: () => t('auth.mfaAdmin.compliance.columnActions'),
    cell: (info) =>
      h(
        Button,
        {
          type: 'button',
          variant: 'outline',
          size: 'sm',
          onClick: () => emit('reset-user', info.row.original.user),
        },
        () => t('auth.mfaAdmin.compliance.resetAction'),
      ),
  }),
]

const table = useVueTable({
  get data() {
    return rows.value
  },
  columns,
  getCoreRowModel: getCoreRowModel(),
})

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

async function loadRows(): Promise<void> {
  loadingRows.value = true
  rowsError.value = null

  try {
    const result = await getMfaComplianceUsers({
      state: activeFilters.value.size > 0 ? Array.from(activeFilters.value) : undefined,
      page: page.value,
    })

    rows.value = result.data
    lastPage.value = result.meta.last_page
  } catch (err) {
    if (apiErrorStatus(err) === 403) {
      rowsError.value = t('auth.mfaAdmin.forbidden')
      emit('forbidden')
      return
    }
    rowsError.value = t('auth.common.unexpectedError')
  } finally {
    loadingRows.value = false
  }
}

function toggleFilter(value: MfaComplianceFilterState): void {
  if (activeFilters.value.has(value)) {
    activeFilters.value.delete(value)
  } else {
    activeFilters.value.add(value)
  }
  activeFilters.value = new Set(activeFilters.value)
  page.value = 1
  void loadRows()
}

function goToPage(next: number): void {
  if (next < 1 || next > lastPage.value) {
    return
  }
  page.value = next
  void loadRows()
}

watch(
  () => props.role?.public_id,
  () => {
    void loadSummary()
  },
  { immediate: true },
)

// El listado individualizado no depende del rol elegido (api.md §C.5: sin
// parámetro `role`) — se carga una sola vez y se refiltra por `state`.
void loadRows()

defineExpose({ refresh: () => Promise.all([loadSummary(), loadRows()]) })
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

    <fieldset class="flex flex-wrap gap-3">
      <legend class="mb-1 text-xs font-medium">
        {{ t('auth.mfaAdmin.compliance.filterLegend') }}
      </legend>
      <label v-for="value in FILTERS" :key="value" class="flex items-center gap-1.5 text-sm">
        <input type="checkbox" :checked="activeFilters.has(value)" @change="toggleFilter(value)" />
        {{ t(`auth.mfaAdmin.compliance.state.${value}`) }}
      </label>
    </fieldset>

    <p v-if="rowsError" role="alert" class="text-destructive text-sm">{{ rowsError }}</p>
    <p v-if="loadingRows" class="text-muted-foreground text-sm">
      {{ t('auth.mfaAdmin.compliance.loading') }}
    </p>

    <Table v-else>
      <TableHeader>
        <TableRow v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id">
          <TableHead v-for="header in headerGroup.headers" :key="header.id">
            <FlexRender
              v-if="!header.isPlaceholder"
              :render="header.column.columnDef.header"
              :props="header.getContext()"
            />
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow v-if="rows.length === 0">
          <TableCell :colspan="columns.length" class="text-muted-foreground">
            {{ t('auth.mfaAdmin.compliance.empty') }}
          </TableCell>
        </TableRow>
        <TableRow v-for="row in table.getRowModel().rows" :key="row.id">
          <TableCell v-for="cell in row.getVisibleCells()" :key="cell.id">
            <FlexRender :render="cell.column.columnDef.cell" :props="cell.getContext()" />
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>

    <div v-if="lastPage > 1" class="flex items-center gap-2 text-sm">
      <Button
        type="button"
        variant="outline"
        size="sm"
        :disabled="page <= 1"
        @click="goToPage(page - 1)"
      >
        {{ t('auth.mfaAdmin.compliance.previousPage') }}
      </Button>
      <span>{{ t('auth.mfaAdmin.compliance.pageIndicator', { page, lastPage }) }}</span>
      <Button
        type="button"
        variant="outline"
        size="sm"
        :disabled="page >= lastPage"
        @click="goToPage(page + 1)"
      >
        {{ t('auth.mfaAdmin.compliance.nextPage') }}
      </Button>
    </div>
  </section>
</template>
