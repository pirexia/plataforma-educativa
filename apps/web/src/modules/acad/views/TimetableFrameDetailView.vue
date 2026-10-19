<script setup lang="ts">
/**
 * `/academico/marcos/:publicId` (`docs/modulos/REQ-ACAD/funcional.md §18.8`,
 * `§18.4.4`, `CA-ACAD-117` a `-120`). Ficha de un marco horario y editor de sus
 * franjas como lista (`TimeSlotsEditor`). El selector de curso académico queda
 * fijo en el de la etapa del marco. Guardar envía **una** petición `PATCH` con
 * el conjunto completo de franjas (`RN-ACAD-113`) y, si cambia, el nombre
 * (fusión por idioma). «Guardar» y «Borrar» solo con `horarios.actualizar` /
 * `eliminar` **y** si el curso admite escritura (`RN-ACAD-42`); sin ellos, las
 * franjas se muestran como texto. Un `409` (franja con sesiones, `RN-ACAD-114`;
 * curso cerrado) o un `422` se muestran con el mensaje del servidor junto a la
 * acción y la pantalla no cambia hasta recargar.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { useAcademicYear, usePinAcademicYear } from '@/academic-year'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import TranslatableField from '@/components/TranslatableField.vue'
import TranslatedText from '@/components/TranslatedText.vue'
import { DataTable, type DataTableColumn, type DataTableLocalFetcher } from '@/data-table'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { translationsPatch, type TranslationValues } from '@/lib/translatable'
import type { Locale } from '@/modules/core/types'
import { useTenantLocales } from '@/tenant/useTenantLocales'
import { deleteTimetableFrame, getTimetableFrame, updateTimetableFrame } from '../api'
import StructureNotices from '../components/StructureNotices.vue'
import TimeSlotsEditor, { type SlotRow } from '../components/TimeSlotsEditor.vue'
import { problemFieldErrors } from '../composables/problem'
import { useLoad } from '../composables/useLoad'
import { usePermissions } from '../composables/usePermissions'
import { useStructureActions } from '../composables/useStructureActions'
import type { TimeSlot, TimetableFrame } from '../types'

const MAX_TEXT_LENGTH = 255

const t = useT()
const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const actions = useStructureActions()
const { defaultLocale, locales } = useTenantLocales()

const publicId = computed(() => String(route.params.publicId))
const {
  data: frame,
  loading,
  error,
  load,
} = useLoad<TimetableFrame>(() => getTimetableFrame(publicId.value))

usePinAcademicYear(computed(() => frame.value?.academic_year ?? null))
const { readOnly, writable } = useAcademicYear()

const canUpdate = computed(() => can('horarios.actualizar') && writable.value)
const canDelete = computed(() => can('horarios.eliminar') && writable.value)

const rows = ref<SlotRow[]>([])
const translations = ref<TranslationValues>({})
const fieldErrors = ref<Record<string, string[]>>({})

function toRows(current: TimetableFrame): SlotRow[] {
  return current.slots.map((slot, index) => ({
    key: index + 1,
    public_id: slot.public_id,
    weekday: String(slot.weekday),
    starts_at: slot.starts_at,
    ends_at: slot.ends_at,
  }))
}

watch(
  frame,
  (current) => {
    rows.value = current ? toRows(current) : []
    translations.value = current ? { ...current.name_translations } : {}
    fieldErrors.value = {}
  },
  { immediate: true },
)

onMounted(load)

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

/** Errores de campos que la pantalla no pinta junto a su campo. */
const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(
      ([field]) =>
        field !== 'name_translations' &&
        !field.startsWith('name_translations.') &&
        field !== 'slots' &&
        !field.startsWith('slots.'),
    )
    .flatMap(([, messages]) => messages),
)

async function save(): Promise<void> {
  const current = frame.value

  if (!current) return

  actions.clear()
  fieldErrors.value = {}

  const body: Record<string, unknown> = {
    slots: rows.value.map((row) => ({
      ...(row.public_id ? { public_id: row.public_id } : {}),
      weekday: Number(row.weekday),
      starts_at: row.starts_at,
      ends_at: row.ends_at,
    })),
  }
  const patch = translationsPatch(current.name_translations, translations.value, locales.value)

  if (Object.keys(patch).length > 0) {
    body.name_translations = patch
  }

  actions.busy.value = true

  try {
    await updateTimetableFrame(current.public_id, body)
    await load()
    actions.message.value = t('acad.flash.frameUpdated')
  } catch (err) {
    fieldErrors.value = problemFieldErrors(err)
    actions.fail(err)
  } finally {
    actions.busy.value = false
  }
}

async function remove(): Promise<void> {
  const current = frame.value

  if (!current) return

  const done = await actions.confirmAndRun(
    {
      title: t('acad.confirm.delete.frame.title', { name: current.name }),
      description: t('acad.confirm.delete.frame.description'),
      confirmLabel: t('acad.confirm.delete.confirm', { code: current.name }),
      destructive: true,
    },
    () => deleteTimetableFrame(current.public_id),
    t('acad.flash.frameDeleted'),
  )

  if (done) {
    void router.push({
      name: 'acad-level-detail',
      params: { publicId: current.education_level },
      query: { academic_year: current.academic_year },
    })
  }
}

function weekdayLabel(day: number): string {
  return t(`acad.weekday.${day}`)
}

/** Franjas en solo lectura (sin `horarios.actualizar` o con el curso cerrado). */
const slotColumns: DataTableColumn<TimeSlot>[] = [
  {
    id: 'weekday',
    headerKey: 'acad.timetable.slots.weekday',
    value: (row) => weekdayLabel(row.weekday),
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  { id: 'starts_at', headerKey: 'acad.timetable.slots.startsAt', card: 'field' },
  { id: 'ends_at', headerKey: 'acad.timetable.slots.endsAt', card: 'field' },
]

const fetchSlots: DataTableLocalFetcher<TimeSlot> = async () => ({
  data: frame.value?.slots ?? [],
})
</script>

<template>
  <div class="mx-auto flex max-w-5xl flex-col gap-6 px-4 py-6">
    <LoadingState v-if="loading && !frame" />
    <ErrorState v-else-if="error" :state="error" @retry="load" />

    <template v-else-if="frame">
      <header class="flex flex-col gap-1">
        <p class="text-sm">
          <RouterLink
            :to="{
              name: 'acad-level-detail',
              params: { publicId: frame.education_level },
              query: { academic_year: frame.academic_year },
            }"
            class="text-primary-on-background underline underline-offset-4"
          >
            {{ t('acad.nav.backToLevelGeneric') }}
          </RouterLink>
        </p>
        <h1 class="text-lg font-semibold">
          <TranslatedText :text="frame.name" :translations="frame.name_translations" />
        </h1>
      </header>

      <StructureNotices
        :read-only="readOnly"
        :message="actions.message.value"
        :action-error="actions.actionError.value"
        :dependents="actions.dependents.value"
      />

      <form v-if="canUpdate" class="flex flex-col gap-6" @submit.prevent="save">
        <TranslatableField
          v-model="translations"
          :label="t('acad.fields.name')"
          :locales="locales"
          :default-locale="defaultLocale"
          id-base="acad-frame-detail-name"
          :locale-errors="localeErrors"
          :field-errors="fieldErrors['name_translations'] ?? []"
          :max-length="MAX_TEXT_LENGTH"
        />

        <section class="flex flex-col gap-3" aria-labelledby="acad-frame-slots">
          <h2 id="acad-frame-slots" class="text-sm font-semibold">
            {{ t('acad.timetable.slots.title') }}
          </h2>
          <TimeSlotsEditor v-model="rows" :errors="fieldErrors" />
        </section>

        <ul v-if="otherErrors.length > 0" role="alert" class="text-destructive text-sm">
          <li v-for="message in otherErrors" :key="message">{{ message }}</li>
        </ul>

        <div class="flex flex-wrap gap-2">
          <Button type="submit" :disabled="actions.busy.value">{{ t('acad.form.save') }}</Button>
          <Button
            v-if="canDelete"
            type="button"
            variant="outline"
            :disabled="actions.busy.value"
            @click="remove"
          >
            {{ t('acad.detail.delete') }}
          </Button>
        </div>
      </form>

      <section v-else class="flex flex-col gap-3" aria-labelledby="acad-frame-slots-readonly">
        <h2 id="acad-frame-slots-readonly" class="text-sm font-semibold">
          {{ t('acad.timetable.slots.title') }}
        </h2>
        <p v-if="frame.slots.length === 0" class="text-muted-foreground text-sm" role="note">
          {{ t('acad.timetable.slots.empty') }}
        </p>
        <DataTable
          v-else
          table-id="acad.frame_slots"
          :caption="t('acad.timetable.slots.caption')"
          :columns="slotColumns"
          mode="local"
          :fetcher="fetchSlots"
          :empty-title="t('acad.timetable.slots.empty')"
          :card-heading-level="3"
          hide-single-page-footer
        />

        <div v-if="canDelete">
          <Button type="button" variant="outline" :disabled="actions.busy.value" @click="remove">
            {{ t('acad.detail.delete') }}
          </Button>
        </div>
      </section>
    </template>

    <ConfirmDialog
      :open="actions.confirmation.open.value"
      :request="actions.confirmation.request.value"
      @confirm="actions.confirmation.confirm"
      @cancel="actions.confirmation.cancel"
    />
  </div>
</template>
