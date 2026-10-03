<script setup lang="ts">
/**
 * `/administracion/auditoria` (`docs/modulos/REQ-CORE/funcional.md §14.7`,
 * `RN-CORE-76`/`-77`/`-78`, `REQ-CORE-005`, 1.9d). Registro de auditoría con
 * el componente de tablas de 1.9 en modo `cursor` (`ADR-038 §4.2`): «Cargar
 * más», tope de 1.000 filas, estado en la URL (sin `cursor`, `RN-CORE-54`),
 * **sin búsqueda** (el *endpoint* no acepta `q`) y **sin columnas ordenables**
 * (orden fijo del servidor).
 *
 * - **Filtros** (`RN-CORE-76`): fecha, operación (`event`), tipo de actor,
 *   usuario (filtro `entity`, solo con `usuario.leer`, `RN-CORE-62`), módulo y
 *   entidad. Las opciones de módulo y entidad salen de `GET
 *   /audit-logs/facets` (`OPEN-CORE-34` = B); operación y tipo de actor son
 *   vocabularios cerrados de `ADR-039` con rama por defecto para valores
 *   nuevos (`ADR-038 §7.3`). La tabla no se monta hasta saber si hay facetas:
 *   el estado de la URL solo se interpreta con los filtros ya declarados.
 * - **Fechas** (`OPEN-CORE-35` = A): zona horaria del navegador, para filtrar
 *   y para mostrar (`auditQuery.ts`).
 * - **«Ver cambios»** (`RN-CORE-77`): panel modal con `changes` tal como llega;
 *   un valor redactado se muestra como «valor no registrado» y nunca se
 *   intenta reconstruir ni se pide nada más al servidor.
 * - **Exportación** (`RN-CORE-78`): solo con `auditoria.exportar`, con los
 *   filtros estructurados del listado.
 */
import { computed, onMounted, ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import LoadingState from '@/layouts/components/LoadingState.vue'
import {
  exportAuditLogs,
  getAuditFacets,
  getDataExport,
  getUser,
  listAuditLogs,
  listUsers,
} from '../api'
import { auditExportPayload, auditListParams } from '../auditQuery'
import { usePermissions } from '../composables/usePermissions'
import type { AuditChangeEntry, AuditFacets, AuditLog, User } from '../types'

const t = useT()
const { can } = usePermissions()
const { formatDateTime } = useDataTableFormatters()

/** `ADR-039 §4.1`: vocabularios cerrados; un valor nuevo del servidor se añade al final. */
const EVENTS = [
  'created',
  'updated',
  'deleted',
  'restored',
  'read',
  'exported',
  'login',
  'logout',
  'password_reset_requested',
]
const ACTOR_TYPES = ['user', 'system', 'console', 'import', 'platform', 'anonymous']

const facets = ref<AuditFacets | null>(null)
const facetsSettled = ref(false)

onMounted(async () => {
  try {
    facets.value = await getAuditFacets()
  } catch {
    // Sin facetas la tabla sigue siendo usable: faltan solo los filtros de módulo y entidad.
    facets.value = null
  } finally {
    facetsSettled.value = true
  }
})

/** `ADR-038 §7.3`: rama por defecto — un valor no anticipado muestra su código, no rompe. */
function translated(key: string, fallback: string): string {
  const label = t(key)

  return label === key ? fallback : label
}

const eventLabel = (event: string): string => translated(`core.audit.event.${event}`, event)
const actorTypeLabel = (type: string): string => translated(`core.audit.actorType.${type}`, type)
const entityLabel = (alias: string): string => translated(`core.audit.entity.${alias}`, alias)

function withExtras(known: string[], extra: string[] | undefined): string[] {
  return [...known, ...(extra ?? []).filter((value) => !known.includes(value))]
}

function userLabel(user: User): string {
  return `${user.person.given_name} ${user.person.family_name_1} (${user.email})`
}

const filters = computed<DataTableFilter[]>(() => {
  const list: DataTableFilter[] = [
    { type: 'dateRange', id: 'occurred_at', labelKey: 'core.audit.filters.occurredAt' },
    {
      type: 'enum',
      id: 'event',
      labelKey: 'core.audit.filters.event',
      options: withExtras(EVENTS, facets.value?.events).map((value) => ({
        value,
        labelKey: `core.audit.event.${value}`,
      })),
    },
    {
      type: 'enum',
      id: 'actor_type',
      labelKey: 'core.audit.filters.actorType',
      options: withExtras(ACTOR_TYPES, facets.value?.actor_types).map((value) => ({
        value,
        labelKey: `core.audit.actorType.${value}`,
      })),
    },
  ]

  // RN-CORE-62: sin `usuario.leer` no hay filtro de usuario ni se pide `GET /users`.
  if (can('usuario.leer')) {
    list.push({
      type: 'entity',
      id: 'actor_id',
      labelKey: 'core.audit.filters.actor',
      search: async (text) =>
        (await listUsers({ q: text, per_page: 10 })).data.map((user) => ({
          value: user.public_id,
          label: userLabel(user),
        })),
      resolve: async (value) => {
        try {
          return userLabel(await getUser(value, { include_deleted: can('usuario.eliminar') }))
        } catch {
          return null
        }
      },
    })
  }

  if (facets.value && facets.value.modules.length > 0) {
    list.push({
      type: 'enum',
      id: 'module',
      labelKey: 'core.audit.filters.module',
      options: facets.value.modules.map((value) => ({
        value,
        labelKey: `core.audit.module.${value}`,
      })),
    })
  }

  if (facets.value && facets.value.auditable_types.length > 0) {
    list.push({
      type: 'enum',
      id: 'auditable_type',
      labelKey: 'core.audit.filters.auditableType',
      options: facets.value.auditable_types.map(({ alias }) => ({
        value: alias,
        labelKey: `core.audit.entity.${alias}`,
      })),
    })
  }

  return list
})

function actorText(log: AuditLog): string | null {
  if (log.actor_type !== 'user') {
    return actorTypeLabel(log.actor_type)
  }

  return log.actor?.display_name ?? null
}

const columns: DataTableColumn<AuditLog>[] = [
  {
    id: 'occurred_at',
    headerKey: 'core.audit.columns.occurredAt',
    value: (log) => formatDateTime(log.occurred_at),
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'event',
    headerKey: 'core.audit.columns.event',
    value: (log) => eventLabel(log.event),
    card: 'subtitle',
  },
  { id: 'actor', headerKey: 'core.audit.columns.actor', value: actorText, card: 'field' },
  {
    id: 'auditable_type',
    headerKey: 'core.audit.columns.auditableType',
    value: (log) => entityLabel(log.auditable_type),
    card: 'field',
  },
  {
    id: 'auditable_public_id',
    headerKey: 'core.audit.columns.auditablePublicId',
    defaultHidden: true,
    card: 'field',
  },
  {
    id: 'ip_address',
    headerKey: 'core.audit.columns.ipAddress',
    defaultHidden: true,
    card: 'field',
  },
  {
    id: 'request_id',
    headerKey: 'core.audit.columns.requestId',
    defaultHidden: true,
    card: 'field',
  },
  {
    id: 'actions',
    headerKey: 'core.audit.columns.actions',
    hideable: false,
    card: 'actions',
  },
]

const fetchAuditLogs: DataTableFetcher<AuditLog> = (query) =>
  listAuditLogs(auditListParams(query.filters, query.cursor))

// RN-CORE-78: solo con `auditoria.exportar`, y con los filtros estructurados del listado.
const exportConfig = computed(() => ({
  canExport: can('auditoria.exportar'),
  request: (filterValues: Record<string, string>) =>
    exportAuditLogs(auditExportPayload(filterValues)),
  status: getDataExport,
}))

// --- «Ver cambios» (RN-CORE-77) ----------------------------------------------

const selected = ref<AuditLog | null>(null)

const panelOpen = computed({
  get: () => selected.value !== null,
  set: (open: boolean) => {
    if (!open) {
      selected.value = null
    }
  },
})

function describe(log: AuditLog): string {
  return t('core.audit.changes.description', {
    event: eventLabel(log.event),
    entity: entityLabel(log.auditable_type),
    date: formatDateTime(log.occurred_at) ?? '',
  })
}

interface ChangeRow {
  attribute: string
  redacted: string | null
  text: string
  before: string | null
  after: string | null
}

function valueText(value: unknown): string {
  if (value === null || value === undefined || value === '') {
    return t('core.audit.changes.emptyValue')
  }

  return typeof value === 'object' ? JSON.stringify(value) : String(value)
}

function emptiness(flag: boolean | undefined): string | null {
  if (typeof flag !== 'boolean') {
    return null
  }

  return flag ? t('core.audit.changes.emptyValue') : t('core.audit.changes.hadValue')
}

const changeRows = computed<ChangeRow[]>(() => {
  const changes = selected.value?.changes

  if (!changes) {
    return []
  }

  return Object.entries(changes).map(([attribute, raw]) => {
    const entry = (typeof raw === 'object' && raw !== null ? raw : {}) as AuditChangeEntry

    // Un valor redactado jamás se reconstruye: solo el motivo y si estaba vacío.
    if (typeof entry.redacted === 'string') {
      return {
        attribute,
        redacted: translated(`core.audit.changes.reason.${entry.redacted}`, entry.redacted),
        text: t('core.audit.changes.notRecorded'),
        before: emptiness(entry.from_empty),
        after: emptiness(entry.to_empty),
      }
    }

    return {
      attribute,
      redacted: null,
      text: t('core.audit.changes.arrow', {
        from: valueText(entry.from),
        to: valueText(entry.to),
      }),
      before: null,
      after: null,
    }
  })
})
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <div>
      <h1 class="text-lg font-semibold">{{ t('core.audit.title') }}</h1>
      <p class="text-muted-foreground text-sm">{{ t('core.audit.intro') }}</p>
    </div>

    <LoadingState v-if="!facetsSettled" />

    <DataTable
      v-else
      table-id="core.audit"
      :caption="t('core.audit.caption')"
      :columns="columns"
      mode="cursor"
      :fetcher="fetchAuditLogs"
      :filters="filters"
      url-state
      :export-config="exportConfig"
      :empty-title="t('core.audit.empty.title')"
      :empty-text="t('core.audit.empty.text')"
      :card-heading-level="2"
    >
      <template #cell-actions="{ row }">
        <Button
          type="button"
          variant="outline"
          size="sm"
          :aria-label="
            t('core.audit.viewChangesFor', {
              event: eventLabel(row.event),
              entity: entityLabel(row.auditable_type),
              date: formatDateTime(row.occurred_at) ?? '',
            })
          "
          @click="selected = row"
        >
          {{ t('core.audit.viewChanges') }}
        </Button>
      </template>
    </DataTable>

    <Sheet v-model:open="panelOpen">
      <SheetContent
        side="right"
        :close-label="t('core.audit.changes.close')"
        class="overflow-y-auto"
      >
        <template v-if="selected">
          <SheetHeader>
            <SheetTitle>{{ t('core.audit.changes.title') }}</SheetTitle>
            <SheetDescription>{{ describe(selected) }}</SheetDescription>
          </SheetHeader>

          <div class="px-4 pb-4">
            <p v-if="changeRows.length === 0" class="text-muted-foreground text-sm">
              {{ t('core.audit.changes.none') }}
            </p>
            <dl v-else class="flex flex-col gap-3 text-sm">
              <div v-for="row in changeRows" :key="row.attribute" class="flex flex-col gap-0.5">
                <dt class="font-mono text-xs font-medium">{{ row.attribute }}</dt>
                <dd>
                  {{ row.text }}
                  <template v-if="row.redacted">
                    <span class="text-muted-foreground block text-xs">{{
                      t('core.audit.changes.reasonLabel', { reason: row.redacted })
                    }}</span>
                    <span v-if="row.before" class="text-muted-foreground block text-xs">{{
                      `${t('core.audit.changes.before')}: ${row.before}`
                    }}</span>
                    <span v-if="row.after" class="text-muted-foreground block text-xs">{{
                      `${t('core.audit.changes.after')}: ${row.after}`
                    }}</span>
                  </template>
                </dd>
              </div>
            </dl>
          </div>
        </template>
      </SheetContent>
    </Sheet>
  </div>
</template>
