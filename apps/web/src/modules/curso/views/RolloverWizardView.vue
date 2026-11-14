<script setup lang="ts">
/**
 * `/administracion/cursos/:publicId/copiar-estructura` (`docs/modulos/REQ-CURSO/funcional.md §10.6`, §4.7, 1.12b,
 * `REQ-CURSO-002`). Asistente de **cuatro pasos** para copiar la estructura de otro curso al curso `:publicId`, que
 * debe estar en `planificacion` (el asistente nunca crea cursos: `RN-CURSO-63`). Cada paso es una región con
 * encabezado y **el foco va al encabezado** al avanzar o retroceder (WCAG 2.4.3).
 *
 * 1. **Origen**: los cursos de `GET /academic-years/selectable` que pueden serlo (`activo`, y `cerrado` si el usuario
 *    tiene `curso_historico.leer`, que el propio selector ya aplica), anteriores al destino; por defecto el activo.
 * 2. **Elementos**: el catálogo de `GET /academic-year-rollovers/elements` agrupado por módulo. Marcar uno marca lo que
 *    necesita; desmarcar uno desmarca lo que lo requiere, **con un aviso textual** (región `aria-live`, no solo
 *    visual). `allowed: false` ⇒ deshabilitado con su motivo. Los elementos de un paquete **no** salen como casillas
 *    sueltas: una única casilla «Copiar la asignación docente», desmarcada por defecto (`RN-CURSO-82`).
 * 3. **Simulación** (`GET …/preview`): por elemento, filas a crear, omisiones y ajustes con su motivo, incidencias con
 *    su `message`, desplazamiento de fechas y rango resultante. «Confirmar» deshabilitado si `executable` es `false`,
 *    con el motivo junto al botón.
 * 4. **Confirmar**: `ConfirmDialog` que advierte de que la copia se ejecuta en segundo plano, de que se puede revertir
 *    mientras el curso siga en planificación y de que al revertir **se pierden los cambios hechos sobre lo copiado**.
 *    Al aceptar, `POST` y paso al detalle de la copia.
 *
 * Validación de cliente = comodidad (`INV-010`): el servidor decide; los `409`/`403`/`422` se muestran con su
 * `detail` y las entradas `errors.*[]` ya traducidos (`ADR-038 §6.3`).
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/components/useConfirm'
import { DataTable, type DataTableColumn, type DataTableLocalFetcher } from '@/data-table'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import {
  getAcademicYear,
  listRolloverElements,
  listSelectableAcademicYears,
  previewRollover,
  requestRollover,
} from '../api'
import { formatCivilDate } from '../civilDate'
import {
  problemDetail,
  problemErrorEntries,
  problemErrorEntryWithCode,
  problemStatus,
} from '../composables/problem'
import {
  blockedReason,
  bundleElements,
  deselectWithDependents,
  selectBundleRequires,
  selectWithRequires,
  selectableElements,
} from '../rolloverSelection'
import type {
  AcademicYear,
  RolloverElement,
  RolloverPreview,
  RolloverPreviewElement,
} from '../types'

const t = useT()
const route = useRoute()
const router = useRouter()
const confirmation = useConfirm()

const targetId = computed(() => String(route.params.publicId))

const loading = ref(true)
const loadError = ref<ShellErrorState | null>(null)
const target = ref<AcademicYear | null>(null)
const sources = ref<AcademicYear[]>([])
const catalog = ref<RolloverElement[]>([])

const step = ref<1 | 2 | 3 | 4>(1)
const sourceId = ref('')
const selected = ref<string[]>([])
const copyTeaching = ref(false)
/** Aviso textual (región `aria-live`) de lo que se ha marcado o desmarcado solo. */
const notice = ref('')

const preview = ref<RolloverPreview | null>(null)
const previewLoading = ref(false)
const previewError = ref<ShellErrorState | null>(null)

const submitting = ref(false)
const submitError = ref<string | null>(null)
const submitEntries = ref<string[]>([])
const existing = ref<string | null>(null)

const heading = ref<HTMLElement | null>(null)

const STEP_KEYS = ['origin', 'elements', 'simulation', 'confirm'] as const

const notPlanning = computed(() => target.value !== null && target.value.status !== 'planificacion')
const selectable = computed(() => selectableElements(catalog.value))
const bundle = computed(() => bundleElements(catalog.value))
const bundleForbidden = computed(() => bundle.value.some((element) => !element.allowed))
const bundleBlocked = computed(
  () =>
    bundleForbidden.value ||
    bundle.value.some((element) => blockedReason(element.code, catalog.value) !== null),
)

/** Elementos agrupados por módulo, en el orden del catálogo. */
const groups = computed(() => {
  const byModule = new Map<string, RolloverElement[]>()

  for (const element of selectable.value) {
    byModule.set(element.module, [...(byModule.get(element.module) ?? []), element])
  }

  return [...byModule.entries()].map(([module, elements]) => ({ module, elements }))
})

const source = computed(
  () => sources.value.find((year) => year.public_id === sourceId.value) ?? null,
)

function moduleLabel(module: string): string {
  const key = `curso.rollover.wizard.modules.${module}`
  const label = t(key)

  return label === key ? module : label
}

function statusLabel(status: string): string {
  const key = `curso.status.${status}`
  const label = t(key)

  return label === key ? status : label
}

const labels = computed(
  () => new Map(catalog.value.map((element) => [element.code, element.label])),
)

function labelOf(code: string): string {
  return labels.value.get(code) ?? code
}

function reasonText(code: string): string | null {
  const reason = blockedReason(code, catalog.value)

  return reason === null ? null : t(`curso.rollover.wizard.blocked.${reason}`)
}

async function focusHeading(): Promise<void> {
  await nextTick()
  heading.value?.focus()
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null

  try {
    const [year, selectableYears, elements] = await Promise.all([
      getAcademicYear(targetId.value),
      listSelectableAcademicYears(),
      listRolloverElements(),
    ])

    target.value = year
    catalog.value = elements.data
    // Posibles orígenes (`RN-CURSO-63`): activo o cerrado, anteriores al destino. El activo, primero y por defecto.
    sources.value = selectableYears.data
      .filter(
        (candidate) =>
          candidate.public_id !== year.public_id &&
          (candidate.status === 'activo' || candidate.status === 'cerrado') &&
          candidate.starts_on < year.starts_on,
      )
      .sort((a, b) => Number(b.status === 'activo') - Number(a.status === 'activo'))
    sourceId.value = sources.value[0]?.public_id ?? ''
  } catch (err) {
    loadError.value = resolveErrorState(err)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function toggleElement(code: string, checked: boolean): void {
  if (checked) {
    const result = selectWithRequires(code, selected.value, catalog.value)

    selected.value = result.selected
    notice.value =
      result.added.length > 0
        ? t('curso.rollover.wizard.alsoSelected', {
            elements: result.added.map(labelOf).join(', '),
          })
        : ''

    return
  }

  const result = deselectWithDependents(code, selected.value, catalog.value, copyTeaching.value)
  const removed = result.removed.map(labelOf)

  selected.value = result.selected

  if (result.bundleDropped) {
    copyTeaching.value = false
    removed.push(t('curso.rollover.wizard.teachingAssignments.label'))
  }

  notice.value =
    removed.length > 0
      ? t('curso.rollover.wizard.alsoDeselected', { elements: removed.join(', ') })
      : ''
}

function toggleTeaching(checked: boolean): void {
  copyTeaching.value = checked

  if (!checked) {
    notice.value = ''

    return
  }

  const result = selectBundleRequires(selected.value, catalog.value)

  selected.value = result.selected
  notice.value =
    result.added.length > 0
      ? t('curso.rollover.wizard.teachingAssignments.requiresMarked', {
          elements: result.added.map(labelOf).join(', '),
        })
      : ''
}

function onCheck(code: string, event: Event): void {
  toggleElement(code, (event.target as HTMLInputElement).checked)
}

function onCheckTeaching(event: Event): void {
  toggleTeaching((event.target as HTMLInputElement).checked)
}

async function go(next: 1 | 2 | 3 | 4): Promise<void> {
  step.value = next
  await focusHeading()
}

async function loadPreview(): Promise<void> {
  if (!target.value || sourceId.value === '') {
    return
  }

  previewLoading.value = true
  previewError.value = null
  preview.value = null

  try {
    preview.value = await previewRollover({
      academic_year: target.value.public_id,
      source: sourceId.value,
      elements: selected.value,
      copy_teaching_assignments: copyTeaching.value,
    })
  } catch (err) {
    previewError.value = resolveErrorState(err)
  } finally {
    previewLoading.value = false
  }
}

async function toSimulation(): Promise<void> {
  await go(3)
  await loadPreview()
}

/** `RN-CORE-53`: toda tabla es el componente común; aquí en modo `local` (la simulación entera ya está en memoria). */
const previewColumns: DataTableColumn<RolloverPreviewElement>[] = [
  {
    id: 'label',
    headerKey: 'curso.rollover.wizard.columns.element',
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'to_copy',
    headerKey: 'curso.rollover.wizard.columns.toCopy',
    value: (row) => String(row.to_copy),
    card: 'field',
  },
  {
    id: 'notes',
    headerKey: 'curso.rollover.wizard.columns.notes',
    value: (row) =>
      [...row.skipped, ...row.adjusted].map((item) => `${item.label}: ${item.count}`).join(' · '),
    card: 'field',
  },
  {
    id: 'dates',
    headerKey: 'curso.rollover.wizard.columns.dates',
    value: (row) =>
      row.date_range
        ? `${formatCivilDate(row.date_range.starts_on)} – ${formatCivilDate(row.date_range.ends_on)}`
        : '',
    card: 'field',
  },
]

/** Cambia con cada simulación para que la tabla vuelva a pedir sus filas. */
const tableKey = computed(
  () => `${sourceId.value}|${selected.value.join(',')}|${copyTeaching.value}`,
)

const fetchPreviewRows: DataTableLocalFetcher<RolloverPreviewElement> = () =>
  Promise.resolve({ data: preview.value?.elements ?? [] })

/** Todas las incidencias que impiden ejecutar: las generales y las de cada elemento. */
const issues = computed(() => {
  const current = preview.value

  return current === null
    ? []
    : [...current.blocking, ...current.elements.flatMap((element) => element.blocking)]
})

async function confirm(): Promise<void> {
  const current = preview.value
  const to = target.value

  if (!current || !to || !current.executable) {
    return
  }

  submitError.value = null
  submitEntries.value = []
  existing.value = null
  await go(4)

  const confirmed = await confirmation.ask({
    title: t('curso.rollover.wizard.confirmDialog.title', {
      source: current.source.code,
      target: to.code,
    }),
    description: t('curso.rollover.wizard.confirmDialog.description', { target: to.code }),
    details: [
      t('curso.rollover.wizard.confirmDialog.background'),
      t('curso.rollover.wizard.confirmDialog.revertible', { target: to.code }),
      t('curso.rollover.wizard.confirmDialog.revertLoses'),
    ],
    confirmLabel: t('curso.rollover.wizard.confirmDialog.confirm', { target: to.code }),
  })

  if (!confirmed) {
    await go(3)

    return
  }

  submitting.value = true

  try {
    const created = await requestRollover({
      academic_year: to.public_id,
      source: current.source.public_id,
      elements: selected.value,
      copy_teaching_assignments: copyTeaching.value,
    })

    await router.push({
      name: 'curso-rollover-detail',
      params: { publicId: to.public_id, rolloverId: created.public_id },
    })
  } catch (err) {
    submitError.value = problemDetail(err) ?? t('curso.errors.unexpected')

    if (problemStatus(err) === 409) {
      const exists = problemErrorEntryWithCode(err, 'rollover', 'curso.conflict.rollover_exists')
      const existingId = exists?.params?.public_id

      existing.value = typeof existingId === 'string' ? existingId : null
    }

    submitEntries.value = [
      ...problemErrorEntries(err, 'rollover'),
      ...problemErrorEntries(err, 'elements'),
    ]
      .map((entry) => entry.message)
      .filter((message) => message !== submitError.value)
    await go(3)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-4xl flex-col gap-6 px-4 py-6">
    <LoadingState v-if="loading" />

    <ErrorState v-else-if="loadError" :state="loadError" @retry="load" />

    <section
      v-else-if="notPlanning"
      class="flex flex-col gap-2"
      aria-labelledby="rollover-not-planning"
    >
      <h1 id="rollover-not-planning" class="text-lg font-semibold">
        {{ t('curso.rollover.wizard.notPlanning.title') }}
      </h1>
      <p class="text-muted-foreground text-sm">{{ t('curso.rollover.wizard.notPlanning.text') }}</p>
      <div>
        <Button variant="outline" as-child>
          <RouterLink :to="{ name: 'curso-academic-year-detail', params: { publicId: targetId } }">
            {{ t('curso.rollover.wizard.backToYear') }}
          </RouterLink>
        </Button>
      </div>
    </section>

    <template v-else-if="target">
      <header class="flex flex-col gap-1">
        <h1 class="text-lg font-semibold">
          {{ t('curso.rollover.wizard.title', { target: target.code }) }}
        </h1>
        <p class="text-muted-foreground text-sm">{{ t('curso.rollover.wizard.intro') }}</p>
      </header>

      <ol
        class="flex flex-wrap gap-x-4 gap-y-1 text-sm"
        :aria-label="t('curso.rollover.wizard.steps')"
      >
        <li
          v-for="(key, index) in STEP_KEYS"
          :key="key"
          :aria-current="step === index + 1 ? 'step' : undefined"
          :class="step === index + 1 ? 'font-semibold' : 'text-muted-foreground'"
        >
          {{ t('curso.rollover.wizard.stepOf', { n: index + 1, total: STEP_KEYS.length }) }}:
          {{ t(`curso.rollover.wizard.step.${key}`) }}
        </li>
      </ol>

      <!-- Paso 1 · Origen -->
      <section v-if="step === 1" class="flex flex-col gap-3" aria-labelledby="rollover-step-1">
        <h2
          id="rollover-step-1"
          ref="heading"
          tabindex="-1"
          class="text-base font-semibold outline-none"
        >
          {{ t('curso.rollover.wizard.step.origin') }}
        </h2>
        <p v-if="sources.length === 0" class="text-muted-foreground text-sm">
          {{ t('curso.rollover.wizard.noSources') }}
        </p>
        <fieldset v-else class="flex flex-col gap-2">
          <legend class="text-sm">{{ t('curso.rollover.wizard.originLegend') }}</legend>
          <label
            v-for="year in sources"
            :key="year.public_id"
            class="flex min-h-11 items-center gap-2 text-sm"
          >
            <input
              v-model="sourceId"
              type="radio"
              name="rollover-source"
              :value="year.public_id"
              class="size-4"
            />
            <span>
              {{ year.code }} · {{ statusLabel(year.status) }} ·
              {{ formatCivilDate(year.starts_on) }} –
              {{ formatCivilDate(year.ends_on) }}
            </span>
          </label>
        </fieldset>
        <div class="flex gap-2">
          <Button type="button" :disabled="sourceId === ''" @click="go(2)">
            {{ t('curso.rollover.wizard.next') }}
          </Button>
          <Button variant="outline" as-child>
            <RouterLink
              :to="{ name: 'curso-academic-year-detail', params: { publicId: target.public_id } }"
            >
              {{ t('curso.form.cancel') }}
            </RouterLink>
          </Button>
        </div>
      </section>

      <!-- Paso 2 · Elementos -->
      <section v-else-if="step === 2" class="flex flex-col gap-4" aria-labelledby="rollover-step-2">
        <h2
          id="rollover-step-2"
          ref="heading"
          tabindex="-1"
          class="text-base font-semibold outline-none"
        >
          {{ t('curso.rollover.wizard.step.elements') }}
        </h2>
        <p class="text-muted-foreground text-sm">{{ t('curso.rollover.wizard.elementsHelp') }}</p>
        <p v-if="catalog.length === 0" class="text-muted-foreground text-sm">
          {{ t('curso.rollover.wizard.noElements') }}
        </p>

        <fieldset v-for="group in groups" :key="group.module" class="flex flex-col gap-1">
          <legend class="text-sm font-medium">{{ moduleLabel(group.module) }}</legend>
          <div v-for="element in group.elements" :key="element.code" class="flex flex-col">
            <label class="flex min-h-11 items-center gap-2 text-sm">
              <input
                type="checkbox"
                class="size-4"
                :checked="selected.includes(element.code)"
                :disabled="blockedReason(element.code, catalog) !== null"
                :aria-describedby="
                  blockedReason(element.code, catalog) !== null
                    ? `rollover-blocked-${element.code}`
                    : undefined
                "
                @change="onCheck(element.code, $event)"
              />
              <span>{{ element.label }}</span>
            </label>
            <p
              v-if="reasonText(element.code)"
              :id="`rollover-blocked-${element.code}`"
              class="text-muted-foreground pl-6 text-xs"
            >
              {{ reasonText(element.code) }}
            </p>
          </div>
        </fieldset>

        <!-- La casilla única de la asignación docente (RN-CURSO-82): desmarcada por defecto. -->
        <div v-if="bundle.length > 0" class="flex flex-col gap-1">
          <label class="flex min-h-11 items-center gap-2 text-sm font-medium">
            <input
              type="checkbox"
              class="size-4"
              data-testid="rollover-teaching-assignments"
              :checked="copyTeaching"
              :disabled="bundleBlocked"
              aria-describedby="rollover-teaching-help"
              @change="onCheckTeaching"
            />
            <span>{{ t('curso.rollover.wizard.teachingAssignments.label') }}</span>
          </label>
          <p id="rollover-teaching-help" class="text-muted-foreground pl-6 text-xs">
            {{ t('curso.rollover.wizard.teachingAssignments.help') }}
          </p>
          <p v-if="bundleBlocked" class="text-muted-foreground pl-6 text-xs">
            {{ t('curso.rollover.wizard.teachingAssignments.forbidden') }}
          </p>
        </div>

        <!-- Aviso textual de lo marcado o desmarcado solo (no solo visual). -->
        <p role="status" aria-live="polite" class="text-sm">{{ notice }}</p>

        <div class="flex gap-2">
          <Button type="button" variant="outline" @click="go(1)">{{
            t('curso.rollover.wizard.back')
          }}</Button>
          <Button type="button" :disabled="selected.length === 0" @click="toSimulation">
            {{ t('curso.rollover.wizard.next') }}
          </Button>
        </div>
      </section>

      <!-- Paso 3 · Simulación -->
      <section v-else-if="step === 3" class="flex flex-col gap-4" aria-labelledby="rollover-step-3">
        <h2
          id="rollover-step-3"
          ref="heading"
          tabindex="-1"
          class="text-base font-semibold outline-none"
        >
          {{ t('curso.rollover.wizard.step.simulation') }}
        </h2>

        <LoadingState v-if="previewLoading" />
        <ErrorState v-else-if="previewError" :state="previewError" @retry="loadPreview" />

        <template v-else-if="preview">
          <p class="text-sm">
            {{
              t('curso.rollover.wizard.summary', {
                source: preview.source.code,
                target: preview.academic_year.code,
              })
            }}
            {{ t('curso.rollover.wizard.offset', { days: preview.date_offset_days }) }}
          </p>

          <DataTable
            :key="tableKey"
            table-id="curso.rollover_preview"
            :caption="t('curso.rollover.wizard.simulationCaption')"
            :columns="previewColumns"
            mode="local"
            :fetcher="fetchPreviewRows"
            :empty-title="t('curso.rollover.wizard.simulationEmpty')"
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

          <div
            v-if="issues.length > 0"
            role="alert"
            class="text-destructive flex flex-col gap-1 text-sm"
          >
            <p class="font-medium">{{ t('curso.rollover.wizard.blockingTitle') }}</p>
            <ul class="list-disc pl-5">
              <li v-for="(issue, index) in issues" :key="`${issue.code}-${index}`">
                {{ issue.message }}
              </li>
            </ul>
          </div>

          <div v-if="submitError" role="alert" class="text-destructive flex flex-col gap-1 text-sm">
            <p>{{ submitError }}</p>
            <ul v-if="submitEntries.length > 0" class="list-disc pl-5">
              <li v-for="entry in submitEntries" :key="entry">{{ entry }}</li>
            </ul>
            <p v-if="existing">
              <RouterLink
                :to="{
                  name: 'curso-rollover-detail',
                  params: { publicId: target.public_id, rolloverId: existing },
                }"
                class="underline underline-offset-4"
              >
                {{ t('curso.rollover.wizard.openExisting') }}
              </RouterLink>
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <Button type="button" variant="outline" @click="go(2)">{{
              t('curso.rollover.wizard.back')
            }}</Button>
            <Button
              type="button"
              :disabled="!preview.executable || submitting"
              aria-describedby="rollover-confirm-hint"
              @click="confirm"
            >
              {{ t('curso.rollover.wizard.confirm') }}
            </Button>
            <p id="rollover-confirm-hint" class="text-muted-foreground text-sm">
              {{ preview.executable ? '' : t('curso.rollover.wizard.notExecutable') }}
            </p>
          </div>
        </template>
      </section>

      <!-- Paso 4 · Confirmar (el ConfirmDialog está abierto sobre este resumen) -->
      <section v-else class="flex flex-col gap-3" aria-labelledby="rollover-step-4">
        <h2
          id="rollover-step-4"
          ref="heading"
          tabindex="-1"
          class="text-base font-semibold outline-none"
        >
          {{ t('curso.rollover.wizard.step.confirm') }}
        </h2>
        <p v-if="source && preview" class="text-sm">
          {{ t('curso.rollover.wizard.summary', { source: source.code, target: target.code }) }}
          {{ t('curso.rollover.wizard.offset', { days: preview.date_offset_days }) }}
        </p>
        <p v-if="submitting" role="status" class="text-muted-foreground text-sm">
          {{ t('curso.rollover.wizard.submitting') }}
        </p>
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
