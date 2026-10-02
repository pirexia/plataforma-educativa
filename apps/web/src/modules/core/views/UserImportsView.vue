<script setup lang="ts">
/**
 * `/administracion/importaciones` (`docs/modulos/REQ-CORE/funcional.md
 * §14.6.1`, `RN-CORE-71`, `§14.6.4.6`, 1.9c). Listado de lotes de importación
 * de usuarios con el componente de tablas (modo `page`, sin filtros ni orden:
 * el *endpoint* no los acepta) y formulario de subida encima.
 *
 * - **Subida** (`RN-CORE-71`): un campo de fichero (`accept=".csv"`; la
 *   especificación pide `".csv,text/csv"`, pero `CA-CORE-192` prohíbe el literal
 *   `text/csv` en el cliente sin excepciones: se queda la extensión),
 *   la casilla «enviar invitaciones» (marcada por defecto, como el servidor) y
 *   la **cabecera exacta esperada** en un bloque copiable. Las comprobaciones
 *   de extensión y tamaño (≤ 10 MB) en cliente son **comodidad**: el servidor
 *   decide (`413`, `415`, `422`, `RN-CORE-18`) y su mensaje se muestra tal cual.
 *   Tras `202`, se navega al detalle del lote.
 * - **Tipos de documento admitidos** (`§14.6.4.6`): la lista de códigos de
 *   `DOCUMENT_TYPES` con su nombre traducido, junto a la cabecera.
 * - Sin enlace al manual: no hay un manual publicado en la SPA; la pantalla
 *   remite a su apartado por nombre.
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
} from '@/data-table'
import { createUserImport, listUserImports } from '../api'
import FormFieldError from '../components/FormFieldError.vue'
import {
  problemDetail,
  problemFieldErrors,
  problemRetryAfter,
  problemStatus,
} from '../composables/problem'
import { rememberImportInvitations } from '../composables/importInvitations'
import { takeFlash } from '../composables/useFlash'
import { DOCUMENT_TYPES } from '../documentTypes'
import { USER_IMPORT_HEADER, USER_IMPORT_MAX_BYTES } from '../userImportHeader'
import type { UserImport } from '../types'

const t = useT()
const router = useRouter()
const { formatDateTime, formatNumber } = useDataTableFormatters()

const file = ref<File | null>(null)
const sendInvitations = ref(true)
const submitting = ref(false)
const fileErrors = ref<string[]>([])
const generalError = ref<string | null>(null)
const copied = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

// Mensaje de resultado dejado por otra pantalla (p. ej. un lote descartado): una sola vez.
const pending = takeFlash()
const flashMessage = ref<string | null>(pending ? t(pending.key, pending.params ?? {}) : null)

function translated(key: string, fallback: string): string {
  const label = t(key)

  return label === key ? fallback : label
}

function statusLabel(status: string): string {
  return translated(`core.userImport.status.${status}`, status)
}

const columns: DataTableColumn<UserImport>[] = [
  {
    id: 'original_filename',
    headerKey: 'core.userImports.columns.file',
    value: (row) => row.original_filename,
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'created_at',
    headerKey: 'core.userImports.columns.createdAt',
    value: (row) => formatDateTime(row.created_at),
    card: 'subtitle',
  },
  {
    id: 'status',
    headerKey: 'core.userImports.columns.status',
    value: (row) => statusLabel(row.status),
    card: 'field',
  },
  {
    id: 'row_count',
    headerKey: 'core.userImports.columns.rows',
    value: (row) => (row.row_count === null ? null : formatNumber(row.row_count)),
    align: 'end',
    card: 'field',
  },
  {
    id: 'error_count',
    headerKey: 'core.userImports.columns.errors',
    value: (row) => (row.error_count === null ? null : formatNumber(row.error_count)),
    align: 'end',
    card: 'field',
  },
  {
    id: 'created_count',
    headerKey: 'core.userImports.columns.created',
    value: (row) => (row.created_count === null ? null : formatNumber(row.created_count)),
    align: 'end',
    card: 'field',
  },
]

const fetchImports: DataTableFetcher<UserImport> = (query) =>
  listUserImports({ page: query.page, per_page: query.per_page })

const documentTypes = computed(() =>
  DOCUMENT_TYPES.map((code) => ({
    code,
    name: translated(`core.person.documentType.${code}`, code),
  })),
)

function onFileChange(event: Event): void {
  const input = event.target as HTMLInputElement

  file.value = input.files?.[0] ?? null
  fileErrors.value = []
  generalError.value = null
}

/** Comodidad (`RN-CORE-71`): el servidor vuelve a decidir. */
function clientCheck(candidate: File): string | null {
  if (!/\.csv$/i.test(candidate.name)) {
    return t('core.userImports.upload.errors.extension')
  }

  if (candidate.size > USER_IMPORT_MAX_BYTES) {
    return t('core.userImports.upload.errors.tooLarge')
  }

  return null
}

async function submit(): Promise<void> {
  fileErrors.value = []
  generalError.value = null
  flashMessage.value = null

  if (file.value === null) {
    fileErrors.value = [t('core.userImports.upload.errors.noFile')]
    fileInput.value?.focus()

    return
  }

  const local = clientCheck(file.value)

  if (local !== null) {
    fileErrors.value = [local]
    fileInput.value?.focus()

    return
  }

  submitting.value = true

  try {
    const created = await createUserImport(file.value, sendInvitations.value)

    rememberImportInvitations(created.public_id, sendInvitations.value)
    await router.push({ name: 'core-user-import-detail', params: { publicId: created.public_id } })
  } catch (err) {
    showError(err)
  } finally {
    submitting.value = false
  }
}

function showError(err: unknown): void {
  const status = problemStatus(err)

  if (status === 429) {
    const seconds = problemRetryAfter(err)

    generalError.value =
      seconds !== null
        ? t('core.users.errors.tooManyRequestsWithSeconds', { seconds })
        : t('core.users.errors.tooManyRequests')

    return
  }

  // 422: el mensaje del campo `file` bajo el campo; 413/415 y el resto: su `detail`, tal cual.
  const field = problemFieldErrors(err).file

  if (status === 422 && field) {
    fileErrors.value = field
    fileInput.value?.focus()

    return
  }

  generalError.value = problemDetail(err) ?? t('core.users.errors.unexpected')
}

async function copyHeader(): Promise<void> {
  copied.value = false

  try {
    await navigator.clipboard.writeText(USER_IMPORT_HEADER)
    copied.value = true
  } catch {
    // Sin permiso de portapapeles: el bloque es seleccionable a mano.
    copied.value = false
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6">
    <div>
      <h1 class="text-lg font-semibold">{{ t('core.userImports.title') }}</h1>
      <p class="text-muted-foreground text-sm">{{ t('core.userImports.intro') }}</p>
    </div>

    <p
      v-if="flashMessage"
      role="status"
      class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
    >
      {{ flashMessage }}
    </p>

    <section class="flex flex-col gap-3" aria-labelledby="user-import-upload-title">
      <h2 id="user-import-upload-title" class="text-sm font-semibold">
        {{ t('core.userImports.upload.title') }}
      </h2>

      <form class="flex flex-col gap-3" novalidate @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="user-import-file">{{ t('core.userImports.upload.file') }}</Label>
          <input
            id="user-import-file"
            ref="fileInput"
            type="file"
            accept=".csv"
            class="border-input bg-background file:text-foreground block w-full max-w-xl rounded-lg border px-2.5 py-1.5 text-sm shadow-xs file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium [@media(any-pointer:coarse)]:min-h-11"
            :aria-invalid="fileErrors.length > 0 ? true : undefined"
            :aria-describedby="
              fileErrors.length > 0
                ? 'user-import-file-hint user-import-file-error'
                : 'user-import-file-hint'
            "
            @change="onFileChange"
          />
          <p id="user-import-file-hint" class="text-muted-foreground text-xs">
            {{ t('core.userImports.upload.fileHint') }}
          </p>
          <FormFieldError id="user-import-file-error" :messages="fileErrors" />
        </div>

        <label
          class="flex min-h-8 items-center gap-2 text-sm [@media(any-pointer:coarse)]:min-h-11"
        >
          <input
            v-model="sendInvitations"
            type="checkbox"
            class="accent-primary-on-background size-4"
          />
          {{ t('core.userImports.upload.sendInvitations') }}
        </label>

        <p v-if="generalError" role="alert" class="text-destructive text-sm">{{ generalError }}</p>

        <div>
          <Button type="submit" :disabled="submitting">
            {{
              submitting
                ? t('core.userImports.upload.submitting')
                : t('core.userImports.upload.submit')
            }}
          </Button>
        </div>
      </form>
    </section>

    <section class="flex flex-col gap-2" aria-labelledby="user-import-header-title">
      <h2 id="user-import-header-title" class="text-sm font-semibold">
        {{ t('core.userImports.header.title') }}
      </h2>
      <p class="text-muted-foreground text-xs">{{ t('core.userImports.header.hint') }}</p>
      <pre
        id="user-import-header"
        tabindex="0"
        class="bg-muted overflow-x-auto rounded-lg px-3 py-2 text-xs"
        :aria-label="t('core.userImports.header.title')"
      ><code>{{ USER_IMPORT_HEADER }}</code></pre>
      <div class="flex flex-wrap items-center gap-3">
        <Button type="button" variant="outline" size="sm" @click="copyHeader">
          {{ t('core.userImports.header.copy') }}
        </Button>
        <p v-if="copied" role="status" class="text-sm">{{ t('core.userImports.header.copied') }}</p>
      </div>
      <p class="text-muted-foreground text-xs">{{ t('core.userImports.header.manual') }}</p>
    </section>

    <section class="flex flex-col gap-2" aria-labelledby="user-import-doc-types-title">
      <h2 id="user-import-doc-types-title" class="text-sm font-semibold">
        {{ t('core.userImports.documentTypes.title') }}
      </h2>
      <p class="text-muted-foreground text-xs">{{ t('core.userImports.documentTypes.hint') }}</p>
      <ul class="flex flex-col gap-1 text-sm" data-testid="user-import-document-types">
        <li v-for="type in documentTypes" :key="type.code">
          <code class="bg-muted rounded px-1.5 py-0.5 text-xs">{{ type.code }}</code>
          {{ type.name }}
        </li>
      </ul>
    </section>

    <DataTable
      table-id="core.user_imports"
      :caption="t('core.userImports.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchImports"
      :empty-title="t('core.userImports.empty.title')"
      :empty-text="t('core.userImports.empty.text')"
      :card-heading-level="2"
    >
      <template #cell-original_filename="{ row }">
        <RouterLink
          :to="{ name: 'core-user-import-detail', params: { publicId: row.public_id } }"
          class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-3"
        >
          {{ row.original_filename }}
        </RouterLink>
      </template>
    </DataTable>
  </div>
</template>
