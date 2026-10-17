<script setup lang="ts">
/**
 * `/academico/escalas/nueva` y `/academico/escalas/:publicId/editar`
 * (`docs/modulos/REQ-ACAD/funcional.md §10.5.1`, `§10.5.2`, `§4.9.2`, `§4.9.3`).
 * Un solo formulario, **en página propia** (el editor de valores no cabe en un
 * diálogo a 320 px, WCAG 1.4.10). Alta: `POST /grading-scales` con
 * `academic_year` explícito, el del selector (`RN-ACAD-51`). Edición:
 * `PATCH /grading-scales/{id}` con solo lo modificado (`ADR-038 §9.2`); `values`
 * viaja como conjunto completo (`RN-ACAD-88`) y `scale_type` no se edita
 * (`RN-ACAD-70`).
 *
 * - **Validación de cliente = comodidad** (`INV-010`): decide el servidor. Un
 *   `422` se reparte (`distributeErrors`): campo de la escala, lista, fila
 *   `values.<n>.<campo>`, o junto a las acciones; el foco va al primer error.
 * - Decimales como **cadena** (`RN-ACAD-75`): nunca `float`.
 * - Curso de solo lectura o sin curso activo: estado propio sin formulario (la
 *   API daría `409`/`404`, `RN-ACAD-42`).
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { CalendarX } from '@lucide/vue'
import { useRoute, useRouter } from 'vue-router'
import { useAcademicYear, usePinAcademicYear } from '@/academic-year'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import TranslatableField from '@/components/TranslatableField.vue'
import EmptyState from '@/layouts/components/EmptyState.vue'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import type { Locale } from '@/modules/core/types'
import { useTenantLocales } from '@/tenant/useTenantLocales'
import ScaleValuesEditor from '../components/ScaleValuesEditor.vue'
import { createGradingScale, getGradingScale, updateGradingScale } from '../api'
import { setFlash } from '../composables/flash'
import { problemDetail, problemFieldErrors, problemStatus } from '../composables/problem'
import {
  buildCreateBody,
  buildUpdateBody,
  DECIMALS_OPTIONS,
  distributeErrors,
  emptyState,
  hasNumericFields,
  hasValues,
  SCALE_TYPES,
  stateFromScale,
  type ScaleErrors,
  type ScaleFormState,
} from '../composables/scaleForm'
import { useLabels } from '../composables/useLabels'
import { useLoad } from '../composables/useLoad'
import type { GradingScale } from '../types'

/** `StructureLimits::MAX_TEXT_LENGTH` (`RN-ACAD-02`). */
const MAX_TEXT_LENGTH = 255
const NUMERIC_FIELDS = ['min_value', 'max_value', 'passing_min_value'] as const
const NUMERIC_LABELS: Record<(typeof NUMERIC_FIELDS)[number], string> = {
  min_value: 'acad.fields.minValue',
  max_value: 'acad.fields.maxValue',
  passing_min_value: 'acad.fields.passingMinValue',
}

const t = useT()
const route = useRoute()
const router = useRouter()
const { scaleTypeLabel } = useLabels()
const { defaultLocale, locales } = useTenantLocales()
const { selectedId, noActive, readOnly, status } = useAcademicYear()

const editingId = computed(() => (route.params.publicId ? String(route.params.publicId) : null))
const isEdit = computed(() => editingId.value !== null)

const state = ref<ScaleFormState>(emptyState())
const errors = ref<ScaleErrors>({ fields: {}, list: [], rows: {}, general: [] })
const generalError = ref<string | null>(null)
const submitting = ref(false)
const form = ref<HTMLFormElement | null>(null)

const {
  data: original,
  loading,
  error: loadError,
  load,
} = useLoad<GradingScale>(async () => {
  const scale = await getGradingScale(String(editingId.value))

  state.value = stateFromScale(scale)

  return scale
})

usePinAcademicYear(computed(() => (isEdit.value ? (original.value?.academic_year ?? null) : null)))

onMounted(() => {
  if (isEdit.value) {
    void load()
  }
})

const type = computed(() => state.value.scale_type)
const numeric = computed(() => hasNumericFields(type.value))
const withValues = computed(() => hasValues(type.value))
const yearReady = computed(() => status.value === 'ready' || status.value === 'error')
const showNoActive = computed(() => !isEdit.value && yearReady.value && noActive.value)
const showReadOnly = computed(() => readOnly.value)

watch(type, () => {
  errors.value = { fields: {}, list: [], rows: {}, general: [] }
})

function fieldErrors(field: string): string[] {
  return errors.value.fields[field] ?? []
}

function describedBy(field: string, extra?: string): string | undefined {
  const ids = [
    extra,
    fieldErrors(field).length > 0 ? `acad-scale-${field}-errors` : undefined,
  ].filter((id): id is string => id !== undefined)

  return ids.length > 0 ? ids.join(' ') : undefined
}

const localeErrors = computed(() => {
  const result: Partial<Record<Locale, string[]>> = {}

  for (const [field, messages] of Object.entries(errors.value.fields)) {
    const match = /^name_translations\.(.+)$/.exec(field)

    if (match) {
      result[match[1] as Locale] = messages
    }
  }

  return result
})

const typeOptions = computed(() => {
  const current = type.value
  const known: string[] = [...SCALE_TYPES]

  return current !== '' && !known.includes(current) ? [...known, current] : known
})

async function focusFirstError(): Promise<void> {
  await nextTick()

  form.value
    ?.querySelector<HTMLElement>(
      '[aria-invalid="true"], #acad-scale-values-errors, #acad-scale-general-errors',
    )
    ?.focus()
}

async function fail(err: unknown): Promise<void> {
  if (problemStatus(err) === 422) {
    errors.value = distributeErrors(problemFieldErrors(err))

    const mapped =
      Object.keys(errors.value.fields).length +
      errors.value.list.length +
      Object.keys(errors.value.rows).length

    generalError.value =
      errors.value.general.length > 0
        ? errors.value.general.join(' ')
        : mapped === 0
          ? (problemDetail(err) ?? t('acad.errors.unexpected'))
          : null
  } else {
    generalError.value = problemDetail(err) ?? t('acad.errors.unexpected')
  }

  await focusFirstError()
}

async function submit(): Promise<void> {
  errors.value = { fields: {}, list: [], rows: {}, general: [] }
  generalError.value = null
  submitting.value = true

  try {
    if (isEdit.value && original.value) {
      const body = buildUpdateBody(state.value, original.value, locales.value)

      if (body !== null) {
        await updateGradingScale(original.value.public_id, body)
        setFlash('acad.flash.scaleUpdated')
      }

      await router.push({
        name: 'acad-grading-scale-detail',
        params: { publicId: original.value.public_id },
      })

      return
    }

    if (selectedId.value === null) {
      return
    }

    const created = await createGradingScale(
      buildCreateBody(state.value, selectedId.value, locales.value),
    )

    setFlash('acad.flash.scaleCreated')
    await router.push({
      name: 'acad-grading-scale-detail',
      params: { publicId: created.public_id },
    })
  } catch (err) {
    await fail(err)
  } finally {
    submitting.value = false
  }
}

const cancelTarget = computed(() =>
  isEdit.value && original.value
    ? { name: 'acad-grading-scale-detail', params: { publicId: original.value.public_id } }
    : {
        name: 'acad-grading-scales',
        query: selectedId.value ? { academic_year: selectedId.value } : {},
      },
)
</script>

<template>
  <div class="mx-auto flex max-w-3xl flex-col gap-4 px-4 py-6">
    <h1 class="text-lg font-semibold">
      {{ isEdit ? t('acad.form.title.edit.scale') : t('acad.form.title.create.scale') }}
    </h1>

    <LoadingState v-if="isEdit && loading" />
    <ErrorState v-else-if="isEdit && loadError" :state="loadError" @retry="load" />

    <LoadingState v-else-if="!yearReady" />

    <EmptyState
      v-else-if="showNoActive"
      :icon="CalendarX"
      :title="t('acad.structure.noActive.title')"
      :text="t('acad.structure.noActive.text')"
    />

    <section v-else-if="showReadOnly" class="flex flex-col gap-2" data-slot="acad-scale-read-only">
      <p role="note" class="border-border bg-muted rounded-lg border px-3 py-2 text-sm">
        {{ t('acad.readOnlyConfig') }}
      </p>
      <div>
        <Button variant="outline" as-child>
          <RouterLink :to="cancelTarget">{{ t('acad.form.cancel') }}</RouterLink>
        </Button>
      </div>
    </section>

    <form
      v-else
      ref="form"
      class="flex flex-col gap-5"
      novalidate
      data-slot="acad-scale-form"
      @submit.prevent="submit"
    >
      <p class="text-muted-foreground text-xs">{{ t('acad.form.description') }}</p>

      <div
        v-if="generalError"
        id="acad-scale-general-errors"
        role="alert"
        tabindex="-1"
        class="border-destructive text-destructive rounded-lg border px-3 py-2 text-sm"
      >
        {{ generalError }}
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="acad-scale-code">{{ t('acad.fields.code') }}</Label>
        <Input
          id="acad-scale-code"
          v-model="state.code"
          type="text"
          autocomplete="off"
          required
          :maxlength="MAX_TEXT_LENGTH"
          :aria-invalid="fieldErrors('code').length > 0 ? 'true' : undefined"
          :aria-describedby="describedBy('code')"
        />
        <ul
          v-if="fieldErrors('code').length > 0"
          id="acad-scale-code-errors"
          role="alert"
          class="text-destructive text-xs"
        >
          <li v-for="message in fieldErrors('code')" :key="message">{{ message }}</li>
        </ul>
      </div>

      <TranslatableField
        v-model="state.names"
        :label="t('acad.fields.name')"
        :locales="locales"
        :default-locale="defaultLocale"
        id-base="acad-scale-name"
        :locale-errors="localeErrors"
        :field-errors="fieldErrors('name_translations')"
        :max-length="MAX_TEXT_LENGTH"
      />

      <fieldset class="flex flex-col gap-2">
        <legend id="acad-scale-type-legend" class="text-sm font-medium">
          {{ t('acad.fields.scaleType') }}
        </legend>
        <RadioGroup
          v-model="state.scale_type"
          aria-labelledby="acad-scale-type-legend"
          :aria-describedby="isEdit ? 'acad-scale-type-locked' : undefined"
          :disabled="isEdit"
          required
        >
          <div v-for="option in typeOptions" :key="option" class="flex items-center gap-2">
            <RadioGroupItem :id="`acad-scale-type-${option}`" :value="option" />
            <Label :for="`acad-scale-type-${option}`" class="font-normal">
              {{ scaleTypeLabel(option) }}
            </Label>
          </div>
        </RadioGroup>
        <p v-if="isEdit" id="acad-scale-type-locked" class="text-muted-foreground text-xs">
          {{ t('acad.scales.typeLocked') }}
        </p>
      </fieldset>

      <div v-if="numeric" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div v-for="field in NUMERIC_FIELDS" :key="field" class="flex min-w-0 flex-col gap-1.5">
          <Label :for="`acad-scale-${field}`">{{ t(NUMERIC_LABELS[field]) }}</Label>
          <Input
            :id="`acad-scale-${field}`"
            v-model="state[field]"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            required
            :aria-invalid="fieldErrors(field).length > 0 ? 'true' : undefined"
            :aria-describedby="describedBy(field)"
          />
          <ul
            v-if="fieldErrors(field).length > 0"
            :id="`acad-scale-${field}-errors`"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors(field)" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex min-w-0 flex-col gap-1.5">
          <Label for="acad-scale-decimals">{{ t('acad.fields.decimals') }}</Label>
          <Select v-model="state.decimals" name="decimals" required>
            <SelectTrigger
              id="acad-scale-decimals"
              :aria-invalid="fieldErrors('decimals').length > 0 ? 'true' : undefined"
              :aria-describedby="describedBy('decimals')"
            >
              <SelectValue :placeholder="t('acad.form.choose')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="option in DECIMALS_OPTIONS" :key="option" :value="option">
                {{ option }}
              </SelectItem>
            </SelectContent>
          </Select>
          <ul
            v-if="fieldErrors('decimals').length > 0"
            id="acad-scale-decimals-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors('decimals')" :key="message">{{ message }}</li>
          </ul>
        </div>
      </div>

      <ScaleValuesEditor
        v-if="withValues"
        v-model="state.values"
        :type="type === 'mixta' ? 'mixta' : 'cualitativa'"
        :passing-threshold="state.passing_min_value"
        :locales="locales"
        :default-locale="defaultLocale"
        :list-errors="errors.list"
        :row-errors="errors.rows"
      />

      <div class="flex flex-wrap gap-2">
        <Button type="submit" :disabled="submitting || type === ''">
          {{ isEdit ? t('acad.form.save') : t('acad.form.create') }}
        </Button>
        <Button variant="outline" as-child>
          <RouterLink :to="cancelTarget">{{ t('acad.form.cancel') }}</RouterLink>
        </Button>
      </div>
    </form>
  </div>
</template>
