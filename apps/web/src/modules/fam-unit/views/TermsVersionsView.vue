<script setup lang="ts">
/**
 * `/configuracion/condiciones-de-uso` (`docs/modulos/REQ-FAM-UNIT/funcional.md §10`, `§4.9`, `REQ-FAM-UNIT-003`/`-004`;
 * `1.14-d`). Versiones de los textos legales del centro —condiciones de uso y texto del consentimiento de imagen de cada
 * nivel— con el componente de tablas de `ADR-054`: modo `page`, estado en la URL, filtros por tipo (`kind`) y estado.
 * Acciones por versión según su permiso **y** su estado: leer el texto, editar el borrador con el campo traducible de
 * `ADR-062 §5.7`, borrarlo y publicarlo (la publicada anterior del mismo tipo pasa a `retirada`). Una versión publicada
 * es inmutable (`409 not_draft`): ninguna acción de edición se ofrece sobre ella. Con `aceptacion_condiciones.leer`,
 * debajo, la consulta de las aceptaciones (`D-32`). El servidor decide igualmente (`INV-002`).
 */
import { computed, ref } from 'vue'
import { CircleCheck, FilePen, History } from '@lucide/vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import ActionNotices from '../components/ActionNotices.vue'
import StatusBadge from '../components/StatusBadge.vue'
import TermsPublishDialog from '../components/TermsPublishDialog.vue'
import TermsTextDialog from '../components/TermsTextDialog.vue'
import TermsVersionFormDialog from '../components/TermsVersionFormDialog.vue'
import {
  createTermsVersion,
  deleteTermsVersion,
  getTermsVersion,
  listTermsAcceptances,
  listTermsVersions,
  publishTermsVersion,
  updateTermsVersion,
} from '../api'
import { useActions } from '../composables/useActions'
import { useLabels } from '../composables/useLabels'
import { usePermissions } from '../composables/usePermissions'
import type { TermsAcceptance, TermsVersion } from '../types'

const KINDS = ['condiciones_uso', 'consentimiento_imagen_web', 'consentimiento_imagen_redes']
const STATUSES = ['borrador', 'publicada', 'retirada']

const t = useT()
const { can } = usePermissions()
const { formatDateTime } = useDataTableFormatters()
const labels = useLabels()
const actions = useActions()

const canCreate = computed(() => can('texto_legal.crear'))
const canUpdate = computed(() => can('texto_legal.actualizar'))
const canDelete = computed(() => can('texto_legal.eliminar'))
const canPublish = computed(() => can('texto_legal.publicar'))
const canReadAcceptances = computed(() => can('aceptacion_condiciones.leer'))

const table = ref<{ refresh: () => Promise<void> } | null>(null)
const formOpen = ref(false)
const editing = ref<TermsVersion | null>(null)
const publishOpen = ref(false)
const publishing = ref<TermsVersion | null>(null)
const textOpen = ref(false)
const reading = ref<TermsVersion | null>(null)

const filters: DataTableFilter[] = [
  {
    type: 'enum',
    id: 'kind',
    labelKey: 'famUnit.fields.kind',
    options: KINDS.map((value) => ({ value, labelKey: `famUnit.enums.termsKind.${value}` })),
  },
  {
    type: 'enum',
    id: 'status',
    labelKey: 'famUnit.fields.status',
    options: STATUSES.map((value) => ({ value, labelKey: `famUnit.enums.termsStatus.${value}` })),
  },
]

const columns = computed<DataTableColumn<TermsVersion>[]>(() => [
  {
    id: 'version_number',
    headerKey: 'famUnit.fields.versionNumber',
    sortable: true,
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'kind',
    headerKey: 'famUnit.fields.kind',
    value: (row) => labels.termsKindLabel(row.kind),
    card: 'subtitle',
  },
  { id: 'title', headerKey: 'famUnit.fields.title', card: 'field' },
  { id: 'status', headerKey: 'famUnit.fields.status', card: 'field' },
  {
    id: 'published_at',
    headerKey: 'famUnit.fields.publishedAt',
    value: (row) => formatDateTime(row.published_at),
    card: 'field',
  },
  { id: 'actions', headerKey: 'famUnit.fields.actions', hideable: false, card: 'actions' },
])

const fetchVersions: DataTableFetcher<TermsVersion> = (query) =>
  listTermsVersions({
    kind: query.filters.kind,
    status: query.filters.status,
    sort: query.sort,
    page: query.page,
    per_page: query.per_page,
  })

const acceptanceColumns: DataTableColumn<TermsAcceptance>[] = [
  {
    id: 'accepted_at',
    headerKey: 'famUnit.fields.acceptedAt',
    value: (row) => formatDateTime(row.accepted_at),
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'version_number',
    headerKey: 'famUnit.fields.versionNumber',
    value: (row) => row.version_number,
    card: 'field',
  },
  { id: 'shown_locale', headerKey: 'famUnit.fields.shownLocale', card: 'field' },
  { id: 'user', headerKey: 'famUnit.fields.user', card: 'field' },
]

const fetchAcceptances: DataTableFetcher<TermsAcceptance> = (query) =>
  listTermsAcceptances({ cursor: query.cursor })

function statusIcon(status: string) {
  if (status === 'publicada') return CircleCheck

  return status === 'borrador' ? FilePen : History
}

function statusVariant(status: string): 'outline' | 'secondary' | 'default' {
  if (status === 'publicada') return 'default'

  return status === 'borrador' ? 'outline' : 'secondary'
}

function titleOf(row: TermsVersion): string {
  return `${labels.termsKindLabel(row.kind)} · ${t('famUnit.terms.versionNumber', { number: row.version_number })}`
}

function startCreate(): void {
  editing.value = null
  formOpen.value = true
}

async function startEdit(row: TermsVersion): Promise<void> {
  actions.clear()

  try {
    editing.value = await getTermsVersion(row.public_id)
    formOpen.value = true
  } catch (err) {
    actions.fail(err)
  }
}

async function startRead(row: TermsVersion): Promise<void> {
  actions.clear()

  try {
    reading.value = await getTermsVersion(row.public_id)
    textOpen.value = true
  } catch (err) {
    actions.fail(err)
  }
}

function startPublish(row: TermsVersion): void {
  actions.clear()
  publishing.value = row
  publishOpen.value = true
}

function save(body: Record<string, unknown>): Promise<unknown> {
  const current = editing.value

  return current === null
    ? createTermsVersion(body as unknown as Parameters<typeof createTermsVersion>[0])
    : updateTermsVersion(current.public_id, body)
}

function publish(
  version: TermsVersion,
  requiresReacceptance: boolean | undefined,
): Promise<unknown> {
  return publishTermsVersion(version.public_id, requiresReacceptance)
}

async function afterSave(): Promise<void> {
  const created = editing.value === null

  actions.clear()
  actions.message.value = t(created ? 'famUnit.flash.termsCreated' : 'famUnit.flash.termsUpdated')
  await table.value?.refresh()
}

async function afterPublish(): Promise<void> {
  actions.clear()
  actions.message.value = t('famUnit.flash.termsPublished')
  await table.value?.refresh()
}

async function remove(row: TermsVersion): Promise<void> {
  const name = titleOf(row)
  const done = await actions.confirmAndRun(
    {
      title: t('famUnit.terms.confirmDelete.title', { name }),
      description: t('famUnit.terms.confirmDelete.description'),
      confirmLabel: t('famUnit.terms.confirmDelete.confirm', { name }),
      destructive: true,
    },
    () => deleteTermsVersion(row.public_id),
    t('famUnit.flash.termsDeleted'),
  )

  if (done) {
    await table.value?.refresh()
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-lg font-semibold">{{ t('famUnit.terms.title') }}</h1>
        <p class="text-muted-foreground text-sm">{{ t('famUnit.terms.intro') }}</p>
      </div>
      <Button v-if="canCreate" type="button" @click="startCreate">
        {{ t('famUnit.terms.new') }}
      </Button>
    </div>

    <ActionNotices :message="actions.message.value" :action-error="actions.actionError.value" />

    <section aria-labelledby="fam-unit-terms-versions" class="flex flex-col gap-3">
      <h2 id="fam-unit-terms-versions" class="text-sm font-semibold">
        {{ t('famUnit.terms.versionsHeading') }}
      </h2>
      <DataTable
        ref="table"
        table-id="fam_unit.terms_versions"
        :caption="t('famUnit.terms.caption')"
        :columns="columns"
        mode="page"
        :fetcher="fetchVersions"
        :filters="filters"
        url-state
        :empty-title="t('famUnit.terms.empty.title')"
        :empty-text="canCreate ? t('famUnit.terms.empty.text') : ''"
        :empty-action-label="canCreate ? t('famUnit.terms.new') : undefined"
        :card-heading-level="3"
        @empty-action="startCreate"
      >
        <template #cell-status="{ row }">
          <StatusBadge
            :text="labels.termsStatusLabel(row.status)"
            :icon="statusIcon(row.status)"
            :variant="statusVariant(row.status)"
          />
        </template>
        <template #cell-actions="{ row }">
          <div class="flex flex-wrap justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              :aria-label="t('famUnit.terms.readFor', { name: titleOf(row) })"
              @click="startRead(row)"
            >
              {{ t('famUnit.terms.read') }}
            </Button>
            <Button
              v-if="canUpdate && row.status === 'borrador'"
              type="button"
              variant="outline"
              size="sm"
              :aria-label="t('famUnit.terms.editFor', { name: titleOf(row) })"
              @click="startEdit(row)"
            >
              {{ t('famUnit.terms.edit') }}
            </Button>
            <Button
              v-if="canPublish && row.status === 'borrador'"
              type="button"
              variant="outline"
              size="sm"
              :aria-label="t('famUnit.terms.publishFor', { name: titleOf(row) })"
              @click="startPublish(row)"
            >
              {{ t('famUnit.terms.publishAction') }}
            </Button>
            <Button
              v-if="canDelete && row.status === 'borrador'"
              type="button"
              variant="outline"
              size="sm"
              :disabled="actions.busy.value"
              :aria-label="t('famUnit.terms.deleteFor', { name: titleOf(row) })"
              @click="remove(row)"
            >
              {{ t('famUnit.terms.delete') }}
            </Button>
          </div>
        </template>
      </DataTable>
    </section>

    <section
      v-if="canReadAcceptances"
      aria-labelledby="fam-unit-terms-acceptances"
      class="flex flex-col gap-3"
    >
      <h2 id="fam-unit-terms-acceptances" class="text-sm font-semibold">
        {{ t('famUnit.terms.acceptances.heading') }}
      </h2>
      <DataTable
        table-id="fam_unit.terms_acceptances"
        :caption="t('famUnit.terms.acceptances.caption')"
        :columns="acceptanceColumns"
        mode="cursor"
        :fetcher="fetchAcceptances"
        :empty-title="t('famUnit.terms.acceptances.empty')"
        :card-heading-level="3"
      />
    </section>

    <TermsVersionFormDialog
      v-model:open="formOpen"
      :version="editing"
      :save="save"
      @saved="afterSave"
    />
    <TermsPublishDialog
      v-model:open="publishOpen"
      :version="publishing"
      :save="publish"
      @saved="afterPublish"
    />
    <TermsTextDialog v-model:open="textOpen" :version="reading" />

    <ConfirmDialog
      :open="actions.confirmation.open.value"
      :request="actions.confirmation.request.value"
      @confirm="actions.confirmation.confirm"
      @cancel="actions.confirmation.cancel"
    />
  </div>
</template>
