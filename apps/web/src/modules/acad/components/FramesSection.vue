<script setup lang="ts">
/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.3` punto 1, `§18.8`, `CA-ACAD-117`.
 * Sección «Marcos horarios» de la ficha de etapa (`acad-level-detail`), visible
 * solo con `horarios.leer`. La SPA pide siempre `GET /timetable-frames` con
 * `academic_year` igual al curso de la etapa **y** `education_level`. «Nuevo
 * marco horario» solo con `horarios.crear` **y** si el curso admite escritura
 * (`RN-ACAD-42`) y la etapa no tiene ya su marco ordinario (como mucho uno,
 * `RN-ACAD-110`; el servidor decide igualmente, `INV-002`). Las franjas se editan
 * en la ficha del marco: el alta pide el nombre y lleva a esa ficha.
 */
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAcademicYear } from '@/academic-year'
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
import TranslatableField from '@/components/TranslatableField.vue'
import TranslatedText from '@/components/TranslatedText.vue'
import { DataTable, type DataTableColumn, type DataTableFetcher } from '@/data-table'
import { cleanTranslations, type TranslationValues } from '@/lib/translatable'
import type { Locale } from '@/modules/core/types'
import { useTenantLocales } from '@/tenant/useTenantLocales'
import { createTimetableFrame, listTimetableFrames } from '../api'
import { problemDetail, problemFieldErrors } from '../composables/problem'
import { usePermissions } from '../composables/usePermissions'
import type { EducationLevel, TimetableFrame } from '../types'

const props = defineProps<{ level: EducationLevel }>()

/** Una etapa tiene pocos marcos (uno ordinario; los especiales llegan con `1.12-b`). */
const FRAMES_PER_PAGE = 100
const MAX_TEXT_LENGTH = 255

const t = useT()
const router = useRouter()
const { can } = usePermissions()
const { readOnly, writable } = useAcademicYear()
const { defaultLocale, locales } = useTenantLocales()

const canRead = computed(() => can('horarios.leer'))
const loaded = ref<TimetableFrame[]>([])
const hasOrdinary = computed(() => loaded.value.some((frame) => frame.frame_type === 'ordinaria'))
const canCreate = computed(() => can('horarios.crear') && writable.value && !hasOrdinary.value)

const columns: DataTableColumn<TimetableFrame>[] = [
  { id: 'name', headerKey: 'acad.fields.name', rowHeader: true, hideable: false, card: 'title' },
  {
    id: 'frame_type',
    headerKey: 'acad.fields.frameType',
    value: (row) => frameTypeLabel(row.frame_type),
    card: 'subtitle',
  },
  {
    id: 'slots',
    headerKey: 'acad.timetable.frame.slotsCount',
    value: (row) => String(row.slots.length),
    align: 'end',
    card: 'field',
  },
]

function frameTypeLabel(code: string): string {
  const key = `acad.frameType.${code}`
  const text = t(key)

  return text === key ? code : text
}

const fetchFrames: DataTableFetcher<TimetableFrame> = async () => {
  const result = await listTimetableFrames({
    academic_year: props.level.academic_year,
    education_level: [props.level.public_id],
    per_page: FRAMES_PER_PAGE,
  })

  loaded.value = result.data

  return result
}

const dialogOpen = ref(false)
const translations = ref<TranslationValues>({})
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

watch(dialogOpen, (open) => {
  if (open) {
    translations.value = {}
    fieldErrors.value = {}
    formError.value = null
  }
})

const localeErrors = computed(() => {
  const result: Partial<Record<Locale, string[]>> = {}

  for (const [field, messages] of Object.entries(fieldErrors.value)) {
    const match = /^name_translations\.(.+)$/.exec(field)

    if (match) {
      result[match[1] as Locale] = messages
    }
  }

  return result
})

const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => field !== 'name_translations' && !field.startsWith('name_translations.'))
    .flatMap(([, messages]) => messages),
)

async function submit(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null

  // El servidor decide igualmente (`translations.default_locale_required`).
  if ((translations.value[defaultLocale.value] ?? '').trim() === '') {
    fieldErrors.value = { name_translations: [t('acad.form.errors.defaultLocaleRequired')] }

    return
  }

  busy.value = true

  try {
    const frame = await createTimetableFrame({
      education_level: props.level.public_id,
      frame_type: 'ordinaria',
      name_translations: cleanTranslations(translations.value, locales.value),
    })

    dialogOpen.value = false
    await router.push({ name: 'acad-timetable-frame', params: { publicId: frame.public_id } })
  } catch (err) {
    fieldErrors.value = problemFieldErrors(err)
    formError.value =
      problemDetail(err) ??
      (Object.keys(fieldErrors.value).length === 0 ? t('acad.errors.unexpected') : null)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section
    v-if="canRead"
    class="flex flex-col gap-3"
    aria-labelledby="acad-level-frames"
    data-slot="acad-frames"
  >
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 id="acad-level-frames" class="text-sm font-semibold">
        {{ t('acad.timetable.frames.title') }}
      </h2>
      <Button v-if="canCreate" type="button" size="sm" @click="dialogOpen = true">
        {{ t('acad.timetable.frames.new') }}
      </Button>
    </div>

    <p
      v-if="readOnly"
      role="note"
      data-slot="acad-frames-read-only"
      class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
    >
      {{ t('acad.readOnlyTimetable') }}
    </p>

    <DataTable
      table-id="acad.level_frames"
      :caption="t('acad.timetable.frames.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchFrames"
      :empty-title="t('acad.timetable.frames.empty')"
      :card-heading-level="3"
      hide-single-page-footer
    >
      <template #cell-name="{ row }">
        <RouterLink
          :to="{ name: 'acad-timetable-frame', params: { publicId: row.public_id } }"
          class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-3"
        >
          <TranslatedText :text="row.name" :translations="row.name_translations" />
        </RouterLink>
      </template>
    </DataTable>

    <Dialog v-model:open="dialogOpen">
      <DialogContent :close-label="t('acad.form.close')" data-slot="acad-frame-form">
        <DialogHeader>
          <DialogTitle>{{ t('acad.timetable.frames.form.title') }}</DialogTitle>
          <DialogDescription>{{ t('acad.timetable.frames.form.description') }}</DialogDescription>
        </DialogHeader>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
          <TranslatableField
            v-model="translations"
            :label="t('acad.fields.name')"
            :locales="locales"
            :default-locale="defaultLocale"
            id-base="acad-frame-name"
            :locale-errors="localeErrors"
            :field-errors="fieldErrors['name_translations'] ?? []"
            :max-length="MAX_TEXT_LENGTH"
          />

          <ul v-if="otherErrors.length > 0" role="alert" class="text-destructive text-sm">
            <li v-for="message in otherErrors" :key="message">{{ message }}</li>
          </ul>
          <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>

          <DialogFooter>
            <Button type="button" variant="outline" @click="dialogOpen = false">
              {{ t('acad.form.cancel') }}
            </Button>
            <Button type="submit" :disabled="busy">{{ t('acad.form.create') }}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  </section>
</template>
