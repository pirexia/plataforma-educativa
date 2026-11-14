<script setup lang="ts">
/**
 * `/academico/escalas/:publicId` (`docs/modulos/REQ-ACAD/funcional.md §10.5.1`,
 * `§4.9.1`, `§4.9.4`). Ficha de una escala: datos, y valores o tramos en orden
 * con su etiqueta y si son aprobado (**texto**, no solo color, WCAG 1.4.1).
 * El selector de curso académico queda fijo en el de la escala. «Editar» y
 * «Borrar» solo con su permiso **y** si el curso admite escritura
 * (`CA-ACAD-099`); el borrado pide confirmación (`RN-CORE-64`) y un `409` se
 * muestra junto a la acción sin cambiar la pantalla (`CA-ACAD-050`).
 */
import { computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAcademicYear, usePinAcademicYear } from '@/academic-year'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import TranslatedText from '@/components/TranslatedText.vue'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import StructureNotices from '../components/StructureNotices.vue'
import { deleteGradingScale, getGradingScale } from '../api'
import { setFlash, takeFlash } from '../composables/flash'
import { hasNumericFields } from '../composables/scaleForm'
import { useLabels } from '../composables/useLabels'
import { useLoad } from '../composables/useLoad'
import { usePermissions } from '../composables/usePermissions'
import { useStructureActions } from '../composables/useStructureActions'
import type { GradingScale } from '../types'

const t = useT()
const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const { scaleTypeLabel } = useLabels()
const actions = useStructureActions()

const publicId = computed(() => String(route.params.publicId))
const {
  data: scale,
  loading,
  error,
  load,
} = useLoad<GradingScale>(() => getGradingScale(publicId.value))

usePinAcademicYear(computed(() => scale.value?.academic_year ?? null))
const { readOnly, writable } = useAcademicYear()

const canUpdate = computed(() => can('configuracion_academica.actualizar') && writable.value)
const canDelete = computed(() => can('configuracion_academica.eliminar') && writable.value)
const numeric = computed(() => (scale.value ? hasNumericFields(scale.value.scale_type) : false))
const isBands = computed(() => scale.value?.scale_type === 'mixta')

onMounted(async () => {
  const key = takeFlash()

  await load()

  if (key !== null) {
    actions.message.value = t(key)
  }
})

async function remove(): Promise<void> {
  const current = scale.value

  if (!current) return

  const done = await actions.confirmAndRun(
    {
      title: t('acad.confirm.delete.scale.title', { code: current.code }),
      description: t('acad.confirm.delete.scale.description'),
      confirmLabel: t('acad.confirm.delete.confirm', { code: current.code }),
      destructive: true,
    },
    () => deleteGradingScale(current.public_id),
    t('acad.flash.scaleDeleted'),
  )

  if (done) {
    setFlash('acad.flash.scaleDeleted')
    void router.push({
      name: 'acad-grading-scales',
      query: { academic_year: current.academic_year },
    })
  }
}

function passingText(value: boolean | null): string {
  return value === true ? t('acad.scales.passing') : t('acad.scales.failing')
}
</script>

<template>
  <div class="mx-auto flex max-w-5xl flex-col gap-6 px-4 py-6">
    <LoadingState v-if="loading" />
    <ErrorState v-else-if="error" :state="error" @retry="load" />

    <template v-else-if="scale">
      <header class="flex flex-col gap-1">
        <p class="text-sm">
          <RouterLink
            :to="{ name: 'acad-grading-scales', query: { academic_year: scale.academic_year } }"
            class="text-primary-on-background underline underline-offset-4"
          >
            {{ t('acad.nav.backToScales') }}
          </RouterLink>
        </p>
        <h1 class="text-lg font-semibold">
          {{ scale.code }} ·
          <TranslatedText :text="scale.name" :translations="scale.name_translations" />
        </h1>
      </header>

      <StructureNotices
        :read-only="false"
        :message="actions.message.value"
        :action-error="actions.actionError.value"
        :dependents="actions.dependents.value"
      />

      <p
        v-if="readOnly"
        role="note"
        data-slot="acad-scale-read-only"
        class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
      >
        {{ t('acad.readOnlyConfig') }}
      </p>

      <section
        v-if="canUpdate || canDelete"
        class="flex flex-wrap gap-2"
        :aria-label="t('acad.detail.actions')"
      >
        <Button v-if="canUpdate" variant="outline" as-child>
          <RouterLink
            :to="{ name: 'acad-grading-scale-edit', params: { publicId: scale.public_id } }"
          >
            {{ t('acad.detail.edit') }}
          </RouterLink>
        </Button>
        <Button
          v-if="canDelete"
          type="button"
          variant="outline"
          :disabled="actions.busy.value"
          @click="remove"
        >
          {{ t('acad.detail.delete') }}
        </Button>
      </section>

      <section aria-labelledby="acad-scale-data">
        <h2 id="acad-scale-data" class="mb-2 text-sm font-semibold">{{ t('acad.detail.data') }}</h2>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-muted-foreground">{{ t('acad.fields.code') }}</dt>
            <dd>{{ scale.code }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground">{{ t('acad.fields.scaleType') }}</dt>
            <dd>{{ scaleTypeLabel(scale.scale_type) }}</dd>
          </div>
          <template v-if="numeric">
            <div>
              <dt class="text-muted-foreground">{{ t('acad.fields.minValue') }}</dt>
              <dd>{{ scale.min_value }}</dd>
            </div>
            <div>
              <dt class="text-muted-foreground">{{ t('acad.fields.maxValue') }}</dt>
              <dd>{{ scale.max_value }}</dd>
            </div>
            <div>
              <dt class="text-muted-foreground">{{ t('acad.fields.decimals') }}</dt>
              <dd>{{ scale.decimals }}</dd>
            </div>
            <div>
              <dt class="text-muted-foreground">{{ t('acad.fields.passingMinValue') }}</dt>
              <dd>{{ scale.passing_min_value }}</dd>
            </div>
          </template>
        </dl>
      </section>

      <section
        v-if="scale.values.length > 0"
        aria-labelledby="acad-scale-values"
        data-slot="acad-scale-values"
      >
        <h2 id="acad-scale-values" class="mb-2 text-sm font-semibold">
          {{ isBands ? t('acad.scales.bands') : t('acad.scales.values') }}
        </h2>
        <ol class="flex flex-col gap-2 text-sm">
          <li
            v-for="value in scale.values"
            :key="value.public_id"
            class="border-border flex flex-wrap items-baseline gap-x-4 gap-y-1 rounded-lg border px-3 py-2"
          >
            <span class="font-medium">{{ value.code }}</span>
            <TranslatedText :text="value.label" :translations="value.label_translations" />
            <span v-if="isBands" class="text-muted-foreground">
              {{
                t('acad.scales.rangeValue', {
                  min: value.range_min ?? '',
                  max: value.range_max ?? '',
                })
              }}
            </span>
            <span class="ms-auto font-medium">{{ passingText(value.is_passing) }}</span>
          </li>
        </ol>
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
