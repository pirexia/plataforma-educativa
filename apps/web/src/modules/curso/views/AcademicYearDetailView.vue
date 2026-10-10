<script setup lang="ts">
/**
 * `/administracion/cursos/:publicId` (`docs/modulos/REQ-CURSO/funcional.md
 * §10.1`, §4.3, §4.4, `OPEN-CURSO-06`/`-08`, 1.10). Ficha de un curso con sus
 * acciones según estado y permiso (`RN-CORE-61`: permiso, nunca rol):
 *
 * - «Editar» (`curso_academico.actualizar`), solo en `planificacion`.
 * - «Activar» (`estado_curso_academico.actualizar`), solo en `planificacion`.
 * - «Cerrar» (`estado_curso_academico.actualizar`), solo en `activo`.
 * - En `cerrado`/`archivado`, ninguna acción y un aviso persistente de solo
 *   lectura (`RN-CURSO-20`). **La interfaz oculta; el servidor decide**
 *   (`INV-002`, `RN-CURSO-21`).
 *
 * Las transiciones piden confirmación con `ConfirmDialog` (`RN-CORE-64`). El
 * diálogo de cierre advierte de que **no se puede deshacer** desde la
 * aplicación (`OPEN-CURSO-08`) y de que bloquea la escritura de todos los datos
 * del curso (`OPEN-CURSO-20`); cancelar no envía ninguna petición. El de
 * activación advierte, sin impedirlo, de que la fecha de fin ya pasó
 * (`RN-CURSO-14`).
 *
 * Un `409` muestra su motivo (`detail` del servidor, ya traducido) y **nada
 * cambia en pantalla hasta recargar** (`CA-CURSO-083`); en `active_exists`, con
 * un enlace al curso activo; en `closure_checks_failed`, la lista de
 * validaciones que fallan (`errors.closure[]`).
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/components/useConfirm'
import { useDataTableFormatters } from '@/data-table'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import { getAcademicYear, transitionAcademicYear } from '../api'
import { formatCivilDate, hasEnded, isReadOnlyStatus } from '../civilDate'
import {
  problemDetail,
  conflictYear,
  problemErrorEntries,
  problemErrorEntryWithCode,
  problemStatus,
} from '../composables/problem'
import { takeFlash } from '../composables/useFlash'
import { usePermissions } from '../composables/usePermissions'
import type { AcademicYear } from '../types'

const t = useT()
const route = useRoute()
const { can } = usePermissions()
const { formatDateTime } = useDataTableFormatters()
const confirmation = useConfirm()

const publicId = computed(() => String(route.params.publicId))

const loading = ref(true)
const loadError = ref<ShellErrorState | null>(null)
const year = ref<AcademicYear | null>(null)

const busy = ref(false)
const message = ref<string | null>(null)
const actionError = ref<string | null>(null)
/** `409 active_exists`: el curso activo, para enlazarlo. */
const activeYear = ref<{ public_id: string; code: string } | null>(null)
/** `409 closure_checks_failed`: una entrada por validación que falla. */
const closureFailures = ref<string[]>([])

const canEdit = computed(
  () => year.value?.status === 'planificacion' && can('curso_academico.actualizar'),
)
const canActivate = computed(
  () => year.value?.status === 'planificacion' && can('estado_curso_academico.actualizar'),
)
const canClose = computed(
  () => year.value?.status === 'activo' && can('estado_curso_academico.actualizar'),
)
const hasActions = computed(() => canEdit.value || canActivate.value || canClose.value)
const readOnly = computed(() => year.value !== null && isReadOnlyStatus(year.value.status))

/** `ADR-038 §7.3`: un valor no anticipado muestra su código, no rompe. */
function statusLabel(status: string): string {
  const key = `curso.status.${status}`
  const label = t(key)

  return label === key ? status : label
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  actionError.value = null
  activeYear.value = null
  closureFailures.value = []

  try {
    year.value = await getAcademicYear(publicId.value)
  } catch (err) {
    year.value = null
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

function fail(err: unknown): void {
  closureFailures.value = []
  activeYear.value = null

  if (problemStatus(err) === 409) {
    activeYear.value = conflictYear(
      problemErrorEntryWithCode(err, 'academic_year', 'curso.conflict.active_exists'),
    )

    closureFailures.value = problemErrorEntries(err, 'closure').map((item) => item.message)
  }

  actionError.value = problemDetail(err) ?? t('curso.errors.unexpected')
}

async function run(action: () => Promise<void>): Promise<void> {
  busy.value = true
  message.value = null
  actionError.value = null
  activeYear.value = null
  closureFailures.value = []

  try {
    await action()
  } catch (err) {
    fail(err)
  } finally {
    busy.value = false
  }
}

async function activate(): Promise<void> {
  const current = year.value

  if (!current) {
    return
  }

  const confirmed = await confirmation.ask({
    title: t('curso.confirm.activate.title', { code: current.code }),
    description: hasEnded(current.ends_on)
      ? t('curso.confirm.activate.descriptionEnded')
      : t('curso.confirm.activate.description'),
    confirmLabel: t('curso.confirm.activate.confirm', { code: current.code }),
  })

  if (!confirmed) {
    return
  }

  await run(async () => {
    year.value = await transitionAcademicYear(current.public_id, 'activo')
    message.value = t('curso.flash.activated', { code: current.code })
  })
}

async function close(): Promise<void> {
  const current = year.value

  if (!current) {
    return
  }

  const confirmed = await confirmation.ask({
    title: t('curso.confirm.close.title', { code: current.code }),
    description: t('curso.confirm.close.description'),
    confirmLabel: t('curso.confirm.close.confirm', { code: current.code }),
    destructive: true,
  })

  if (!confirmed) {
    return
  }

  await run(async () => {
    year.value = await transitionAcademicYear(current.public_id, 'cerrado')
    message.value = t('curso.flash.closed', { code: current.code })
  })
}
</script>

<template>
  <div class="mx-auto flex max-w-4xl flex-col gap-6 px-4 py-6">
    <LoadingState v-if="loading" />

    <ErrorState v-else-if="loadError" :state="loadError" @retry="load" />

    <template v-else-if="year">
      <header class="flex flex-col gap-1">
        <h1 class="text-lg font-semibold">{{ year.code }}</h1>
        <p class="text-muted-foreground text-sm">
          {{ statusLabel(year.status) }} · {{ formatCivilDate(year.starts_on) }} –
          {{ formatCivilDate(year.ends_on) }}
        </p>
      </header>

      <!-- Aviso persistente de solo lectura (RN-CURSO-20): texto, no solo color. -->
      <p
        v-if="readOnly"
        role="note"
        class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
      >
        {{ t('curso.detail.readOnly', { status: statusLabel(year.status).toLowerCase() }) }}
      </p>

      <p
        v-if="message"
        role="status"
        class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
      >
        {{ message }}
      </p>

      <div v-if="actionError" role="alert" class="text-destructive flex flex-col gap-2 text-sm">
        <p>{{ actionError }}</p>
        <p v-if="activeYear">
          <RouterLink
            :to="{ name: 'curso-academic-year-detail', params: { publicId: activeYear.public_id } }"
            class="underline underline-offset-4"
          >
            {{ t('curso.detail.openActive', { code: activeYear.code }) }}
          </RouterLink>
        </p>
        <template v-if="closureFailures.length > 0">
          <p class="font-medium">{{ t('curso.detail.closureTitle') }}</p>
          <ul class="list-disc pl-5">
            <li v-for="failure in closureFailures" :key="failure">{{ failure }}</li>
          </ul>
        </template>
        <p>
          <Button type="button" variant="outline" size="sm" :disabled="busy" @click="load">
            {{ t('curso.detail.refresh') }}
          </Button>
        </p>
      </div>

      <section class="flex flex-col gap-2" aria-labelledby="curso-actions-title">
        <h2 id="curso-actions-title" class="text-sm font-semibold">
          {{ t('curso.detail.actions') }}
        </h2>

        <div v-if="hasActions" class="flex flex-wrap gap-2">
          <Button v-if="canEdit" variant="outline" as-child>
            <RouterLink
              :to="{ name: 'curso-academic-year-edit', params: { publicId: year.public_id } }"
            >
              {{ t('curso.detail.edit') }}
            </RouterLink>
          </Button>

          <Button v-if="canActivate" type="button" :disabled="busy" @click="activate">
            {{ t('curso.detail.activate') }}
          </Button>

          <Button v-if="canClose" type="button" variant="outline" :disabled="busy" @click="close">
            {{ t('curso.detail.close') }}
          </Button>
        </div>
        <p v-else class="text-muted-foreground text-sm">{{ t('curso.detail.noActions') }}</p>
      </section>

      <section aria-labelledby="curso-data-title">
        <h2 id="curso-data-title" class="mb-2 text-sm font-semibold">
          {{ t('curso.detail.title') }}
        </h2>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-muted-foreground">{{ t('curso.detail.fields.code') }}</dt>
            <dd>{{ year.code }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground">{{ t('curso.detail.fields.status') }}</dt>
            <dd>{{ statusLabel(year.status) }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground">{{ t('curso.detail.fields.startsOn') }}</dt>
            <dd>{{ formatCivilDate(year.starts_on) }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground">{{ t('curso.detail.fields.endsOn') }}</dt>
            <dd>{{ formatCivilDate(year.ends_on) }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground">{{ t('curso.detail.fields.createdAt') }}</dt>
            <dd>{{ formatDateTime(year.created_at) }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground">{{ t('curso.detail.fields.updatedAt') }}</dt>
            <dd>{{ formatDateTime(year.updated_at) }}</dd>
          </div>
        </dl>
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
