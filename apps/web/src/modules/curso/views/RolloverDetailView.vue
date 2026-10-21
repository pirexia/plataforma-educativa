<script setup lang="ts">
/**
 * `/administracion/cursos/:publicId/copias/:rolloverId` (`docs/modulos/REQ-CURSO/funcional.md §10.6`, §4.7, 1.12b,
 * `REQ-CURSO-002`). Detalle de una copia hacia el curso `:publicId`: **estado traducido (no solo color)**, resultado por
 * elemento al terminar, motivo y entradas del fallo si `fallido`.
 *
 * - Mientras está en vuelo (`pendiente`, `en_curso`, `revirtiendo`) consulta `GET /academic-year-rollovers/{id}` con espera
 *   creciente de 2 s a 30 s y tope de 10 min (`useRollover`, patrón de `ADR-054`); pasado el tope, el texto «La copia sigue
 *   en cola…» sin error y **«Comprobar de nuevo»**. Región `aria-live="polite"` para el cambio de estado.
 * - «Revertir» (`apertura_curso_academico.eliminar`), solo en `completado` y con el destino en `planificacion`
 *   (`revertible`); su `ConfirmDialog` advierte de que **se pierden los cambios hechos sobre lo copiado**. Un `409
 *   rollover_revert_blocked` se muestra con el `message` de cada entrada.
 * - «Cancelar copia» (`apertura_curso_academico.eliminar`), solo en `pendiente`, `en_curso` o `revirtiendo` pasados 30 minutos
 *   de su último cambio (`cancellable_at`, `RN-CURSO-85`); antes de ese plazo no se ofrece.
 *
 * La interfaz oculta; el servidor decide (`INV-002`): los permisos se leen de `/me`, nunca de un rol (`RN-CORE-61`).
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/components/useConfirm'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableLocalFetcher,
} from '@/data-table'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { resolveErrorState } from '@/layouts/errorState'
import { cancelRollover, getAcademicYear, revertRollover } from '../api'
import { problemDetail, problemErrorEntries } from '../composables/problem'
import { usePermissions } from '../composables/usePermissions'
import { isRolloverInFlight, useRollover } from '../composables/useRollover'
import type { AcademicYear, Rollover, RolloverIssue } from '../types'

const t = useT()
const route = useRoute()
const { can } = usePermissions()
const { formatDateTime } = useDataTableFormatters()
const confirmation = useConfirm()

const yearId = computed(() => String(route.params.publicId))
const { data, loading, error, stalled, load, replace, checkAgain } = useRollover(() =>
  String(route.params.rolloverId),
)
const loadError = computed(() => (error.value === null ? null : resolveErrorState(error.value)))

const year = ref<AcademicYear | null>(null)
const busy = ref(false)
const actionError = ref<string | null>(null)
const actionEntries = ref<string[]>([])
/** Texto del cambio de estado que anuncia la región `aria-live`. */
const announcement = ref('')

/** Instante de referencia para ofrecer «Cancelar copia» sin recargar: se renueva cada 30 s. */
const now = ref(Date.now())
let ticker: ReturnType<typeof setInterval> | null = null

function statusLabel(status: string): string {
  const key = `curso.rollover.status.${status}`
  const label = t(key)

  return label === key ? status : label
}

function failureLabel(code: string): string {
  const key = `curso.rollover.failure.${code}`
  const label = t(key)

  return label === key ? code : label
}

const inFlight = computed(() => data.value !== null && isRolloverInFlight(data.value.status))
const canRevert = computed(
  () =>
    data.value?.status === 'completado' &&
    data.value.revertible &&
    can('apertura_curso_academico.eliminar'),
)
const canCancel = computed(() => {
  const cancellableAt = data.value?.cancellable_at

  return (
    can('apertura_curso_academico.eliminar') &&
    typeof cancellableAt === 'string' &&
    Date.parse(cancellableAt) <= now.value
  )
})

type ResultRow = NonNullable<Rollover['result']>['elements'][number]

/** `RN-CORE-53`: toda tabla es el componente común; aquí en modo `local`. */
const resultColumns: DataTableColumn<ResultRow>[] = [
  {
    id: 'label',
    headerKey: 'curso.rollover.wizard.columns.element',
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'copied',
    headerKey: 'curso.rollover.detail.copied',
    value: (row) => String(row.copied),
    card: 'field',
  },
  {
    id: 'notes',
    headerKey: 'curso.rollover.wizard.columns.notes',
    value: (row) =>
      [...row.skipped, ...row.adjusted].map((item) => `${item.label}: ${item.count}`).join(' · '),
    card: 'field',
  },
]

const fetchResultRows: DataTableLocalFetcher<ResultRow> = () =>
  Promise.resolve({ data: data.value?.result?.elements ?? [] })

function issueMessages(issues: RolloverIssue[] | undefined): string[] {
  return (issues ?? []).map((issue) => issue.message)
}

watch(
  () => data.value?.status,
  (status, previous) => {
    if (status !== undefined && previous !== undefined && status !== previous) {
      announcement.value = t('curso.rollover.detail.statusChanged', { status: statusLabel(status) })
    }
  },
)

onMounted(() => {
  void load()
  void getAcademicYear(yearId.value)
    .then((loaded) => {
      year.value = loaded
    })
    .catch(() => undefined)
  ticker = setInterval(() => {
    now.value = Date.now()
  }, 30_000)
})

onBeforeUnmount(() => {
  if (ticker !== null) {
    clearInterval(ticker)
  }
})

async function run(action: () => Promise<void>): Promise<void> {
  busy.value = true
  actionError.value = null
  actionEntries.value = []

  try {
    await action()
  } catch (err) {
    actionError.value = problemDetail(err) ?? t('curso.errors.unexpected')
    actionEntries.value = [
      ...problemErrorEntries(err, 'rollover_revert'),
      ...problemErrorEntries(err, 'rollover'),
    ].map((entry) => entry.message)
  } finally {
    busy.value = false
  }
}

async function revert(): Promise<void> {
  const current = data.value

  if (!current) {
    return
  }

  const confirmed = await confirmation.ask({
    title: t('curso.rollover.detail.revertConfirm.title', { target: year.value?.code ?? '' }),
    description: t('curso.rollover.detail.revertConfirm.description'),
    confirmLabel: t('curso.rollover.detail.revertConfirm.confirm', {
      target: year.value?.code ?? '',
    }),
    destructive: true,
  })

  if (!confirmed) {
    return
  }

  await run(async () => {
    replace(await revertRollover(current.public_id))
  })
}

async function cancel(): Promise<void> {
  const current = data.value

  if (!current) {
    return
  }

  const confirmed = await confirmation.ask({
    title: t('curso.rollover.detail.cancelConfirm.title'),
    description: t('curso.rollover.detail.cancelConfirm.description'),
    confirmLabel: t('curso.rollover.detail.cancelConfirm.confirm'),
    destructive: true,
  })

  if (!confirmed) {
    return
  }

  await run(async () => {
    replace(await cancelRollover(current.public_id))
  })
}
</script>

<template>
  <div class="mx-auto flex max-w-4xl flex-col gap-6 px-4 py-6">
    <LoadingState v-if="loading" />

    <ErrorState v-else-if="loadError" :state="loadError" @retry="load" />

    <template v-else-if="data">
      <header class="flex flex-col gap-1">
        <h1 class="text-lg font-semibold">{{ t('curso.rollover.detail.title') }}</h1>
        <p class="text-muted-foreground text-sm">
          <RouterLink
            :to="{ name: 'curso-academic-year-detail', params: { publicId: yearId } }"
            class="underline underline-offset-4"
          >
            {{ t('curso.rollover.detail.backToYear', { code: year?.code ?? '' }) }}
          </RouterLink>
        </p>
      </header>

      <!-- El estado, siempre con texto; el cambio de estado se anuncia en la región `aria-live`. -->
      <p class="text-sm" data-testid="rollover-status">
        <span class="text-muted-foreground">{{ t('curso.rollover.detail.status') }}:</span>
        <strong class="ml-1">{{ statusLabel(data.status) }}</strong>
      </p>
      <p role="status" aria-live="polite" class="sr-only" data-testid="rollover-announcement">
        {{ announcement }}
      </p>

      <p v-if="inFlight && !stalled" class="text-muted-foreground text-sm">
        {{ t('curso.rollover.detail.inProgress') }}
      </p>

      <div v-if="stalled && inFlight" class="flex flex-col items-start gap-2 text-sm">
        <p class="text-muted-foreground">{{ t('curso.rollover.detail.stalled') }}</p>
        <Button type="button" variant="outline" size="sm" @click="checkAgain">
          {{ t('curso.rollover.detail.checkAgain') }}
        </Button>
      </div>

      <div v-if="actionError" role="alert" class="text-destructive flex flex-col gap-1 text-sm">
        <p>{{ actionError }}</p>
        <ul v-if="actionEntries.length > 0" class="list-disc pl-5">
          <li v-for="entry in actionEntries" :key="entry">{{ entry }}</li>
        </ul>
      </div>

      <!-- Fallo de la copia (`fallido`): motivo y entradas, sin datos personales. -->
      <section
        v-if="data.status === 'fallido' && data.failure"
        class="flex flex-col gap-1"
        aria-labelledby="rollover-failure"
      >
        <h2 id="rollover-failure" class="text-sm font-semibold">
          {{ t('curso.rollover.detail.failureTitle') }}
        </h2>
        <p class="text-sm">{{ failureLabel(data.failure.code) }}</p>
        <ul v-if="data.failure.errors.length > 0" class="list-disc pl-5 text-sm">
          <li v-for="(message, index) in issueMessages(data.failure.errors)" :key="index">
            {{ message }}
          </li>
        </ul>
      </section>

      <!-- Último fallo de una reversión: la copia sigue completada. -->
      <section
        v-if="data.revert_failure"
        class="flex flex-col gap-1"
        aria-labelledby="rollover-revert-failure"
      >
        <h2 id="rollover-revert-failure" class="text-sm font-semibold">
          {{ t('curso.rollover.detail.revertFailureTitle') }}
        </h2>
        <p class="text-sm">{{ failureLabel(data.revert_failure.code) }}</p>
        <ul v-if="data.revert_failure.errors.length > 0" class="list-disc pl-5 text-sm">
          <li v-for="(message, index) in issueMessages(data.revert_failure.errors)" :key="index">
            {{ message }}
          </li>
        </ul>
      </section>

      <!-- Resultado por elemento. -->
      <section v-if="data.result" aria-labelledby="rollover-result">
        <h2 id="rollover-result" class="mb-2 text-sm font-semibold">
          {{ t('curso.rollover.detail.resultTitle') }}
        </h2>
        <DataTable
          :key="data.updated_at"
          table-id="curso.rollover_result"
          :caption="t('curso.rollover.detail.resultCaption')"
          :columns="resultColumns"
          mode="local"
          :fetcher="fetchResultRows"
          :empty-title="t('curso.rollover.detail.resultEmpty')"
          :card-heading-level="3"
          hide-single-page-footer
        >
          <template #cell-notes="{ row }">
            <ul
              v-if="row.skipped.length > 0 || row.adjusted.length > 0"
              class="list-disc pl-4 text-xs"
            >
              <li v-for="item in row.skipped" :key="`s-${item.reason}`">
                {{ item.label }}: {{ item.count }}
              </li>
              <li v-for="item in row.adjusted" :key="`a-${item.reason}`">
                {{ item.label }}: {{ item.count }}
              </li>
            </ul>
          </template>
        </DataTable>
      </section>

      <section class="flex flex-col gap-2" aria-labelledby="rollover-actions">
        <h2 id="rollover-actions" class="text-sm font-semibold">{{ t('curso.detail.actions') }}</h2>
        <div v-if="canRevert || canCancel" class="flex flex-wrap gap-2">
          <Button v-if="canRevert" type="button" variant="outline" :disabled="busy" @click="revert">
            {{ t('curso.rollover.detail.revert') }}
          </Button>
          <Button v-if="canCancel" type="button" variant="outline" :disabled="busy" @click="cancel">
            {{ t('curso.rollover.detail.cancel') }}
          </Button>
        </div>
        <p v-else class="text-muted-foreground text-sm">{{ t('curso.detail.noActions') }}</p>
      </section>

      <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-muted-foreground">{{ t('curso.rollover.detail.createdAt') }}</dt>
          <dd>{{ formatDateTime(data.created_at) }}</dd>
        </div>
        <div v-if="data.finished_at">
          <dt class="text-muted-foreground">{{ t('curso.rollover.detail.finishedAt') }}</dt>
          <dd>{{ formatDateTime(data.finished_at) }}</dd>
        </div>
        <div v-if="data.date_offset_days !== null">
          <dt class="text-muted-foreground">{{ t('curso.rollover.detail.offset') }}</dt>
          <dd>{{ t('curso.rollover.wizard.offsetValue', { days: data.date_offset_days }) }}</dd>
        </div>
      </dl>
    </template>

    <ConfirmDialog
      :open="confirmation.open.value"
      :request="confirmation.request.value"
      @confirm="confirmation.confirm"
      @cancel="confirmation.cancel"
    />
  </div>
</template>
