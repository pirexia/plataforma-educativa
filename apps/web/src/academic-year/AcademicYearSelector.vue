<script setup lang="ts">
/**
 * `docs/modulos/REQ-ACAD/funcional.md §10.2`, `§4.7`, `RN-ACAD-40..42`,
 * `CA-ACAD-047` (`REQ-CURSO-001` punto 3). Selector de curso académico de la
 * barra superior del *shell*: **solo** en rutas con `meta.academicYearScoped`.
 *
 * - Las opciones son las de `GET /academic-years/selectable` (#409).
 * - Elegir otro curso navega a la misma ruta con `?academic_year=<public_id>`;
 *   nada se guarda en `localStorage` ni en sesión.
 * - En una ficha (`usePinAcademicYear`) queda **fijo** en el curso de la entidad:
 *   se muestra como texto, no como control.
 * - Un curso `cerrado`/`archivado` se rotula con texto («solo lectura»), no solo
 *   con color (WCAG 1.4.1). El cambio se anuncia a los lectores de pantalla
 *   (región `status`, WCAG 4.1.3).
 */
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useT } from '@/i18n'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { isWritableStatus, useAcademicYear, useSelectableYearsSync } from './useAcademicYear'

const t = useT()
const route = useRoute()
useSelectableYearsSync()
const { options, status, selectedId, selected, isPinned, select } = useAcademicYear()

const visible = computed(() => route.meta.academicYearScoped === true)

/** `ADR-038 §7.3`: un estado no anticipado muestra su código y se trata como solo lectura. */
function statusLabel(value: string): string {
  const key = `academicYear.status.${value}`
  const label = t(key)

  return label === key ? value : label
}

function optionLabel(code: string, state: string): string {
  return isWritableStatus(state)
    ? t('academicYear.selector.option', { code, status: statusLabel(state) })
    : t('academicYear.selector.optionReadOnly', { code, status: statusLabel(state) })
}

const announcement = ref('')

watch(selectedId, (next, previous) => {
  if (previous === undefined || next === previous) {
    return
  }

  const year = options.value.find((candidate) => candidate.public_id === next)

  announcement.value = year ? t('academicYear.selector.changed', { code: year.code }) : ''
})

function onSelect(value: unknown): void {
  if (typeof value === 'string' && value !== '' && value !== selectedId.value) {
    void select(value)
  }
}
</script>

<template>
  <div
    v-if="visible"
    data-slot="academic-year-selector"
    class="flex min-w-0 flex-wrap items-center gap-2 text-sm"
  >
    <Label for="academic-year-select">
      {{ t('academicYear.selector.label') }}
    </Label>

    <p
      v-if="isPinned && selected"
      id="academic-year-select"
      class="min-w-0 font-medium break-words"
      data-slot="academic-year-pinned"
    >
      {{ optionLabel(selected.code, selected.status) }}
    </p>

    <Select v-else :model-value="selectedId ?? ''" @update:model-value="onSelect">
      <SelectTrigger id="academic-year-select" class="min-w-40 max-w-full">
        <SelectValue :placeholder="t('academicYear.selector.placeholder')" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem v-for="year in options" :key="year.public_id" :value="year.public_id">
          {{ optionLabel(year.code, year.status) }}
        </SelectItem>
      </SelectContent>
    </Select>

    <p v-if="status === 'error'" role="alert" class="text-destructive text-xs">
      {{ t('academicYear.selector.loadError') }}
    </p>

    <p class="sr-only" role="status" aria-live="polite" aria-atomic="true">{{ announcement }}</p>
  </div>
</template>
