<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §4.3`, `§7.4`, `OPEN-FAM-UNIT-01` (opción A). Añade un alumno a la unidad por
 * su **persona** (`POST /family-units/{id}/students`). Hasta que exista `REQ-ALUM` (`1.15`) no hay buscador de
 * alumnos: se escribe el identificador público de la persona (`D-42`). Los errores del servidor ya vienen traducidos
 * (`student_not_found`, `already_in_unit`).
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
import { problemDetail, problemFieldErrors } from '../composables/problem'

const props = defineProps<{ open: boolean; save: (studentPerson: string) => Promise<unknown> }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()

const person = ref('')
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

watch(
  () => props.open,
  (open) => {
    if (open) {
      person.value = ''
      fieldErrors.value = {}
      formError.value = null
    }
  },
  { immediate: true },
)

const personErrors = computed(() => fieldErrors.value.student_person ?? [])
const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => field !== 'student_person')
    .flatMap(([, messages]) => messages),
)

async function submit(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null
  busy.value = true

  try {
    await props.save(person.value.trim())
    emit('saved')
    emit('update:open', false)
  } catch (err) {
    fieldErrors.value = problemFieldErrors(err)
    formError.value =
      problemDetail(err) ??
      (Object.keys(fieldErrors.value).length === 0 ? t('famUnit.errors.unexpected') : null)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('famUnit.form.close')" data-slot="fam-unit-student-form">
      <DialogHeader>
        <DialogTitle>{{ t('famUnit.students.form.title') }}</DialogTitle>
        <DialogDescription>{{ t('famUnit.students.form.description') }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-student-person">{{ t('famUnit.students.form.person') }}</Label>
          <Input
            id="fam-unit-student-person"
            v-model="person"
            required
            :aria-invalid="personErrors.length > 0 ? 'true' : undefined"
            aria-describedby="fam-unit-student-person-hint"
          />
          <p id="fam-unit-student-person-hint" class="text-muted-foreground text-xs">
            {{ t('famUnit.students.form.personHint') }}
          </p>
        </div>

        <ul v-if="personErrors.length > 0" role="alert" class="text-destructive text-sm">
          <li v-for="message in personErrors" :key="message">{{ message }}</li>
        </ul>
        <ul v-if="otherErrors.length > 0" role="alert" class="text-destructive text-sm">
          <li v-for="message in otherErrors" :key="message">{{ message }}</li>
        </ul>
        <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>

        <DialogFooter>
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('famUnit.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="busy">{{ t('famUnit.students.form.submit') }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
