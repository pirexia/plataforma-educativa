<script setup lang="ts">
/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.8`, `§18.4.4` (`RN-ACAD-112`,
 * `RN-ACAD-113`), `CA-ACAD-118`, `CA-ACAD-119`. Editor de las franjas de un
 * marco como **lista** (conjunto completo, no una rejilla): día de la semana
 * (1 = lunes … 7 = domingo), hora de inicio y hora de fin (`HH:MM`, 24 h, hora
 * civil del centro, `ADR-065`), intervalo semiabierto `[inicio, fin)`.
 *
 * Cada fila es un `fieldset` con su leyenda («Franja n»); los errores del
 * servidor (`slots.<n>.<campo>`) ya vienen traducidos y se asocian a su campo con
 * `aria-describedby`; el error no se comunica solo con color (WCAG 1.4.1). Añadir
 * y quitar son botones; no hay arrastre (WCAG 2.5.7). Una franja existente lleva
 * su `public_id` (la actualiza); una nueva, no (`RN-ACAD-113`).
 */
import { computed } from 'vue'
import { Plus, Trash2 } from '@lucide/vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

export interface SlotRow {
  /** Clave de la fila en la lista (estable mientras se edita). */
  key: number
  /** `public_id` de la franja existente; ausente en una nueva. */
  public_id?: string
  /** El `Select` entrega cadenas: se convierte a número al enviar. */
  weekday: string
  starts_at: string
  ends_at: string
}

const WEEKDAYS = ['1', '2', '3', '4', '5', '6', '7']
/** `RN-ACAD-113` [DERIVADA]: tope del servidor. */
const MAX_SLOTS = 100

const props = defineProps<{
  modelValue: SlotRow[]
  /** Errores por campo (`slots.0.weekday`, `slots.1.starts_at`, `slots`), ya traducidos. */
  errors?: Record<string, string[]>
  disabled?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: SlotRow[]] }>()

const t = useT()

const canAdd = computed(() => !props.disabled && props.modelValue.length < MAX_SLOTS)

function nextKey(): number {
  return props.modelValue.reduce((max, row) => Math.max(max, row.key), 0) + 1
}

function update(index: number, patch: Partial<SlotRow>): void {
  emit(
    'update:modelValue',
    props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row)),
  )
}

function add(): void {
  const last = props.modelValue.at(-1)

  emit('update:modelValue', [
    ...props.modelValue,
    {
      key: nextKey(),
      weekday: last?.weekday ?? '1',
      // Sugiere la franja siguiente: empieza donde acaba la anterior.
      starts_at: last?.ends_at ?? '',
      ends_at: '',
    },
  ])
}

function remove(index: number): void {
  emit(
    'update:modelValue',
    props.modelValue.filter((_, i) => i !== index),
  )
}

function messages(index: number, field: string): string[] {
  return props.errors?.[`slots.${index}.${field}`] ?? []
}

function weekdayLabel(day: string): string {
  return t(`acad.weekday.${day}`)
}

const generalErrors = computed(() => [
  ...(props.errors?.['slots'] ?? []),
  ...Object.entries(props.errors ?? {})
    .filter(([field]) => /^slots\.\d+$/.test(field))
    .flatMap(([, list]) => list),
])
</script>

<template>
  <div class="flex flex-col gap-3" data-slot="acad-slots-editor">
    <p class="text-muted-foreground text-sm">{{ t('acad.timetable.slots.hint') }}</p>

    <ul v-if="generalErrors.length > 0" role="alert" class="text-destructive text-sm">
      <li v-for="message in generalErrors" :key="message">{{ message }}</li>
    </ul>

    <p v-if="modelValue.length === 0" class="text-muted-foreground text-sm" role="note">
      {{ t('acad.timetable.slots.empty') }}
    </p>

    <ol class="flex flex-col gap-3">
      <li v-for="(row, index) in modelValue" :key="row.key">
        <fieldset
          class="border-border grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_1fr_1fr_auto]"
        >
          <legend class="px-1 text-sm font-medium">
            {{ t('acad.timetable.slots.rowLegend', { number: index + 1 }) }}
          </legend>

          <div class="flex flex-col gap-1.5">
            <Label :for="`acad-slot-${row.key}-weekday`">{{
              t('acad.timetable.slots.weekday')
            }}</Label>
            <Select
              :model-value="row.weekday"
              :disabled="disabled"
              @update:model-value="update(index, { weekday: String($event) })"
            >
              <SelectTrigger
                :id="`acad-slot-${row.key}-weekday`"
                :aria-invalid="messages(index, 'weekday').length > 0 ? 'true' : undefined"
                :aria-describedby="
                  messages(index, 'weekday').length > 0
                    ? `acad-slot-${row.key}-weekday-errors`
                    : undefined
                "
              >
                <SelectValue :placeholder="t('acad.form.choose')" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="day in WEEKDAYS" :key="day" :value="day">
                  {{ weekdayLabel(day) }}
                </SelectItem>
              </SelectContent>
            </Select>
            <ul
              v-if="messages(index, 'weekday').length > 0"
              :id="`acad-slot-${row.key}-weekday-errors`"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in messages(index, 'weekday')" :key="message">{{ message }}</li>
            </ul>
          </div>

          <div class="flex flex-col gap-1.5">
            <Label :for="`acad-slot-${row.key}-starts`">{{
              t('acad.timetable.slots.startsAt')
            }}</Label>
            <Input
              :id="`acad-slot-${row.key}-starts`"
              type="time"
              :model-value="row.starts_at"
              :disabled="disabled"
              required
              :aria-invalid="messages(index, 'starts_at').length > 0 ? 'true' : undefined"
              :aria-describedby="
                messages(index, 'starts_at').length > 0
                  ? `acad-slot-${row.key}-starts-errors`
                  : undefined
              "
              @update:model-value="update(index, { starts_at: String($event) })"
            />
            <ul
              v-if="messages(index, 'starts_at').length > 0"
              :id="`acad-slot-${row.key}-starts-errors`"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in messages(index, 'starts_at')" :key="message">{{ message }}</li>
            </ul>
          </div>

          <div class="flex flex-col gap-1.5">
            <Label :for="`acad-slot-${row.key}-ends`">{{ t('acad.timetable.slots.endsAt') }}</Label>
            <Input
              :id="`acad-slot-${row.key}-ends`"
              type="time"
              :model-value="row.ends_at"
              :disabled="disabled"
              required
              :aria-invalid="messages(index, 'ends_at').length > 0 ? 'true' : undefined"
              :aria-describedby="
                messages(index, 'ends_at').length > 0
                  ? `acad-slot-${row.key}-ends-errors`
                  : undefined
              "
              @update:model-value="update(index, { ends_at: String($event) })"
            />
            <ul
              v-if="messages(index, 'ends_at').length > 0"
              :id="`acad-slot-${row.key}-ends-errors`"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in messages(index, 'ends_at')" :key="message">{{ message }}</li>
            </ul>
          </div>

          <div class="flex items-end">
            <Button
              v-if="!disabled"
              type="button"
              variant="outline"
              size="sm"
              :aria-label="t('acad.timetable.slots.removeFor', { number: index + 1 })"
              @click="remove(index)"
            >
              <Trash2 aria-hidden="true" class="size-4" />
              {{ t('acad.timetable.slots.remove') }}
            </Button>
          </div>
        </fieldset>
      </li>
    </ol>

    <div v-if="!disabled">
      <Button type="button" variant="outline" size="sm" :disabled="!canAdd" @click="add">
        <Plus aria-hidden="true" class="size-4" />
        {{ t('acad.timetable.slots.add') }}
      </Button>
    </div>
  </div>
</template>
