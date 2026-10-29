<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §4.4`, `§10` (`REQ-FAM-UNIT-001`). Alta de un vínculo tutor ↔ alumno de la
 * unidad: tutor y alumno se eligen entre los de la propia ficha; relación, representación legal, nivel de acceso,
 * categorías restringidas y contacto de emergencia con los valores por defecto de la API (acceso `lectura`, sin
 * categorías, sin representación). Los errores del servidor ya vienen traducidos (`already_exists`, `self_link`,
 * `emergency_contact_taken`, `ADR-038 §6.3`).
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
import type { CreateGuardianLinkBody } from '../api'
import { problemDetail, problemFieldErrors } from '../composables/problem'
import { useLabels } from '../composables/useLabels'
import type {
  FamilyUnitStudent,
  Guardian,
  GuardianAccessLevel,
  GuardianRelationship,
} from '../types'

const RELATIONSHIPS: GuardianRelationship[] = [
  'padre',
  'madre',
  'tutor_legal',
  'abuelo',
  'apoderado',
  'otro',
]
const ACCESS_LEVELS: GuardianAccessLevel[] = ['lectura', 'lectura_escritura']
/** `OPEN-FAM-UNIT-07`: el vocabulario cerrado de categorías restringidas hoy es solo `salud`. */
const RESTRICTABLE = ['salud']

const props = defineProps<{
  open: boolean
  guardians: readonly Guardian[]
  students: readonly FamilyUnitStudent[]
  save: (body: CreateGuardianLinkBody) => Promise<unknown>
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()
const { relationshipLabel, accessLevelLabel, restrictedCategoryLabel } = useLabels()

const guardian = ref('')
const student = ref('')
const relationship = ref('')
const accessLevel = ref<string>('lectura')
const isLegalGuardian = ref(false)
const isEmergencyContact = ref(false)
const restricted = ref<string[]>([])

const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

watch(
  () => props.open,
  (open) => {
    if (open) {
      guardian.value = ''
      student.value = ''
      relationship.value = ''
      accessLevel.value = 'lectura'
      isLegalGuardian.value = false
      isEmergencyContact.value = false
      restricted.value = []
      fieldErrors.value = {}
      formError.value = null
    }
  },
  { immediate: true },
)

const KNOWN = ['guardian', 'student_person', 'relationship', 'access_level']

const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => !KNOWN.includes(field))
    .flatMap(([, messages]) => messages),
)

function toggleRestricted(code: string, checked: boolean): void {
  restricted.value = checked
    ? [...restricted.value, code]
    : restricted.value.filter((current) => current !== code)
}

async function submit(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null
  busy.value = true

  try {
    await props.save({
      guardian: guardian.value,
      student_person: student.value,
      relationship: relationship.value as GuardianRelationship,
      is_legal_guardian: isLegalGuardian.value,
      access_level: accessLevel.value as GuardianAccessLevel,
      restricted_categories: restricted.value,
      is_emergency_contact: isEmergencyContact.value,
    })
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

function describedBy(field: string): string | undefined {
  return fieldErrors.value[field] ? `fam-unit-link-${field}-errors` : undefined
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('famUnit.form.close')" data-slot="fam-unit-link-form">
      <DialogHeader>
        <DialogTitle>{{ t('famUnit.links.form.title') }}</DialogTitle>
        <DialogDescription>{{ t('famUnit.links.form.description') }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-link-guardian">{{ t('famUnit.links.form.guardian') }}</Label>
          <Select v-model="guardian" required>
            <SelectTrigger
              id="fam-unit-link-guardian"
              :aria-invalid="fieldErrors.guardian ? 'true' : undefined"
              :aria-describedby="describedBy('guardian')"
            >
              <SelectValue :placeholder="t('famUnit.form.choose')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="item in guardians" :key="item.public_id" :value="item.public_id">
                {{ item.display_name || t('famUnit.guardians.removedUser') }}
              </SelectItem>
            </SelectContent>
          </Select>
          <ul
            v-if="fieldErrors.guardian"
            id="fam-unit-link-guardian-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.guardian" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-link-student">{{ t('famUnit.links.form.student') }}</Label>
          <Select v-model="student" required>
            <SelectTrigger
              id="fam-unit-link-student"
              :aria-invalid="fieldErrors.student_person ? 'true' : undefined"
              :aria-describedby="describedBy('student_person')"
            >
              <SelectValue :placeholder="t('famUnit.form.choose')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="item in students"
                :key="item.public_id"
                :value="item.student_person"
              >
                {{ item.display_name }}
              </SelectItem>
            </SelectContent>
          </Select>
          <ul
            v-if="fieldErrors.student_person"
            id="fam-unit-link-student_person-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.student_person" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-link-relationship">{{ t('famUnit.fields.relationship') }}</Label>
          <Select v-model="relationship" required>
            <SelectTrigger
              id="fam-unit-link-relationship"
              :aria-invalid="fieldErrors.relationship ? 'true' : undefined"
              :aria-describedby="describedBy('relationship')"
            >
              <SelectValue :placeholder="t('famUnit.form.choose')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="code in RELATIONSHIPS" :key="code" :value="code">
                {{ relationshipLabel(code) }}
              </SelectItem>
            </SelectContent>
          </Select>
          <ul
            v-if="fieldErrors.relationship"
            id="fam-unit-link-relationship-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.relationship" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-link-access">{{ t('famUnit.fields.accessLevel') }}</Label>
          <Select v-model="accessLevel">
            <SelectTrigger
              id="fam-unit-link-access"
              :aria-invalid="fieldErrors.access_level ? 'true' : undefined"
              :aria-describedby="describedBy('access_level')"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="code in ACCESS_LEVELS" :key="code" :value="code">
                {{ accessLevelLabel(code) }}
              </SelectItem>
            </SelectContent>
          </Select>
          <ul
            v-if="fieldErrors.access_level"
            id="fam-unit-link-access_level-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.access_level" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1">
          <label class="flex min-h-11 items-center gap-2 text-sm">
            <input v-model="isLegalGuardian" type="checkbox" class="size-4" />
            {{ t('famUnit.fields.isLegalGuardian') }}
          </label>
          <p class="text-muted-foreground text-xs">{{ t('famUnit.links.form.legalHint') }}</p>
        </div>

        <label class="flex min-h-11 items-center gap-2 text-sm">
          <input v-model="isEmergencyContact" type="checkbox" class="size-4" />
          {{ t('famUnit.fields.isEmergencyContact') }}
        </label>

        <fieldset class="flex flex-col gap-1">
          <legend class="text-sm font-medium">
            {{ t('famUnit.fields.restrictedCategories') }}
          </legend>
          <label
            v-for="code in RESTRICTABLE"
            :key="code"
            class="flex min-h-11 items-center gap-2 text-sm"
          >
            <input
              type="checkbox"
              class="size-4"
              :checked="restricted.includes(code)"
              @change="toggleRestricted(code, ($event.target as HTMLInputElement).checked)"
            />
            {{ restrictedCategoryLabel(code) }}
          </label>
        </fieldset>

        <ul v-if="otherErrors.length > 0" role="alert" class="text-destructive text-sm">
          <li v-for="message in otherErrors" :key="message">{{ message }}</li>
        </ul>
        <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>

        <DialogFooter>
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('famUnit.form.cancel') }}
          </Button>
          <Button
            type="submit"
            :disabled="busy || guardian === '' || student === '' || relationship === ''"
          >
            {{ t('famUnit.links.form.submit') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
