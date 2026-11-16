<script setup lang="ts">
/**
 * `/alumnado/cambios-de-grupo` (`docs/modulos/REQ-ALUM/funcional.md §10`, `REQ-ALUM-003`; `1.15-d` T2b, `CA-ALUM-070`,
 * `CA-ALUM-071`). Solicitudes de cambio de grupo del curso académico elegido (`?academic_year=`, obligatorio para el
 * servidor, `api.md §3`), con el componente de tablas de `ADR-054`: modo `page`, filtros por estado y por grupo (origen o
 * destino) en la URL. Esta pantalla **solo lista, aprueba, rechaza y cancela**: solicitar se hace desde la ficha y la
 * lista de clase (decisión del usuario 2026-11-11).
 *
 * - Aprobar y rechazar: `matricula.aprobar`; cancelar: `matricula.actualizar`. Solo sobre solicitudes `pendiente` y con
 *   un curso escribible (`RN-ACAD-42`); con el curso en solo lectura, sin acciones. El servidor decide igualmente
 *   (`INV-002`): `403 self_approval`, `409 request_closed`/`enrollment_changed`, `academic-year-closed` se muestran en
 *   el diálogo.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import { useAcademicYear } from '@/academic-year'
import { listGroups } from '@/modules/acad/api'
import ActionNotices from '../components/ActionNotices.vue'
import GroupChangeDecisionDialog from '../components/GroupChangeDecisionDialog.vue'
import { listGroupChangeRequests } from '../api'
import { useActions } from '../composables/useActions'
import { usePermissions } from '../composables/usePermissions'
import type { GroupChangeDecisionMode, GroupChangeRequest } from '../types'

const OPTIONS_PER_PAGE = 100
const STATUSES = ['pendiente', 'aprobada', 'rechazada', 'cancelada']

const t = useT()
const { can } = usePermissions()
const { formatDateTime } = useDataTableFormatters()
const year = useAcademicYear()
const actions = useActions()

const refresh = ref(0)
const dialogOpen = ref(false)
const dialogMode = ref<GroupChangeDecisionMode>('approve')
const dialogTarget = ref<GroupChangeRequest | null>(null)

/** La solicitud exige un curso: el de la URL o el activo. Sin ninguno no se consulta. */
const yearId = computed(() => year.selectedId.value)
const noYear = computed(() => year.noActive.value && yearId.value === null)

// --- Opciones del filtro de grupo (de `acad`, del curso académico elegido) ----------------------------------------

const groupOptions = ref<{ value: string; label: string }[]>([])
const optionsReady = ref(false)

async function loadOptions(): Promise<void> {
  optionsReady.value = false
  groupOptions.value = []

  if (yearId.value !== null) {
    try {
      const groups = await listGroups({ academic_year: yearId.value, per_page: OPTIONS_PER_PAGE })

      groupOptions.value = groups.data.map((group) => ({
        value: group.public_id,
        label: group.name || group.code,
      }))
    } catch {
      // Si `acad` no responde (permiso, módulo), el filtro simplemente no se ofrece.
    }
  }

  optionsReady.value = true
}

onMounted(loadOptions)
watch(yearId, loadOptions)

const filters = computed<DataTableFilter[]>(() => {
  const result: DataTableFilter[] = [
    {
      type: 'enum',
      id: 'status',
      labelKey: 'alum.groupChanges.filters.status',
      multiple: false,
      options: STATUSES.map((value) => ({
        value,
        labelKey: `alum.enums.groupChangeStatus.${value}`,
      })),
    },
  ]

  if (groupOptions.value.length > 0) {
    result.push({
      type: 'enum',
      id: 'group',
      labelKey: 'alum.groupChanges.filters.group',
      multiple: false,
      options: groupOptions.value,
    })
  }

  return result
})

// --- Acciones por fila ---------------------------------------------------------------------------------------------

const canDecide = computed(() => can('matricula.aprobar') && year.writable.value)
const canCancel = computed(() => can('matricula.actualizar') && year.writable.value)
const showActions = computed(() => canDecide.value || canCancel.value)

function isPending(request: GroupChangeRequest): boolean {
  return request.status === 'pendiente'
}

function openDialog(mode: GroupChangeDecisionMode, request: GroupChangeRequest): void {
  actions.clear()
  dialogMode.value = mode
  dialogTarget.value = request
  dialogOpen.value = true
}

function onDecided(): void {
  actions.message.value = t(`alum.groupChanges.${dialogMode.value}.done`)
  actions.actionError.value = null
  refresh.value += 1
}

/** Un `409`: la solicitud ya no está como se vio; se recarga la lista sin cerrar el diálogo con su error. */
function onStale(): void {
  refresh.value += 1
}

// --- Tabla ---------------------------------------------------------------------------------------------------------

function groupName(group: { name: string; code: string } | null): string {
  return group === null ? t('alum.common.dash') : group.name || group.code
}

const columns = computed<DataTableColumn<GroupChangeRequest>[]>(() => [
  {
    id: 'student',
    headerKey: 'alum.groupChanges.columns.student',
    rowHeader: true,
    hideable: false,
    card: 'title',
    value: (request) => request.enrollment.student.display_name,
  },
  {
    id: 'from_group',
    headerKey: 'alum.groupChanges.columns.fromGroup',
    value: (request) => groupName(request.from_group),
    card: 'field',
  },
  {
    id: 'to_group',
    headerKey: 'alum.groupChanges.columns.toGroup',
    value: (request) => groupName(request.to_group),
    card: 'field',
  },
  {
    id: 'reason',
    headerKey: 'alum.groupChanges.columns.reason',
    value: (request) => request.reason,
    card: 'field',
  },
  {
    id: 'status',
    headerKey: 'alum.groupChanges.columns.status',
    value: (request) => t(`alum.enums.groupChangeStatus.${request.status}`),
    card: 'field',
  },
  {
    id: 'requested_by',
    headerKey: 'alum.groupChanges.columns.requestedBy',
    value: (request) => request.requested_by?.display_name ?? t('alum.common.dash'),
    card: 'field',
  },
  {
    id: 'created_at',
    headerKey: 'alum.groupChanges.columns.createdAt',
    value: (request) => formatDateTime(request.created_at),
    card: 'field',
  },
  {
    id: 'decision_note',
    headerKey: 'alum.groupChanges.columns.decisionNote',
    defaultHidden: true,
    value: (request) => request.decision_note ?? t('alum.common.dash'),
    card: 'field',
  },
  ...(showActions.value
    ? [
        {
          id: 'actions',
          headerKey: 'alum.fields.actions',
          hideable: false,
          card: 'actions' as const,
          value: () => null,
        },
      ]
    : []),
])

const fetchRequests: DataTableFetcher<GroupChangeRequest> = (query) =>
  listGroupChangeRequests({
    academic_year: yearId.value ?? undefined,
    status: query.filters.status,
    group: query.filters.group,
    page: query.page,
    per_page: query.per_page,
  })

const rowKey = (request: GroupChangeRequest): string => request.public_id
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <div>
      <h1 class="text-lg font-semibold">{{ t('alum.groupChanges.title') }}</h1>
      <p class="text-muted-foreground text-sm">{{ t('alum.groupChanges.intro') }}</p>
    </div>

    <p v-if="year.readOnly.value" role="status" class="text-muted-foreground text-sm">
      {{ t('alum.groupChanges.readOnlyYear') }}
    </p>
    <p v-if="noYear" role="status" class="text-muted-foreground text-sm">
      {{ t('alum.groupChanges.noYear') }}
    </p>

    <ActionNotices :message="actions.message.value" :action-error="actions.actionError.value" />

    <DataTable
      v-if="optionsReady && yearId !== null"
      :key="`${yearId}-${refresh}`"
      table-id="alum.group_changes"
      :caption="t('alum.groupChanges.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchRequests"
      :filters="filters"
      :row-key="rowKey"
      url-state
      :empty-title="t('alum.groupChanges.empty.title')"
      :empty-text="t('alum.groupChanges.empty.text')"
      :card-heading-level="2"
    >
      <template #cell-actions="{ row }">
        <div v-if="isPending(row)" class="flex flex-wrap gap-2">
          <Button
            v-if="canDecide"
            type="button"
            variant="outline"
            size="sm"
            @click="openDialog('approve', row)"
          >
            {{ t('alum.groupChanges.approve.action') }}
            <span class="sr-only">{{ row.enrollment.student.display_name }}</span>
          </Button>
          <Button
            v-if="canDecide"
            type="button"
            variant="outline"
            size="sm"
            @click="openDialog('reject', row)"
          >
            {{ t('alum.groupChanges.reject.action') }}
            <span class="sr-only">{{ row.enrollment.student.display_name }}</span>
          </Button>
          <Button
            v-if="canCancel"
            type="button"
            variant="outline"
            size="sm"
            @click="openDialog('cancel', row)"
          >
            {{ t('alum.groupChanges.cancel.action') }}
            <span class="sr-only">{{ row.enrollment.student.display_name }}</span>
          </Button>
        </div>
      </template>
    </DataTable>

    <GroupChangeDecisionDialog
      v-model:open="dialogOpen"
      :mode="dialogMode"
      :request="dialogTarget"
      @saved="onDecided"
      @stale="onStale"
    />
  </div>
</template>
