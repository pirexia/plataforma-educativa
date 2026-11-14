<script setup lang="ts">
/**
 * `docs/modulos/REQ-ACAD/funcional.md §10.5.2`, `§10.5.4`, `CA-ACAD-099`,
 * `CA-ACAD-100`. Editor de la lista de valores (cualitativa) o tramos (mixta)
 * de una escala. El orden de la lista es el `position` (`RN-ACAD-83`).
 *
 * - Cada fila es un `fieldset` con `legend` («Valor 2»); «Añadir», «Quitar» y
 *   «Subir»/«Bajar» **con botón**: no hay arrastre como única vía (WCAG 2.5.7).
 * - Foco: al elemento nuevo al añadir; a la fila que ocupa el sitio de la
 *   quitada (o a «Añadir» si no queda ninguna); al mismo botón de la fila que
 *   se mueve. Un cambio de orden se anuncia en una región viva.
 * - Mixta: la columna «Aprobado» es de solo lectura y se recalcula con el umbral
 *   (`RN-ACAD-87`), en texto (WCAG 1.4.1). Decimales como cadena (`RN-ACAD-75`).
 * - Los errores `values.<n>.<campo>` se pintan en su fila y los de la lista
 *   sobre ella, enlazados con `aria-describedby`. La validación de cliente no
 *   existe aquí: decide el servidor (`INV-010`).
 */
import { computed, nextTick, ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import TranslatableField from '@/components/TranslatableField.vue'
import type { Locale } from '@/modules/core/types'
import type { TranslationValues } from '@/lib/translatable'
import { bandIsPassing, emptyRow, ROW_FIELDS, type ValueRow } from '../composables/scaleForm'

const props = defineProps<{
  type: 'cualitativa' | 'mixta'
  /** Umbral de aprobado de la escala (mixta), tal cual está escrito. */
  passingThreshold: string
  locales: readonly Locale[]
  defaultLocale: Locale
  /** Errores de la lista entera (`errors.values`). */
  listErrors: string[]
  /** Errores por fila (`errors.values.<n>.<campo>`). */
  rowErrors: Record<number, Record<string, string[]>>
}>()

const rows = defineModel<ValueRow[]>({ required: true })

const t = useT()

const announcement = ref('')

const isBands = computed(() => props.type === 'mixta')
const headingId = 'acad-scale-values-heading'
const listErrorsId = 'acad-scale-values-errors'
const addId = 'acad-scale-values-add'

function legend(index: number): string {
  return t('acad.scales.itemLegend', { n: index + 1 })
}

function id(row: ValueRow, field: string): string {
  return `acad-scale-${row.key}-${field}`
}

function errorsOf(index: number, field: string): string[] {
  return props.rowErrors[index]?.[field] ?? []
}

function labelLocaleErrors(index: number): Partial<Record<Locale, string[]>> {
  const result: Partial<Record<Locale, string[]>> = {}
  const prefix = 'label_translations.'

  for (const [field, messages] of Object.entries(props.rowErrors[index] ?? {})) {
    if (field.startsWith(prefix)) {
      result[field.slice(prefix.length) as Locale] = messages
    }
  }

  return result
}

/** Errores de la fila que ningún control pinta (`public_id`: `value_not_in_scale`, ...). */
function otherErrors(index: number): string[] {
  return Object.entries(props.rowErrors[index] ?? {})
    .filter(([field]) => !ROW_FIELDS.includes(field) && !field.startsWith('label_translations.'))
    .flatMap(([, messages]) => messages)
}

function describedBy(row: ValueRow, index: number, field: string): string | undefined {
  return errorsOf(index, field).length > 0 ? id(row, `${field}-errors`) : undefined
}

function update(index: number, patch: Partial<ValueRow>): void {
  rows.value = rows.value.map((row, position) => (position === index ? { ...row, ...patch } : row))
}

function focusById(target: string): void {
  void nextTick(() => document.getElementById(target)?.focus())
}

function add(): void {
  const row = emptyRow()

  rows.value = [...rows.value, row]
  focusById(id(row, 'code'))
}

function remove(index: number): void {
  const next = rows.value.filter((_, position) => position !== index)

  rows.value = next

  const target = next[index] ?? next[index - 1]

  focusById(target ? id(target, 'code') : addId)
}

function describe(row: ValueRow, index: number): string {
  const label = (row.labels[props.defaultLocale] ?? '').trim()

  return label !== '' ? label : row.code.trim() !== '' ? row.code.trim() : legend(index)
}

function move(index: number, direction: -1 | 1): void {
  const to = index + direction

  if (to < 0 || to >= rows.value.length) {
    return
  }

  const next = [...rows.value]
  const [row] = next.splice(index, 1)

  next.splice(to, 0, row!)
  rows.value = next
  announcement.value = t('acad.scales.moved', { label: describe(row!, index), n: to + 1 })

  // El botón pulsado puede quedar deshabilitado en el extremo: el foco pasa al contrario.
  const same = direction === -1 ? 'up' : 'down'
  const other = direction === -1 ? 'down' : 'up'
  const atEdge = direction === -1 ? to === 0 : to === next.length - 1

  focusById(id(row!, atEdge ? other : same))
}

function passing(row: ValueRow): string {
  const result = bandIsPassing(row.range_min, props.passingThreshold)

  return result === null ? '' : result ? t('acad.scales.passing') : t('acad.scales.failing')
}

function setLabels(index: number, labels: TranslationValues): void {
  update(index, { labels })
}
</script>

<template>
  <section class="flex flex-col gap-3" :aria-labelledby="headingId" data-slot="acad-scale-values">
    <h2 :id="headingId" class="text-sm font-semibold">
      {{ isBands ? t('acad.scales.bands') : t('acad.scales.values') }}
    </h2>

    <ul
      v-if="listErrors.length > 0"
      :id="listErrorsId"
      role="alert"
      tabindex="-1"
      class="text-destructive text-sm"
    >
      <li v-for="message in listErrors" :key="message">{{ message }}</li>
    </ul>

    <div
      role="group"
      :aria-labelledby="headingId"
      :aria-describedby="listErrors.length > 0 ? listErrorsId : undefined"
      class="flex flex-col gap-3"
    >
      <fieldset
        v-for="(row, index) in rows"
        :key="row.key"
        class="border-border flex min-w-0 flex-col gap-3 rounded-lg border p-3"
        data-slot="acad-scale-value"
      >
        <legend :id="id(row, 'legend')" class="px-1 text-sm font-medium">
          {{ legend(index) }}
        </legend>

        <div v-if="otherErrors(index).length > 0" role="alert" class="text-destructive text-sm">
          <p v-for="message in otherErrors(index)" :key="message">{{ message }}</p>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label :for="id(row, 'code')">{{ t('acad.fields.code') }}</Label>
          <Input
            :id="id(row, 'code')"
            :model-value="row.code"
            type="text"
            autocomplete="off"
            required
            :aria-invalid="errorsOf(index, 'code').length > 0 ? 'true' : undefined"
            :aria-describedby="describedBy(row, index, 'code')"
            @update:model-value="update(index, { code: String($event ?? '') })"
          />
          <ul
            v-if="errorsOf(index, 'code').length > 0"
            :id="id(row, 'code-errors')"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in errorsOf(index, 'code')" :key="message">{{ message }}</li>
          </ul>
        </div>

        <TranslatableField
          :model-value="row.labels"
          :label="t('acad.fields.label')"
          :locales="locales"
          :default-locale="defaultLocale"
          :id-base="id(row, 'label')"
          :locale-errors="labelLocaleErrors(index)"
          :field-errors="errorsOf(index, 'label_translations')"
          @update:model-value="setLabels(index, $event)"
        />

        <div v-if="!isBands" class="flex flex-col gap-1.5">
          <Label :for="id(row, 'is_passing')" class="flex items-center gap-2 font-normal">
            <input
              :id="id(row, 'is_passing')"
              type="checkbox"
              class="size-4"
              :checked="row.is_passing"
              :aria-invalid="errorsOf(index, 'is_passing').length > 0 ? 'true' : undefined"
              :aria-describedby="describedBy(row, index, 'is_passing')"
              @change="update(index, { is_passing: ($event.target as HTMLInputElement).checked })"
            />
            {{ t('acad.fields.isPassing') }}
          </Label>
          <ul
            v-if="errorsOf(index, 'is_passing').length > 0"
            :id="id(row, 'is_passing-errors')"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in errorsOf(index, 'is_passing')" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div class="flex min-w-0 flex-col gap-1.5">
            <Label :for="id(row, 'range_min')">{{ t('acad.fields.rangeMin') }}</Label>
            <Input
              :id="id(row, 'range_min')"
              :model-value="row.range_min"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              required
              :aria-invalid="errorsOf(index, 'range_min').length > 0 ? 'true' : undefined"
              :aria-describedby="describedBy(row, index, 'range_min')"
              @update:model-value="update(index, { range_min: String($event ?? '') })"
            />
            <ul
              v-if="errorsOf(index, 'range_min').length > 0"
              :id="id(row, 'range_min-errors')"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in errorsOf(index, 'range_min')" :key="message">{{ message }}</li>
            </ul>
          </div>

          <div class="flex min-w-0 flex-col gap-1.5">
            <Label :for="id(row, 'range_max')">{{ t('acad.fields.rangeMax') }}</Label>
            <Input
              :id="id(row, 'range_max')"
              :model-value="row.range_max"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              required
              :aria-invalid="errorsOf(index, 'range_max').length > 0 ? 'true' : undefined"
              :aria-describedby="describedBy(row, index, 'range_max')"
              @update:model-value="update(index, { range_max: String($event ?? '') })"
            />
            <ul
              v-if="errorsOf(index, 'range_max').length > 0"
              :id="id(row, 'range_max-errors')"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in errorsOf(index, 'range_max')" :key="message">{{ message }}</li>
            </ul>
          </div>

          <div class="flex min-w-0 flex-col gap-1.5" data-slot="acad-scale-band-passing">
            <span class="text-sm font-medium">{{ t('acad.scales.passing') }}</span>
            <output class="text-sm">{{ passing(row) }}</output>
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <Button
            :id="id(row, 'up')"
            type="button"
            variant="outline"
            size="sm"
            :disabled="index === 0"
            :aria-describedby="id(row, 'legend')"
            @click="move(index, -1)"
          >
            {{ t('acad.scales.moveUp') }}
          </Button>
          <Button
            :id="id(row, 'down')"
            type="button"
            variant="outline"
            size="sm"
            :disabled="index === rows.length - 1"
            :aria-describedby="id(row, 'legend')"
            @click="move(index, 1)"
          >
            {{ t('acad.scales.moveDown') }}
          </Button>
          <Button
            :id="id(row, 'remove')"
            type="button"
            variant="outline"
            size="sm"
            :aria-describedby="id(row, 'legend')"
            @click="remove(index)"
          >
            {{ t('acad.scales.remove') }}
          </Button>
        </div>
      </fieldset>
    </div>

    <div>
      <Button :id="addId" type="button" variant="outline" size="sm" @click="add">
        {{ isBands ? t('acad.scales.addBand') : t('acad.scales.addValue') }}
      </Button>
    </div>

    <p role="status" aria-live="polite" class="sr-only" data-slot="acad-scale-announcement">
      {{ announcement }}
    </p>
  </section>
</template>
