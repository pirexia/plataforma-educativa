<script setup lang="ts">
/**
 * `/administracion/cursos/nuevo` y `/administracion/cursos/:publicId/editar`
 * (`docs/modulos/REQ-CURSO/funcional.md §10.1`, §4.1, §4.2, 1.10). Un solo
 * formulario para el alta (`POST /academic-years`) y la edición
 * (`PATCH /academic-years/{id}`, solo en `planificacion`, `RN-CURSO-06`).
 *
 * - **Validación de cliente = comodidad** (`INV-010`): el servidor decide. Un
 *   `422` pinta cada `errors.<campo>[].message` (ya traducido, `ADR-038 §6.3`)
 *   bajo su campo con `aria-invalid="true"` y `aria-describedby`; el foco va al
 *   primer campo con error y un resumen con `role="alert"` los enumera.
 * - **`PATCH` solo con lo modificado** (`ADR-038 §9.2`).
 * - **`409 planning_exists`** (`RN-CURSO-04`): el mensaje del servidor con un
 *   enlace al curso que ya está en planificación.
 * - **Curso no editable** (`RN-CURSO-06`): si el curso no está en
 *   `planificacion`, estado propio sin formulario (la API daría `409`).
 * - Campos de fecha con etiqueta y formato anunciado (`type="date"`, WCAG 2.2).
 */
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import {
  createAcademicYear,
  getAcademicYear,
  updateAcademicYear,
  type AcademicYearPayload,
} from '../api'
import { setFlash } from '../composables/useFlash'
import {
  problemDetail,
  conflictYear,
  problemErrorEntryWithCode,
  problemFieldErrors,
  problemStatus,
} from '../composables/problem'
import type { AcademicYear } from '../types'

const t = useT()
const route = useRoute()
const router = useRouter()

const editingId = computed(() => (route.params.publicId ? String(route.params.publicId) : null))
const isEdit = computed(() => editingId.value !== null)

const FIELDS = ['code', 'starts_on', 'ends_on'] as const
type FieldName = (typeof FIELDS)[number]

const values = reactive<Record<FieldName, string>>({ code: '', starts_on: '', ends_on: '' })
let initial: Record<FieldName, string> = { code: '', starts_on: '', ends_on: '' }

const loading = ref(false)
const loadError = ref<ShellErrorState | null>(null)
const submitting = ref(false)
const notEditable = ref(false)

const fieldErrors = ref<Partial<Record<FieldName, string[]>>>({})
const generalError = ref<string | null>(null)
/** `409 planning_exists`: el curso que ya está en planificación. */
const planning = ref<{ public_id: string; code: string } | null>(null)

const errorSummary = computed(() => {
  const lines: string[] = []

  for (const messages of Object.values(fieldErrors.value)) {
    lines.push(...(messages ?? []))
  }

  if (generalError.value) {
    lines.push(generalError.value)
  }

  return lines
})

function describedBy(name: FieldName): string | undefined {
  const ids = [
    name === 'code' ? 'curso-form-code-hint' : null,
    (fieldErrors.value[name]?.length ?? 0) > 0 ? `curso-form-${name}-error` : null,
  ].filter(Boolean)

  return ids.length > 0 ? ids.join(' ') : undefined
}

function invalid(name: FieldName): true | undefined {
  return (fieldErrors.value[name]?.length ?? 0) > 0 ? true : undefined
}

async function focusFirstError(): Promise<void> {
  await nextTick()

  const first = FIELDS.find((name) => (fieldErrors.value[name]?.length ?? 0) > 0)

  if (first) {
    document.getElementById(`curso-form-${first}`)?.focus()
  }
}

async function load(): Promise<void> {
  if (!editingId.value) {
    return
  }

  loading.value = true
  loadError.value = null
  notEditable.value = false

  try {
    const year = await getAcademicYear(editingId.value)

    if (year.status !== 'planificacion') {
      notEditable.value = true

      return
    }

    initial = { code: year.code, starts_on: year.starts_on, ends_on: year.ends_on }
    Object.assign(values, initial)
  } catch (err) {
    loadError.value = resolveErrorState(err)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function updatePayload(): Partial<AcademicYearPayload> | null {
  const payload: Partial<AcademicYearPayload> = {}

  for (const name of FIELDS) {
    const value = values[name].trim()

    if (value !== initial[name]) {
      payload[name] = value
    }
  }

  return Object.keys(payload).length > 0 ? payload : null
}

async function submit(): Promise<void> {
  fieldErrors.value = {}
  generalError.value = null
  planning.value = null

  submitting.value = true

  try {
    if (isEdit.value && editingId.value) {
      const payload = updatePayload()

      if (payload !== null) {
        await updateAcademicYear(editingId.value, payload)
        setFlash({ key: 'curso.flash.updated' })
      }

      await router.push({
        name: 'curso-academic-year-detail',
        params: { publicId: editingId.value },
      })

      return
    }

    const created: AcademicYear = await createAcademicYear({
      code: values.code.trim(),
      starts_on: values.starts_on,
      ends_on: values.ends_on,
    })

    setFlash({ key: 'curso.flash.created', params: { code: created.code } })
    await router.push({
      name: 'curso-academic-year-detail',
      params: { publicId: created.public_id },
    })
  } catch (err) {
    await showError(err)
  } finally {
    submitting.value = false
  }
}

async function showError(err: unknown): Promise<void> {
  const status = problemStatus(err)

  if (status === 422) {
    const next: Partial<Record<FieldName, string[]>> = {}

    for (const [serverField, messages] of Object.entries(problemFieldErrors(err))) {
      if ((FIELDS as readonly string[]).includes(serverField)) {
        next[serverField as FieldName] = [...(next[serverField as FieldName] ?? []), ...messages]
      } else {
        generalError.value = messages.join(' ')
      }
    }

    fieldErrors.value = next

    if (errorSummary.value.length === 0) {
      generalError.value = t('curso.errors.unexpected')
    }

    await focusFirstError()

    return
  }

  if (status === 409) {
    planning.value = conflictYear(
      problemErrorEntryWithCode(err, 'academic_year', 'curso.conflict.planning_exists'),
    )

    generalError.value = problemDetail(err) ?? t('curso.errors.unexpected')

    return
  }

  generalError.value = problemDetail(err) ?? t('curso.errors.unexpected')
}
</script>

<template>
  <div class="mx-auto flex max-w-2xl flex-col gap-4 px-4 py-6">
    <h1 class="text-lg font-semibold">
      {{ isEdit ? t('curso.form.titleEdit') : t('curso.form.titleNew') }}
    </h1>

    <LoadingState v-if="loading" />
    <ErrorState v-else-if="loadError" :state="loadError" @retry="load" />

    <section
      v-else-if="notEditable"
      class="flex flex-col gap-2"
      aria-labelledby="curso-not-editable"
    >
      <h2 id="curso-not-editable" class="text-base font-semibold">
        {{ t('curso.form.notEditable.title') }}
      </h2>
      <p class="text-muted-foreground text-sm">{{ t('curso.form.notEditable.text') }}</p>
      <div>
        <Button variant="outline" as-child>
          <RouterLink
            :to="{ name: 'curso-academic-year-detail', params: { publicId: editingId ?? '' } }"
          >
            {{ t('curso.form.notEditable.back') }}
          </RouterLink>
        </Button>
      </div>
    </section>

    <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <p class="text-muted-foreground text-xs">{{ t('curso.form.requiredHint') }}</p>

      <div
        v-if="errorSummary.length > 0"
        role="alert"
        class="border-destructive text-destructive rounded-lg border px-3 py-2 text-sm"
      >
        <p class="font-medium">{{ t('curso.form.errorSummary') }}</p>
        <ul class="list-disc pl-5">
          <li v-for="line in errorSummary" :key="line">{{ line }}</li>
        </ul>
        <p v-if="planning" class="mt-2">
          <RouterLink
            :to="{ name: 'curso-academic-year-detail', params: { publicId: planning.public_id } }"
            class="underline underline-offset-4"
          >
            {{ t('curso.form.openPlanning', { code: planning.code }) }}
          </RouterLink>
        </p>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="curso-form-code">
          {{ t('curso.form.code') }}
          <span aria-hidden="true">*</span>
          <span class="sr-only">{{ t('curso.form.required') }}</span>
        </Label>
        <Input
          id="curso-form-code"
          v-model="values.code"
          type="text"
          autocomplete="off"
          required
          :aria-invalid="invalid('code')"
          :aria-describedby="describedBy('code')"
        />
        <p id="curso-form-code-hint" class="text-muted-foreground text-xs">
          {{ t('curso.form.codeHint') }}
        </p>
        <div
          v-if="(fieldErrors.code?.length ?? 0) > 0"
          id="curso-form-code-error"
          class="text-destructive text-sm"
        >
          <p v-for="message in fieldErrors.code" :key="message">{{ message }}</p>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="flex flex-col gap-1.5">
          <Label for="curso-form-starts_on">
            {{ t('curso.form.startsOn') }}
            <span aria-hidden="true">*</span>
            <span class="sr-only">{{ t('curso.form.required') }}</span>
          </Label>
          <Input
            id="curso-form-starts_on"
            v-model="values.starts_on"
            type="date"
            required
            :aria-invalid="invalid('starts_on')"
            :aria-describedby="describedBy('starts_on')"
          />
          <div
            v-if="(fieldErrors.starts_on?.length ?? 0) > 0"
            id="curso-form-starts_on-error"
            class="text-destructive text-sm"
          >
            <p v-for="message in fieldErrors.starts_on" :key="message">{{ message }}</p>
          </div>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="curso-form-ends_on">
            {{ t('curso.form.endsOn') }}
            <span aria-hidden="true">*</span>
            <span class="sr-only">{{ t('curso.form.required') }}</span>
          </Label>
          <Input
            id="curso-form-ends_on"
            v-model="values.ends_on"
            type="date"
            required
            :aria-invalid="invalid('ends_on')"
            :aria-describedby="describedBy('ends_on')"
          />
          <div
            v-if="(fieldErrors.ends_on?.length ?? 0) > 0"
            id="curso-form-ends_on-error"
            class="text-destructive text-sm"
          >
            <p v-for="message in fieldErrors.ends_on" :key="message">{{ message }}</p>
          </div>
        </div>
      </div>

      <div class="flex flex-wrap gap-2">
        <Button type="submit" :disabled="submitting">
          {{ submitting ? t('curso.form.saving') : t('curso.form.save') }}
        </Button>
        <Button variant="outline" as-child>
          <RouterLink
            :to="
              isEdit && editingId
                ? { name: 'curso-academic-year-detail', params: { publicId: editingId } }
                : { name: 'curso-academic-years' }
            "
          >
            {{ t('curso.form.cancel') }}
          </RouterLink>
        </Button>
      </div>
    </form>
  </div>
</template>
