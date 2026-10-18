<script setup lang="ts">
/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.3` punto 2, `§18.4.5`, `CA-ACAD-122`,
 * `-123`, `-125`, `CA-ACAD-134`. Diálogo de una sesión de horario.
 *
 * - **Alta** (desde una celda vacía de la vista de un grupo): se elige la asignación
 *   docente del grupo (asignatura, subgrupo y profesor) y un aula opcional; la
 *   franja es la de la celda.
 * - **Edición** («mover» es editar, sin arrastre, WCAG 2.5.7): selector de franja
 *   del mismo marco y de aula; `teaching_assignment` es inmutable (`RN-ACAD-122`).
 *
 * Un `422 timetable_conflict` (`RN-ACAD-123`) se muestra **como texto junto a la
 * acción**, nombrando cada sesión con la que choca (se lee su detalle por su
 * `public_id`): no solo con color (WCAG 1.4.1). Cualquier otro error del servidor
 * ya viene traducido (`ADR-038 §6.3`).
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
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { getTimetableFrame, getTimetableSession } from '../api'
import { problemDetail, problemFieldEntries, problemFieldErrors } from '../composables/problem'
import type { Facility, TimeSlot, TimetableConflict, TimetableSession } from '../types'

/** Una asignación del grupo, ya con la etiqueta que se muestra. */
export interface AssignmentOption {
  public_id: string
  label: string
}

const props = defineProps<{
  open: boolean
  /** `null` = alta. */
  session: TimetableSession | null
  /** Alta: franja de la celda activada. */
  targetSlot: TimeSlot | null
  canRemove: boolean
  assignments: AssignmentOption[]
  /** Solo las de tipo `aula` (`RN-ACAD-121`). */
  facilities: Facility[]
  save: (body: Record<string, unknown>) => Promise<unknown>
}>()

const emit = defineEmits<{ 'update:open': [value: boolean]; saved: []; remove: [] }>()

const t = useT()
const NO_FACILITY = '__none__'

const mode = computed(() => (props.session === null ? 'create' : 'edit'))

const assignment = ref('')
const facility = ref(NO_FACILITY)
const timeSlot = ref('')
const frameSlots = ref<TimeSlot[]>([])

const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)
const conflicts = ref<string[]>([])

function weekdayLabel(weekday: number): string {
  const key = `acad.weekday.${weekday}`
  const text = t(key)

  return text === key ? String(weekday) : text
}

function slotLabel(slot: TimeSlot): string {
  return `${weekdayLabel(slot.weekday)} ${t('acad.timetable.grid.range', { start: slot.starts_at, end: slot.ends_at })}`
}

watch(
  () => [props.open, props.session?.public_id, props.targetSlot?.public_id],
  async () => {
    if (!props.open) return

    fieldErrors.value = {}
    formError.value = null
    conflicts.value = []
    assignment.value = ''
    facility.value = props.session?.facility?.deleted
      ? NO_FACILITY
      : (props.session?.facility?.public_id ?? NO_FACILITY)
    timeSlot.value = props.session?.slot.public_id ?? ''
    frameSlots.value = []

    if (props.session !== null) {
      try {
        frameSlots.value = (await getTimetableFrame(props.session.frame)).slots
      } catch {
        frameSlots.value = [props.session.slot]
      }
    }
  },
  { immediate: true },
)

const sessionText = computed(() => {
  const current = props.session

  return current
    ? `${current.subject.name} · ${current.group.code}${current.subgroup ? ` / ${current.subgroup.code}` : ''}${current.teacher ? ` · ${current.teacher.name}` : ''}`
    : ''
})

/** Errores de campos que el formulario no pinta junto a su campo. */
const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => !['time_slot', 'teaching_assignment', 'facility'].includes(field))
    .flatMap(([, messages]) => messages),
)

/** Nombre de la sesión con la que choca (`RN-ACAD-123`): se lee su detalle; si falla, su `public_id`. */
async function describe(conflict: TimetableConflict): Promise<string> {
  const kind = t(`acad.timetable.conflict.kind.${conflict.kind}`)
  const kindText = kind === `acad.timetable.conflict.kind.${conflict.kind}` ? conflict.kind : kind

  try {
    const other = await getTimetableSession(conflict.session)

    return t('acad.timetable.conflict.with', {
      kind: kindText,
      subject: other.subject.name,
      group: other.subgroup ? `${other.group.code} / ${other.subgroup.code}` : other.group.code,
      teacher: other.teacher?.name ?? '—',
      slot: slotLabel(other.slot),
    })
  } catch {
    return t('acad.timetable.conflict.withUnknown', { kind: kindText, session: conflict.session })
  }
}

function buildBody(): Record<string, unknown> {
  const current = props.session
  const chosenFacility = facility.value === NO_FACILITY ? null : facility.value

  if (current === null) {
    return {
      time_slot: props.targetSlot?.public_id,
      teaching_assignment: assignment.value,
      ...(chosenFacility !== null ? { facility: chosenFacility } : {}),
    }
  }

  const body: Record<string, unknown> = {}

  if (timeSlot.value !== current.slot.public_id) body.time_slot = timeSlot.value
  if (chosenFacility !== (current.facility?.public_id ?? null)) body.facility = chosenFacility

  return body
}

async function submit(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null
  conflicts.value = []

  if (props.session === null && assignment.value === '') {
    fieldErrors.value = { teaching_assignment: [t('acad.timetable.form.assignmentRequired')] }

    return
  }

  const body = buildBody()

  if (props.session !== null && Object.keys(body).length === 0) {
    emit('update:open', false)

    return
  }

  busy.value = true

  try {
    await props.save(body)
    emit('saved')
    emit('update:open', false)
  } catch (err) {
    fieldErrors.value = problemFieldErrors(err)

    const entries = problemFieldEntries(err).time_slot ?? []
    const conflict = entries.find(
      ({ code: found }) => found === 'acad.validation.timetable_conflict',
    )
    const list = conflict?.params?.conflicts

    if (Array.isArray(list)) {
      conflicts.value = await Promise.all((list as TimetableConflict[]).map(describe))
    }

    formError.value =
      problemDetail(err) ??
      (Object.keys(fieldErrors.value).length === 0 ? t('acad.errors.unexpected') : null)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('acad.form.close')" data-slot="acad-session-form">
      <DialogHeader>
        <DialogTitle>{{ t(`acad.timetable.form.title.${mode}`) }}</DialogTitle>
        <DialogDescription>
          <template v-if="session === null && targetSlot">{{ slotLabel(targetSlot) }}</template>
          <template v-else>{{ sessionText }}</template>
        </DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div v-if="session === null" class="flex flex-col gap-1.5">
          <Label for="acad-session-assignment">{{ t('acad.timetable.form.assignment') }}</Label>
          <Select v-model="assignment" required>
            <SelectTrigger
              id="acad-session-assignment"
              :aria-invalid="fieldErrors.teaching_assignment ? 'true' : undefined"
              :aria-describedby="
                fieldErrors.teaching_assignment ? 'acad-session-assignment-errors' : undefined
              "
            >
              <SelectValue :placeholder="t('acad.form.choose')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="option in assignments"
                :key="option.public_id"
                :value="option.public_id"
              >
                {{ option.label }}
              </SelectItem>
            </SelectContent>
          </Select>
          <p v-if="assignments.length === 0" class="text-muted-foreground text-xs">
            {{ t('acad.timetable.form.noAssignments') }}
          </p>
          <ul
            v-if="fieldErrors.teaching_assignment"
            id="acad-session-assignment-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.teaching_assignment" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div v-else class="flex flex-col gap-1.5">
          <Label for="acad-session-slot">{{ t('acad.timetable.form.slot') }}</Label>
          <Select v-model="timeSlot">
            <SelectTrigger
              id="acad-session-slot"
              :aria-invalid="fieldErrors.time_slot ? 'true' : undefined"
              :aria-describedby="fieldErrors.time_slot ? 'acad-session-slot-errors' : undefined"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="option in frameSlots"
                :key="option.public_id"
                :value="option.public_id"
              >
                {{ slotLabel(option) }}
              </SelectItem>
            </SelectContent>
          </Select>
          <ul
            v-if="fieldErrors.time_slot"
            id="acad-session-slot-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.time_slot" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="acad-session-facility">{{ t('acad.timetable.form.facility') }}</Label>
          <Select v-model="facility">
            <SelectTrigger
              id="acad-session-facility"
              :aria-invalid="fieldErrors.facility ? 'true' : undefined"
              :aria-describedby="fieldErrors.facility ? 'acad-session-facility-errors' : undefined"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem :value="NO_FACILITY">{{
                t('acad.timetable.form.noFacility')
              }}</SelectItem>
              <SelectItem
                v-for="option in facilities"
                :key="option.public_id"
                :value="option.public_id"
              >
                {{ option.code }} · {{ option.name }}
              </SelectItem>
            </SelectContent>
          </Select>
          <ul
            v-if="fieldErrors.facility"
            id="acad-session-facility-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.facility" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div
          v-if="conflicts.length > 0"
          role="alert"
          class="text-destructive text-sm"
          data-slot="acad-session-conflicts"
        >
          <p class="font-medium">{{ t('acad.timetable.conflict.title') }}</p>
          <ul class="list-disc pl-5">
            <li v-for="line in conflicts" :key="line">{{ line }}</li>
          </ul>
        </div>

        <ul
          v-if="otherErrors.length > 0 && conflicts.length === 0"
          role="alert"
          class="text-destructive text-sm"
        >
          <li v-for="message in otherErrors" :key="message">{{ message }}</li>
        </ul>
        <p v-if="formError && conflicts.length === 0" role="alert" class="text-destructive text-sm">
          {{ formError }}
        </p>

        <DialogFooter>
          <Button
            v-if="session !== null && canRemove"
            type="button"
            variant="outline"
            :disabled="busy"
            @click="emit('remove')"
          >
            {{ t('acad.timetable.form.remove') }}
          </Button>
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('acad.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="busy">{{ t('acad.form.save') }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
