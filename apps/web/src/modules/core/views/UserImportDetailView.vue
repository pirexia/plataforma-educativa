<script setup lang="ts">
/**
 * `/administracion/importaciones/:publicId` (`docs/modulos/REQ-CORE/funcional.md
 * §14.6.2`, `RN-CORE-72`/`-73`/`-74`/`-64`, 1.9c). Detalle de un lote: estado,
 * recuentos, fechas e incidencias, con sus acciones.
 *
 * - **Seguimiento** (`RN-CORE-72`, `useUserImport`): mientras el lote está
 *   `subido`, `validando` o `ejecutando` se consulta con la política de
 *   `RN-CORE-49`. El estado se anuncia con `role="status"` en cada cambio de
 *   estado, no en cada consulta (el texto solo cambia con el estado).
 * - **Ejecución idempotente** (`RN-CORE-73`, `INV-011`): «Ejecutar» solo en
 *   `validado`, con confirmación que dice cuántas filas se crearán
 *   (`row_count − error_count`), que las filas con error se omiten, si se
 *   enviarán invitaciones y que **una importación no se deshace**. Cada
 *   confirmación genera **una** `Idempotency-Key` ULID; si la petición falla
 *   sin respuesta (red, `5xx`), «Reintentar» reutiliza **la misma** clave; una
 *   confirmación nueva genera una clave nueva. Un `Idempotency-Replayed` es un
 *   `202` y se trata como éxito sin más. `409` muestra su `detail` y no
 *   reintenta.
 * - **Incidencias** (`RN-CORE-74`): `error_summary` con el componente de tablas
 *   (`RN-CORE-53`), como una única página sin filtros, orden ni exportación;
 *   `message` tal cual llega (idioma de quien subió el lote, #285). Si hay más
 *   incidencias que las recibidas, aviso y enlace al informe completo
 *   (`report_url`, URL firmada: sin `Blob`, `RN-CORE-46`).
 * - **Descartar** en `subido`, `validando`, `validado` y `fallido`, con
 *   confirmación; `409` si ya se ejecutó.
 * - **Informe caducado**: `report_url` caduca a los 15 min; se vuelve a pedir
 *   el detalle si han pasado más de 10 min desde la última respuesta.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/components/useConfirm'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
} from '@/data-table'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { resolveErrorState } from '@/layouts/errorState'
import { ulid } from '@/lib/ulid'
import { deleteUserImport, executeUserImport } from '../api'
import { problemDetail, problemRetryAfter, problemStatus } from '../composables/problem'
import { setFlash } from '../composables/useFlash'
import { useUserImport } from '../composables/useUserImport'
import { USER_IMPORT_HEADER } from '../userImportHeader'
import type { UserImportErrorEntry } from '../types'

/** Margen sobre el TTL de 15 min de `report_url` (`CORE_SIGNED_URL_TTL_MINUTES`). */
const REPORT_REFRESH_AFTER_MS = 10 * 60 * 1000
const REPORT_CHECK_EVERY_MS = 30 * 1000

const t = useT()
const route = useRoute()
const router = useRouter()
const { formatDateTime, formatNumber } = useDataTableFormatters()
const confirmation = useConfirm()

const publicId = computed(() => String(route.params.publicId))
const { data, loading, error, stalled, lastResponseAt, load, refresh, replace, checkAgain } =
  useUserImport(() => publicId.value)

const loadError = computed(() =>
  error.value !== null && data.value === null ? resolveErrorState(error.value) : null,
)

const busy = ref(false)
const message = ref<string | null>(null)
const actionError = ref<string | null>(null)
/** Clave de la confirmación cuya petición falló sin respuesta: «Reintentar» la reutiliza. */
const retryKey = ref<string | null>(null)

function translated(key: string, fallback: string): string {
  const label = t(key)

  return label === key ? fallback : label
}

const statusText = computed(() =>
  data.value ? translated(`core.userImport.status.${data.value.status}`, data.value.status) : '',
)

const canExecute = computed(() => data.value?.status === 'validado')
const canDiscard = computed(
  () =>
    data.value !== null &&
    ['subido', 'validando', 'validado', 'fallido'].includes(data.value.status),
)
const inProgress = computed(
  () => data.value !== null && ['subido', 'validando', 'ejecutando'].includes(data.value.status),
)

const entries = computed<UserImportErrorEntry[]>(() => data.value?.error_summary ?? [])
const showErrors = computed(
  () =>
    data.value !== null &&
    ['validado', 'fallido'].includes(data.value.status) &&
    entries.value.length > 0,
)
/** `error_summary` trae como mucho 50 entradas (`api.md §7`). */
const ERROR_SUMMARY_CAP = 50

/**
 * `RN-CORE-74`: hay más incidencias de las mostradas si `error_count` supera las
 * recibidas **o** si las entradas llegan al tope de 50 (`error_count` cuenta filas,
 * no incidencias: 30 filas con 2 errores se truncan a 50 con `error_count` = 30).
 */
const truncated = computed(
  () =>
    data.value !== null &&
    ((data.value.error_count ?? 0) > entries.value.length ||
      entries.value.length >= ERROR_SUMMARY_CAP),
)
/** Lote `fallido` por cabecera: se muestra el motivo y la cabecera esperada, sin «Ejecutar». */
const headerFailed = computed(
  () =>
    data.value?.status === 'fallido' && entries.value.some((entry) => entry.column === 'header'),
)

/**
 * Solo `https:`: Vue no filtra el esquema de `href` (issue #275, `CA-CORE-190`). `http:`
 * únicamente en desarrollo (MinIO local sin TLS, `import.meta.env.DEV`); en un
 * despliegue de producción una URL firmada con `http:` no se ofrece
 * (security-reviewer S5).
 */
const reportHref = computed<string | null>(() => {
  const url = data.value?.report_url

  if (!url) {
    return null
  }

  try {
    const { protocol } = new URL(url, window.location.origin)

    return protocol === 'https:' || (import.meta.env.DEV && protocol === 'http:') ? url : null
  } catch {
    return null
  }
})

/** Cuántas filas se crearán al ejecutar (`RN-CORE-73`). */
const willCreate = computed(() =>
  data.value ? Math.max((data.value.row_count ?? 0) - (data.value.error_count ?? 0), 0) : 0,
)

interface ErrorRow extends UserImportErrorEntry {
  key: string
}

const columns: DataTableColumn<ErrorRow>[] = [
  {
    id: 'line',
    headerKey: 'core.userImportErrors.columns.line',
    value: (row) => row.line,
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'column',
    headerKey: 'core.userImportErrors.columns.column',
    value: (row) => row.column,
    card: 'subtitle',
  },
  {
    id: 'message',
    headerKey: 'core.userImportErrors.columns.message',
    value: (row) => row.message,
    card: 'field',
  },
]

/**
 * `RN-CORE-74`: `error_summary` es un campo del recurso, no un listado
 * paginado. Se entrega al componente como una única página, sin filtros,
 * orden ni exportación (no es el modo `local`, que no existe, `OPEN-CORE-26`).
 */
const fetchErrors: DataTableFetcher<ErrorRow> = async () => {
  const rows = entries.value.map((entry, index) => ({ ...entry, key: String(index) }))

  return {
    data: rows,
    meta: { current_page: 1, per_page: 50, total: rows.length, last_page: 1 },
  }
}

function fail(err: unknown): void {
  const status = problemStatus(err)

  if (status === 429) {
    const seconds = problemRetryAfter(err)

    actionError.value =
      seconds !== null
        ? t('core.users.errors.tooManyRequestsWithSeconds', { seconds })
        : t('core.users.errors.tooManyRequests')

    return
  }

  actionError.value = problemDetail(err) ?? t('core.users.errors.unexpected')
}

/** Sin respuesta del servidor: red caída (`0`) o `5xx`. Se puede reintentar la misma confirmación. */
function isTransportFailure(err: unknown): boolean {
  const status = problemStatus(err)

  return status === 0 || (status !== null && status >= 500)
}

async function sendExecute(key: string): Promise<void> {
  busy.value = true
  actionError.value = null
  message.value = null

  try {
    const executing = await executeUserImport(publicId.value, key)

    retryKey.value = null
    replace(executing)
    message.value = t('core.userImports.flash.executing')
  } catch (err) {
    // 409 incluido: se muestra su `detail` y no se reintenta (`RN-CORE-73`).
    retryKey.value = isTransportFailure(err) ? key : null
    fail(err)

    if (problemStatus(err) === 409) {
      await refresh()
    }
  } finally {
    busy.value = false
  }
}

async function execute(): Promise<void> {
  const current = data.value

  if (!current) {
    return
  }

  const invitations = current.send_invitations
  const confirmed = await confirmation.ask({
    title: t('core.userImports.confirm.execute.title', { file: current.original_filename }),
    description: [
      t('core.userImports.confirm.execute.creates', { count: willCreate.value }),
      t('core.userImports.confirm.execute.skipsErrors'),
      invitations
        ? t('core.userImports.confirm.execute.invitationsYes')
        : t('core.userImports.confirm.execute.invitationsNo'),
      t('core.userImports.confirm.execute.irreversible'),
    ].join(' '),
    confirmLabel: t('core.userImports.confirm.execute.confirm', {
      file: current.original_filename,
    }),
  })

  if (!confirmed) {
    return
  }

  // Una confirmación nueva = una clave nueva (`RN-CORE-73`).
  await sendExecute(ulid())
}

async function retry(): Promise<void> {
  if (retryKey.value !== null) {
    await sendExecute(retryKey.value)
  }
}

async function discard(): Promise<void> {
  const current = data.value

  if (!current) {
    return
  }

  const confirmed = await confirmation.ask({
    title: t('core.userImports.confirm.discard.title', { file: current.original_filename }),
    description: t('core.userImports.confirm.discard.description'),
    confirmLabel: t('core.userImports.confirm.discard.confirm', {
      file: current.original_filename,
    }),
    destructive: true,
  })

  if (!confirmed) {
    return
  }

  busy.value = true
  actionError.value = null
  message.value = null

  try {
    await deleteUserImport(publicId.value)
    setFlash({
      key: 'core.userImports.flash.discarded',
      params: { file: current.original_filename },
    })
    await router.push({ name: 'core-user-imports' })
  } catch (err) {
    fail(err)

    if (problemStatus(err) === 409) {
      await refresh()
    }
  } finally {
    busy.value = false
  }
}

// --- Enlace firmado del informe -------------------------------------------

let reportTimer: ReturnType<typeof setInterval> | null = null

function reportMayHaveExpired(): boolean {
  return (
    reportHref.value !== null &&
    lastResponseAt.value > 0 &&
    Date.now() - lastResponseAt.value > REPORT_REFRESH_AFTER_MS
  )
}

onMounted(() => {
  void load()

  reportTimer = setInterval(() => {
    if (reportMayHaveExpired()) {
      void refresh()
    }
  }, REPORT_CHECK_EVERY_MS)
})

onBeforeUnmount(() => {
  if (reportTimer !== null) {
    clearInterval(reportTimer)
    reportTimer = null
  }
})
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <LoadingState v-if="loading" />
    <ErrorState v-else-if="loadError" :state="loadError" @retry="load" />

    <template v-else-if="data">
      <div>
        <h1 class="text-lg font-semibold">
          {{ t('core.userImports.detail.title', { file: data.original_filename }) }}
        </h1>
        <p class="text-muted-foreground text-sm">{{ t('core.userImports.detail.intro') }}</p>
      </div>

      <p
        v-if="message"
        role="status"
        class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
      >
        {{ message }}
      </p>
      <p v-if="actionError" role="alert" class="text-destructive text-sm">{{ actionError }}</p>

      <section class="flex flex-col gap-2" aria-labelledby="user-import-state-title">
        <h2 id="user-import-state-title" class="text-sm font-semibold">
          {{ t('core.userImports.detail.state') }}
        </h2>

        <p role="status" data-testid="user-import-status" class="text-sm">
          {{ t('core.userImports.detail.statusLine', { status: statusText }) }}
        </p>

        <div v-if="stalled && inProgress" class="flex flex-wrap items-center gap-3">
          <p class="text-sm">{{ t('core.userImports.detail.stalled') }}</p>
          <Button type="button" variant="outline" size="sm" @click="checkAgain">
            {{ t('core.userImports.detail.checkAgain') }}
          </Button>
        </div>

        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('core.userImports.detail.rows') }}</dt>
            <dd>
              {{
                data.row_count === null ? t('dataTable.emptyValue') : formatNumber(data.row_count)
              }}
            </dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('core.userImports.detail.errors') }}</dt>
            <dd>
              {{
                data.error_count === null
                  ? t('dataTable.emptyValue')
                  : formatNumber(data.error_count)
              }}
            </dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">
              {{ t('core.userImports.detail.created') }}
            </dt>
            <dd>
              {{
                data.created_count === null
                  ? t('dataTable.emptyValue')
                  : formatNumber(data.created_count)
              }}
            </dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">
              {{ t('core.userImports.detail.uploadedAt') }}
            </dt>
            <dd>{{ formatDateTime(data.created_at) }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">
              {{ t('core.userImports.detail.validatedAt') }}
            </dt>
            <dd>
              {{
                data.validated_at ? formatDateTime(data.validated_at) : t('dataTable.emptyValue')
              }}
            </dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">
              {{ t('core.userImports.detail.executedAt') }}
            </dt>
            <dd>
              {{ data.executed_at ? formatDateTime(data.executed_at) : t('dataTable.emptyValue') }}
            </dd>
          </div>
        </dl>
      </section>

      <section
        v-if="headerFailed"
        class="flex flex-col gap-2"
        aria-labelledby="user-import-header-failed-title"
      >
        <h2 id="user-import-header-failed-title" class="text-sm font-semibold">
          {{ t('core.userImports.detail.headerFailed.title') }}
        </h2>
        <p class="text-sm">{{ entries[0]?.message }}</p>
        <p class="text-muted-foreground text-xs">
          {{ t('core.userImports.detail.headerFailed.expected') }}
        </p>
        <pre
          class="bg-muted overflow-x-auto rounded-lg px-3 py-2 text-xs"
          tabindex="0"
        ><code>{{ USER_IMPORT_HEADER }}</code></pre>
      </section>

      <section
        v-if="showErrors && !headerFailed"
        class="flex flex-col gap-3"
        aria-labelledby="user-import-errors-title"
      >
        <h2 id="user-import-errors-title" class="text-sm font-semibold">
          {{ t('core.userImports.detail.incidents') }}
        </h2>

        <p
          v-if="truncated"
          role="note"
          class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
        >
          {{
            t('core.userImports.detail.truncated', {
              shown: entries.length,
              total: data.error_count ?? 0,
            })
          }}
        </p>

        <DataTable
          :key="`${data.status}-${entries.length}`"
          table-id="core.user_import_errors"
          :caption="t('core.userImportErrors.caption')"
          :columns="columns"
          mode="page"
          :fetcher="fetchErrors"
          :row-key="(row: ErrorRow) => row.key"
          :empty-title="t('core.userImportErrors.empty')"
          :card-heading-level="3"
        />
      </section>

      <div v-if="reportHref" class="flex flex-wrap items-center gap-3">
        <a
          :href="reportHref"
          rel="noreferrer noopener"
          class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm text-sm font-medium underline underline-offset-4 outline-none focus-visible:ring-3"
        >
          {{ t('core.userImports.detail.report') }}
        </a>
        <Button type="button" variant="outline" size="sm" @click="refresh">
          {{ t('core.userImports.detail.refreshReport') }}
        </Button>
      </div>

      <div class="flex flex-wrap gap-2">
        <Button v-if="canExecute" type="button" :disabled="busy" @click="execute">
          {{ t('core.userImports.detail.execute') }}
        </Button>
        <Button
          v-if="retryKey !== null"
          type="button"
          variant="outline"
          :disabled="busy"
          @click="retry"
        >
          {{ t('core.userImports.detail.retry') }}
        </Button>
        <Button
          v-if="canDiscard"
          type="button"
          variant="destructive"
          :disabled="busy"
          @click="discard"
        >
          {{ t('core.userImports.detail.discard') }}
        </Button>
        <Button variant="outline" as-child>
          <RouterLink :to="{ name: 'core-user-imports' }">{{
            t('core.userImports.detail.back')
          }}</RouterLink>
        </Button>
      </div>
    </template>

    <ConfirmDialog
      :open="confirmation.open.value"
      :request="confirmation.request.value"
      @confirm="confirmation.confirm"
      @cancel="confirmation.cancel"
    />
  </div>
</template>
