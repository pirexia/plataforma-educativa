<script setup lang="ts">
/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.3` punto 5, `§18.4.6` (`RN-ACAD-140`,
 * `-141`), `CA-ACAD-138`. Diálogo de alta de una reserva de un espacio (aula o
 * recurso): espacio, fecha y un intervalo `[inicio, fin)` (hora civil del centro).
 * Las reservas no se editan (`RN-ACAD-142`): solo hay alta. El curso académico es
 * el seleccionado y viaja en el cuerpo (`academic_year`, una reserva no tiene
 * padre con curso).
 *
 * Un `422 booking_conflict` se muestra **como texto junto a la acción**, nombrando
 * cada reserva o sesión con la que choca (se lee su detalle por su `public_id`; si
 * no se puede leer —por ejemplo sin `horarios.leer`—, se dice solo el tipo): no solo
 * con color (WCAG 1.4.1). Cualquier otro error del servidor ya viene traducido
 * (`ADR-038 §6.3`) y se muestra junto a su campo.
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
import { getFacilityBooking, getTimetableSession } from '../api'
import { formatCivilDate } from '../composables/civilDate'
import { problemDetail, problemFieldEntries, problemFieldErrors } from '../composables/problem'
import type { BookingConflict, Facility } from '../types'

const props = defineProps<{
  open: boolean
  /** Aulas y recursos (`RN-ACAD-140`: cualquier tipo de espacio). */
  facilities: Facility[]
  /** Fechas del curso seleccionado, para acotar el selector de fecha (el servidor decide igualmente). */
  yearStart: string | null
  yearEnd: string | null
  save: (body: Record<string, unknown>) => Promise<unknown>
}>()

const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()

const facility = ref('')
const bookedOn = ref('')
const startsAt = ref('')
const endsAt = ref('')

const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)
const conflicts = ref<string[]>([])

watch(
  () => props.open,
  (open) => {
    if (!open) return

    facility.value = ''
    bookedOn.value = ''
    startsAt.value = ''
    endsAt.value = ''
    fieldErrors.value = {}
    formError.value = null
    conflicts.value = []
  },
  { immediate: true },
)

const FIELDS = ['facility', 'booked_on', 'starts_at', 'ends_at']

/** Errores de campos que el formulario no pinta junto a su campo. */
const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => !FIELDS.includes(field))
    .flatMap(([, messages]) => messages),
)

function facilityTypeLabel(code: string): string {
  const key = `acad.facilityType.${code}`
  const text = t(key)

  return text === key ? code : text
}

function weekdayLabel(weekday: number): string {
  const key = `acad.weekday.${weekday}`
  const text = t(key)

  return text === key ? String(weekday) : text
}

/** Nombre de la reserva o sesión con la que choca (`RN-ACAD-141`): se lee su detalle; si falla, solo el tipo. */
async function describe(conflict: BookingConflict): Promise<string> {
  try {
    if (conflict.kind === 'booking' && conflict.booking !== undefined) {
      const other = await getFacilityBooking(conflict.booking)

      return t('acad.bookings.conflict.booking', {
        facility: other.facility.code,
        date: formatCivilDate(other.booked_on),
        start: other.starts_at,
        end: other.ends_at,
        by: other.booked_by?.name ?? '—',
      })
    }

    if (conflict.kind === 'session' && conflict.session !== undefined) {
      const other = await getTimetableSession(conflict.session)

      return t('acad.bookings.conflict.session', {
        subject: other.subject.name,
        group: other.subgroup ? `${other.group.code} / ${other.subgroup.code}` : other.group.code,
        teacher: other.teacher?.name ?? '—',
        slot: `${weekdayLabel(other.slot.weekday)} ${other.slot.starts_at}–${other.slot.ends_at}`,
      })
    }
  } catch {
    // Sin permiso para leer la otra entidad (o ya no existe): se dice solo el tipo.
  }

  return conflict.kind === 'session'
    ? t('acad.bookings.conflict.sessionUnknown')
    : t('acad.bookings.conflict.bookingUnknown')
}

async function submit(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null
  conflicts.value = []

  busy.value = true

  try {
    await props.save({
      facility: facility.value,
      booked_on: bookedOn.value,
      starts_at: startsAt.value,
      ends_at: endsAt.value,
    })
    emit('saved')
    emit('update:open', false)
  } catch (err) {
    fieldErrors.value = problemFieldErrors(err)

    const entries = problemFieldEntries(err).booked_on ?? []
    const conflict = entries.find(({ code }) => code === 'acad.validation.booking_conflict')
    const list = conflict?.params?.conflicts

    if (Array.isArray(list)) {
      conflicts.value = await Promise.all((list as BookingConflict[]).map(describe))
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
    <DialogContent :close-label="t('acad.form.close')" data-slot="acad-booking-form">
      <DialogHeader>
        <DialogTitle>{{ t('acad.bookings.form.title') }}</DialogTitle>
        <DialogDescription>{{ t('acad.bookings.form.description') }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="acad-booking-facility">{{ t('acad.bookings.fields.facility') }}</Label>
          <Select v-model="facility" required>
            <SelectTrigger
              id="acad-booking-facility"
              :aria-invalid="fieldErrors.facility ? 'true' : undefined"
              :aria-describedby="fieldErrors.facility ? 'acad-booking-facility-errors' : undefined"
            >
              <SelectValue :placeholder="t('acad.form.choose')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="option in facilities"
                :key="option.public_id"
                :value="option.public_id"
              >
                {{ option.code }} · {{ option.name }} ({{
                  facilityTypeLabel(option.facility_type)
                }})
              </SelectItem>
            </SelectContent>
          </Select>
          <p v-if="facilities.length === 0" class="text-muted-foreground text-xs">
            {{ t('acad.bookings.form.noFacilities') }}
          </p>
          <ul
            v-if="fieldErrors.facility"
            id="acad-booking-facility-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.facility" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="acad-booking-date">{{ t('acad.bookings.fields.date') }}</Label>
          <Input
            id="acad-booking-date"
            v-model="bookedOn"
            type="date"
            required
            :min="yearStart ?? undefined"
            :max="yearEnd ?? undefined"
            :aria-invalid="fieldErrors.booked_on ? 'true' : undefined"
            :aria-describedby="fieldErrors.booked_on ? 'acad-booking-date-errors' : undefined"
          />
          <ul
            v-if="fieldErrors.booked_on && conflicts.length === 0"
            id="acad-booking-date-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.booked_on" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div class="flex flex-col gap-1.5">
            <Label for="acad-booking-starts">{{ t('acad.bookings.fields.startsAt') }}</Label>
            <Input
              id="acad-booking-starts"
              v-model="startsAt"
              type="time"
              required
              :aria-invalid="fieldErrors.starts_at ? 'true' : undefined"
              :aria-describedby="fieldErrors.starts_at ? 'acad-booking-starts-errors' : undefined"
            />
            <ul
              v-if="fieldErrors.starts_at"
              id="acad-booking-starts-errors"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in fieldErrors.starts_at" :key="message">{{ message }}</li>
            </ul>
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="acad-booking-ends">{{ t('acad.bookings.fields.endsAt') }}</Label>
            <Input
              id="acad-booking-ends"
              v-model="endsAt"
              type="time"
              required
              :aria-invalid="fieldErrors.ends_at ? 'true' : undefined"
              :aria-describedby="fieldErrors.ends_at ? 'acad-booking-ends-errors' : undefined"
            />
            <ul
              v-if="fieldErrors.ends_at"
              id="acad-booking-ends-errors"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in fieldErrors.ends_at" :key="message">{{ message }}</li>
            </ul>
          </div>
        </div>
        <p class="text-muted-foreground text-xs">{{ t('acad.bookings.form.intervalHint') }}</p>

        <div
          v-if="conflicts.length > 0"
          role="alert"
          class="text-destructive text-sm"
          data-slot="acad-booking-conflicts"
        >
          <p class="font-medium">{{ t('acad.bookings.conflict.title') }}</p>
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
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('acad.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="busy">{{ t('acad.bookings.form.submit') }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
