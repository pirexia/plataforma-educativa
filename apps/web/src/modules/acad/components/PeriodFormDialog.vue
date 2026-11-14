<script setup lang="ts">
/**
 * `docs/modulos/REQ-ACAD/funcional.md §10.5.2`, `§4.8.2`, `§4.8.3`,
 * `CA-ACAD-099`. Alta y edición de un período lectivo en diálogo: tipo
 * (`Select`), nombre traducible (`ADR-062 §5.7`), inicio, fin y posición
 * opcional. La etapa es fija (la de la ficha) y solo viaja en el alta; el curso
 * académico no se envía nunca (`RN-ACAD-51`, `RN-ACAD-06`). En la edición solo
 * viajan las claves modificadas (`ADR-038 §9.2`).
 *
 * La validación de cliente es solo ayuda (`INV-010`): los `422` del servidor se
 * pintan junto a su campo, ya traducidos. `period_overlap` nombra el período con
 * el que solapa, resuelto desde `params.period` en la tabla ya cargada y con la
 * clave completa `acad.periods.overlapWith` (sin concatenar fragmentos); si no
 * está en la tabla, se muestra el `message` de la API.
 */
import { computed, ref, watch } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import TranslatableField from '@/components/TranslatableField.vue'
import type { Locale } from '@/modules/core/types'
import { cleanTranslations, translationsPatch, type TranslationValues } from '@/lib/translatable'
import { useTenantLocales } from '@/tenant/useTenantLocales'
import { entriesHaveCode, problemDetail, problemFieldEntries } from '../composables/problem'
import type { AcademicPeriod, ProblemErrorEntry } from '../types'

const PERIOD_TYPES = ['trimestre', 'cuatrimestre', 'semestre']
const OVERLAP_ERROR = 'acad.validation.period_overlap'
/** `maxLength` de cada valor del nombre (`StructureLimits::MAX_TEXT_LENGTH`, `RN-ACAD-02`). */
const MAX_TEXT_LENGTH = 255
const OWN_FIELDS = new Set(['period_type', 'name_translations', 'starts_on', 'ends_on', 'position'])

const props = defineProps<{
  open: boolean
  /** `null` = alta. */
  period: AcademicPeriod | null
  /** `public_id` de la etapa de la ficha (solo viaja en el alta). */
  educationLevel: string
  /** Períodos ya cargados en la tabla: para nombrar el solape (`§10.5.2`). */
  periods: readonly AcademicPeriod[]
  /** Fechas del curso académico de la etapa, ya formateadas (`null` si no se conocen). */
  yearStart: string | null
  yearEnd: string | null
  save: (body: Record<string, unknown>) => Promise<unknown>
}>()

const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()
const { defaultLocale, locales } = useTenantLocales()

const hasRange = computed(() => props.yearStart !== null && props.yearEnd !== null)
const mode = computed(() => (props.period === null ? 'create' : 'edit'))

const periodType = ref('')
const translations = ref<TranslationValues>({})
const startsOn = ref('')
const endsOn = ref('')
const position = ref<string | number>('')

const busy = ref(false)
const fieldEntries = ref<Record<string, ProblemErrorEntry[]>>({})
const clientErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

function reset(): void {
  const period = props.period

  periodType.value = period?.period_type ?? ''
  translations.value = period ? { ...period.name_translations } : {}
  startsOn.value = period?.starts_on ?? ''
  endsOn.value = period?.ends_on ?? ''
  position.value = period ? String(period.position) : ''
  fieldEntries.value = {}
  clientErrors.value = {}
  formError.value = null
}

watch(
  () => [props.open, props.period?.public_id],
  () => {
    if (props.open) {
      reset()
    }
  },
  { immediate: true },
)

function messageOf(entry: ProblemErrorEntry): string {
  if (entriesHaveCode([entry], OVERLAP_ERROR)) {
    const other = props.periods.find((item) => item.public_id === entry.params?.period)

    if (other) {
      return t('acad.periods.overlapWith', { name: other.name })
    }
  }

  return entry.message
}

/** Errores por campo, ya traducidos: los del servidor (enriquecidos) y los de cliente. */
const errors = computed<Record<string, string[]>>(() => {
  const result: Record<string, string[]> = {}

  for (const [field, entries] of Object.entries(fieldEntries.value)) {
    result[field] = entries.map(messageOf)
  }

  for (const [field, messages] of Object.entries(clientErrors.value)) {
    result[field] = [...(result[field] ?? []), ...messages]
  }

  return result
})

const typeOptions = computed(() => {
  const current = periodType.value
  const values =
    current !== '' && !PERIOD_TYPES.includes(current) ? [...PERIOD_TYPES, current] : PERIOD_TYPES

  return values.map((value) => {
    const key = `acad.periodType.${value}`
    const label = t(key)

    return { value, label: label === key ? value : label }
  })
})

const localeErrors = computed(() => {
  const result: Partial<Record<Locale, string[]>> = {}

  for (const [field, messages] of Object.entries(errors.value)) {
    const match = /^name_translations\.(.+)$/.exec(field)

    if (match) {
      result[match[1] as Locale] = messages
    }
  }

  return result
})

const translationsFieldErrors = computed(() => errors.value['name_translations'] ?? [])

/** Errores de campos que el formulario no pinta (p. ej. `parent_not_found`): junto a la acción. */
const otherErrors = computed(() =>
  Object.entries(errors.value)
    .filter(([field]) => !OWN_FIELDS.has(field) && !field.startsWith('name_translations.'))
    .flatMap(([, messages]) => messages),
)

function describedBy(field: string, extra?: string): string | undefined {
  const ids = [extra, errors.value[field] ? `acad-period-${field}-errors` : undefined].filter(
    (id): id is string => id !== undefined,
  )

  return ids.length > 0 ? ids.join(' ') : undefined
}

function parsePosition(): number | undefined {
  const text = String(position.value ?? '').trim()

  return text === '' ? undefined : Number(text)
}

/** Completo en el alta; solo las claves modificadas en la edición (`ADR-038 §9.2`). */
function buildBody(): Record<string, unknown> {
  const period = props.period
  const nextPosition = parsePosition()

  if (period === null) {
    const body: Record<string, unknown> = {
      education_level: props.educationLevel,
      period_type: periodType.value,
      name_translations: cleanTranslations(translations.value, locales.value),
      starts_on: startsOn.value,
      ends_on: endsOn.value,
    }

    if (nextPosition !== undefined) body.position = nextPosition

    return body
  }

  const body: Record<string, unknown> = {}
  const patch = translationsPatch(period.name_translations, translations.value, locales.value)

  if (periodType.value !== period.period_type) body.period_type = periodType.value
  if (Object.keys(patch).length > 0) body.name_translations = patch
  if (startsOn.value !== period.starts_on) body.starts_on = startsOn.value
  if (endsOn.value !== period.ends_on) body.ends_on = endsOn.value
  if (nextPosition !== undefined && nextPosition !== period.position) body.position = nextPosition

  return body
}

async function submit(): Promise<void> {
  fieldEntries.value = {}
  clientErrors.value = {}
  formError.value = null

  // Ayuda de cliente (`INV-010`): el servidor decide igualmente.
  if ((translations.value[defaultLocale.value] ?? '').trim() === '') {
    clientErrors.value = { name_translations: [t('acad.form.errors.defaultLocaleRequired')] }

    return
  }

  const body = buildBody()

  if (props.period !== null && Object.keys(body).length === 0) {
    emit('update:open', false)

    return
  }

  busy.value = true

  try {
    await props.save(body)
    emit('saved')
    emit('update:open', false)
  } catch (err) {
    fieldEntries.value = problemFieldEntries(err)
    formError.value =
      problemDetail(err) ??
      (Object.keys(fieldEntries.value).length === 0 ? t('acad.errors.unexpected') : null)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('acad.form.close')" data-slot="acad-entity-form">
      <DialogHeader>
        <DialogTitle>{{ t(`acad.form.title.${mode}.period`) }}</DialogTitle>
        <DialogDescription>{{ t('acad.form.description') }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="acad-period-type">{{ t('acad.fields.periodType') }}</Label>
          <Select v-model="periodType" name="period_type" required>
            <SelectTrigger
              id="acad-period-type"
              :aria-invalid="errors.period_type ? 'true' : undefined"
              :aria-describedby="describedBy('period_type')"
            >
              <SelectValue :placeholder="t('acad.periods.typePlaceholder')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="option in typeOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </SelectItem>
            </SelectContent>
          </Select>
          <ul
            v-if="errors.period_type"
            id="acad-period-period_type-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in errors.period_type" :key="message">{{ message }}</li>
          </ul>
        </div>

        <TranslatableField
          v-model="translations"
          :label="t('acad.fields.name')"
          :locales="locales"
          :default-locale="defaultLocale"
          id-base="acad-period-name"
          :locale-errors="localeErrors"
          :field-errors="translationsFieldErrors"
          :max-length="MAX_TEXT_LENGTH"
        />

        <p v-if="hasRange" id="acad-period-range" class="text-muted-foreground text-xs">
          {{ t('acad.periods.yearRange', { start: yearStart, end: yearEnd }) }}
        </p>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div class="flex flex-col gap-1.5">
            <Label for="acad-period-starts_on">{{ t('acad.fields.startsOn') }}</Label>
            <Input
              id="acad-period-starts_on"
              v-model="startsOn"
              type="date"
              required
              :aria-invalid="errors.starts_on ? 'true' : undefined"
              :aria-describedby="
                describedBy('starts_on', hasRange ? 'acad-period-range' : undefined)
              "
            />
            <ul
              v-if="errors.starts_on"
              id="acad-period-starts_on-errors"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in errors.starts_on" :key="message">{{ message }}</li>
            </ul>
          </div>

          <div class="flex flex-col gap-1.5">
            <Label for="acad-period-ends_on">{{ t('acad.fields.endsOn') }}</Label>
            <Input
              id="acad-period-ends_on"
              v-model="endsOn"
              type="date"
              required
              :aria-invalid="errors.ends_on ? 'true' : undefined"
              :aria-describedby="describedBy('ends_on', hasRange ? 'acad-period-range' : undefined)"
            />
            <ul
              v-if="errors.ends_on"
              id="acad-period-ends_on-errors"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in errors.ends_on" :key="message">{{ message }}</li>
            </ul>
          </div>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="acad-period-position">{{ t('acad.fields.position') }}</Label>
          <Input
            id="acad-period-position"
            :model-value="String(position)"
            type="number"
            min="1"
            step="1"
            inputmode="numeric"
            :aria-invalid="errors.position ? 'true' : undefined"
            :aria-describedby="describedBy('position', 'acad-period-position-hint')"
            @update:model-value="position = $event ?? ''"
          />
          <p id="acad-period-position-hint" class="text-muted-foreground text-xs">
            {{ t('acad.form.positionHint') }}
          </p>
          <ul
            v-if="errors.position"
            id="acad-period-position-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in errors.position" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div
          v-if="formError || otherErrors.length > 0"
          role="alert"
          class="text-destructive text-sm"
        >
          <p v-if="formError">{{ formError }}</p>
          <p v-for="message in otherErrors" :key="message">{{ message }}</p>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            :disabled="busy"
            @click="emit('update:open', false)"
          >
            {{ t('acad.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="busy">
            {{ mode === 'create' ? t('acad.form.create') : t('acad.form.save') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
