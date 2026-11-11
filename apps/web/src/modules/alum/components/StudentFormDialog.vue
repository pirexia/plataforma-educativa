<script setup lang="ts">
/**
 * `docs/modulos/REQ-ALUM/funcional.md §10`, `api.md §1` (`POST /students`, `PATCH /students/{id}`), `RN-ALUM-04`,
 * `RN-ALUM-10`, `RN-ALUM-27`. Alta (`alumno.crear`) y corrección (`alumno.actualizar`) de los datos personales del
 * alumno. Validación en cliente solo como ayuda (`INV-010`): los errores del servidor ya vienen traducidos y se pintan
 * junto al campo con `aria-describedby`/`aria-invalid`.
 *
 * - Alta: nombre, primer apellido y fecha de nacimiento obligatorios; unidad familiar **opcional** y solo con
 *   `familia.actualizar` (`canLinkFamily`). La `Idempotency-Key` la gestiona el llamador por intento de envío.
 * - Corrección: solo viajan las claves modificadas. El número de documento llega **enmascarado** y no se precarga:
 *   se informa el actual enmascarado y solo se envía si la persona escribe uno nuevo (con su tipo). `family_unit` no
 *   se admite (la pertenencia se gestiona en `fam_unit`, `RN-ALUM-04`).
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
import { problemDetail, problemFieldErrors } from '../composables/problem'
import type { Student, StudentCreate, StudentUpdate } from '../types'
import FamilyUnitPicker from './FamilyUnitPicker.vue'

const NONE = '__none__'
const DOCUMENT_TYPES = ['dni', 'nie', 'pasaporte']
const LOCALES = ['es-ES', 'en', 'de', 'fr']
const FIELDS = [
  'given_name',
  'family_name_1',
  'family_name_2',
  'birth_date',
  'document_type',
  'document_number',
  'locale',
] as const

const props = defineProps<{
  open: boolean
  mode: 'create' | 'edit'
  student?: Student | null
  canLinkFamily?: boolean
  save: (body: StudentCreate | StudentUpdate) => Promise<unknown>
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: []; reset: [] }>()

const t = useT()

const givenName = ref('')
const familyName1 = ref('')
const familyName2 = ref('')
const birthDate = ref('')
const documentType = ref(NONE)
const documentNumber = ref('')
const locale = ref(NONE)
const familyUnit = ref<string | null>(null)
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

watch(
  () => props.open,
  (open) => {
    if (!open) return

    const current = props.mode === 'edit' ? (props.student ?? null) : null

    givenName.value = current?.given_name ?? ''
    familyName1.value = current?.family_name_1 ?? ''
    familyName2.value = current?.family_name_2 ?? ''
    birthDate.value = current?.birth_date ?? ''
    documentType.value = current?.document_type ?? NONE
    // El número llega enmascarado: no se precarga (se escribe solo para cambiarlo).
    documentNumber.value = ''
    locale.value = current?.locale ?? NONE
    familyUnit.value = null
    fieldErrors.value = {}
    formError.value = null
    emit('reset')
  },
  { immediate: true },
)

function trimmed(value: string): string {
  return value.trim()
}

/** El cuerpo a enviar: el alta lleva todo lo informado; la corrección, solo lo que cambia (`RN-ALUM-10`). */
const body = computed<StudentCreate | StudentUpdate>(() => {
  if (props.mode === 'create') {
    const create: StudentCreate = {
      given_name: trimmed(givenName.value),
      family_name_1: trimmed(familyName1.value),
      birth_date: birthDate.value,
    }

    if (trimmed(familyName2.value) !== '') create.family_name_2 = trimmed(familyName2.value)

    if (documentType.value !== NONE && trimmed(documentNumber.value) !== '') {
      create.document_type = documentType.value
      create.document_number = trimmed(documentNumber.value)
    }

    if (locale.value !== NONE) create.locale = locale.value

    if (props.canLinkFamily && familyUnit.value !== null) create.family_unit = familyUnit.value

    return create
  }

  const current = props.student
  const update: StudentUpdate = {}

  if (!current) return update

  if (trimmed(givenName.value) !== current.given_name) update.given_name = trimmed(givenName.value)

  if (trimmed(familyName1.value) !== current.family_name_1) {
    update.family_name_1 = trimmed(familyName1.value)
  }

  if (trimmed(familyName2.value) !== (current.family_name_2 ?? '')) {
    update.family_name_2 = trimmed(familyName2.value) === '' ? null : trimmed(familyName2.value)
  }

  if (birthDate.value !== '' && birthDate.value !== current.birth_date) {
    update.birth_date = birthDate.value
  }

  if (trimmed(documentNumber.value) !== '') {
    update.document_type = documentType.value === NONE ? null : documentType.value
    update.document_number = trimmed(documentNumber.value)
  } else if (
    documentType.value !== (current.document_type ?? NONE) &&
    documentType.value !== NONE
  ) {
    update.document_type = documentType.value
  }

  if (locale.value !== NONE && locale.value !== current.locale) update.locale = locale.value

  return update
})

const hasChanges = computed(() => props.mode === 'create' || Object.keys(body.value).length > 0)

function errorsFor(field: string): string[] {
  return fieldErrors.value[field] ?? []
}

function describedBy(field: string): string | undefined {
  return errorsFor(field).length > 0 ? `alum-student-${field}-errors` : undefined
}

/** Errores de campos que el formulario no pinta (p. ej. `family_unit`, `Idempotency-Key`). */
const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => !(FIELDS as readonly string[]).includes(field))
    .flatMap(([, messages]) => messages),
)

async function submit(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null
  busy.value = true

  try {
    await props.save(body.value)
    emit('saved')
    emit('update:open', false)
  } catch (err) {
    fieldErrors.value = problemFieldErrors(err)
    formError.value =
      problemDetail(err) ??
      (Object.keys(fieldErrors.value).length === 0 ? t('alum.errors.unexpected') : null)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('alum.form.close')" data-slot="alum-student-form">
      <DialogHeader>
        <DialogTitle>{{
          mode === 'create' ? t('alum.form.createTitle') : t('alum.form.editTitle')
        }}</DialogTitle>
        <DialogDescription>{{
          mode === 'create' ? t('alum.form.createDescription') : t('alum.form.editDescription')
        }}</DialogDescription>
      </DialogHeader>

      <form class="flex max-h-[70vh] flex-col gap-4 overflow-y-auto" @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="alum-student-given_name">{{ t('alum.fields.givenName') }}</Label>
          <Input
            id="alum-student-given_name"
            v-model="givenName"
            required
            autocomplete="off"
            :aria-invalid="errorsFor('given_name').length > 0 ? 'true' : undefined"
            :aria-describedby="describedBy('given_name')"
          />
          <ul
            v-if="errorsFor('given_name').length > 0"
            id="alum-student-given_name-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in errorsFor('given_name')" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="alum-student-family_name_1">{{ t('alum.fields.familyName1') }}</Label>
          <Input
            id="alum-student-family_name_1"
            v-model="familyName1"
            required
            autocomplete="off"
            :aria-invalid="errorsFor('family_name_1').length > 0 ? 'true' : undefined"
            :aria-describedby="describedBy('family_name_1')"
          />
          <ul
            v-if="errorsFor('family_name_1').length > 0"
            id="alum-student-family_name_1-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in errorsFor('family_name_1')" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="alum-student-family_name_2">{{ t('alum.fields.familyName2') }}</Label>
          <Input
            id="alum-student-family_name_2"
            v-model="familyName2"
            autocomplete="off"
            :aria-invalid="errorsFor('family_name_2').length > 0 ? 'true' : undefined"
            :aria-describedby="describedBy('family_name_2')"
          />
          <ul
            v-if="errorsFor('family_name_2').length > 0"
            id="alum-student-family_name_2-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in errorsFor('family_name_2')" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="alum-student-birth_date">{{ t('alum.fields.birthDate') }}</Label>
          <Input
            id="alum-student-birth_date"
            v-model="birthDate"
            type="date"
            :required="mode === 'create'"
            :aria-invalid="errorsFor('birth_date').length > 0 ? 'true' : undefined"
            :aria-describedby="describedBy('birth_date')"
          />
          <ul
            v-if="errorsFor('birth_date').length > 0"
            id="alum-student-birth_date-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in errorsFor('birth_date')" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="alum-student-document_type">{{ t('alum.fields.documentType') }}</Label>
          <Select v-model="documentType">
            <SelectTrigger
              id="alum-student-document_type"
              :aria-invalid="errorsFor('document_type').length > 0 ? 'true' : undefined"
              :aria-describedby="describedBy('document_type')"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem :value="NONE">{{ t('alum.form.none') }}</SelectItem>
              <SelectItem v-for="type in DOCUMENT_TYPES" :key="type" :value="type">
                {{ t(`alum.enums.documentType.${type}`) }}
              </SelectItem>
            </SelectContent>
          </Select>
          <ul
            v-if="errorsFor('document_type').length > 0"
            id="alum-student-document_type-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in errorsFor('document_type')" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="alum-student-document_number">{{ t('alum.fields.documentNumber') }}</Label>
          <Input
            id="alum-student-document_number"
            v-model="documentNumber"
            autocomplete="off"
            :aria-invalid="errorsFor('document_number').length > 0 ? 'true' : undefined"
            :aria-describedby="
              [
                mode === 'edit' && student?.document_number
                  ? 'alum-student-document_number-hint'
                  : '',
                describedBy('document_number') ?? '',
              ]
                .filter((id) => id !== '')
                .join(' ') || undefined
            "
          />
          <p
            v-if="mode === 'edit' && student?.document_number"
            id="alum-student-document_number-hint"
            class="text-muted-foreground text-xs"
          >
            {{ t('alum.form.documentKeep', { masked: student.document_number }) }}
          </p>
          <ul
            v-if="errorsFor('document_number').length > 0"
            id="alum-student-document_number-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in errorsFor('document_number')" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="alum-student-locale">{{ t('alum.fields.locale') }}</Label>
          <Select v-model="locale">
            <SelectTrigger
              id="alum-student-locale"
              :aria-invalid="errorsFor('locale').length > 0 ? 'true' : undefined"
              :aria-describedby="describedBy('locale')"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem :value="NONE">{{ t('alum.form.localeDefault') }}</SelectItem>
              <SelectItem v-for="code in LOCALES" :key="code" :value="code">
                {{ t(`alum.enums.locale.${code}`) }}
              </SelectItem>
            </SelectContent>
          </Select>
          <ul
            v-if="errorsFor('locale').length > 0"
            id="alum-student-locale-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in errorsFor('locale')" :key="message">{{ message }}</li>
          </ul>
        </div>

        <FamilyUnitPicker
          v-if="mode === 'create' && canLinkFamily"
          v-model="familyUnit"
          id-base="alum-student-family"
        />

        <ul v-if="otherErrors.length > 0" role="alert" class="text-destructive text-sm">
          <li v-for="message in otherErrors" :key="message">{{ message }}</li>
        </ul>
        <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>

        <DialogFooter>
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('alum.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="busy || !hasChanges">
            {{ mode === 'create' ? t('alum.form.create') : t('alum.form.save') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
