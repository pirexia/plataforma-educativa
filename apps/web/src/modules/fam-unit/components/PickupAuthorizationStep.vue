<script setup lang="ts">
/**
 * `funcional.md §23.4.3` punto 2 (`OPEN-FAM-UNIT-35`, `-55` A, `D-60`, `RN-FAM-UNIT-85`). Autorización: alumno
 * (preseleccionado el del panel), relación, tipo (`permanente`/`puntual`), vigencia (`valid_until` solo en `permanente`;
 * en `puntual` la fecha es la misma y no se pide), motivo (obligatorio y visible solo en `puntual`), canal (`telefono`
 * solo con `puntual`), versión del texto firmado (la publicada por defecto), fecha y hora de la firma o la llamada y el
 * tutor que autoriza: el principal de la unidad, de solo lectura (`RN-FAM-UNIT-85`: no admite a otro). Los errores del
 * servidor van junto al campo (UI-6); un `422 pickup.text_version_invalid` queda junto al selector de versión.
 */
import { computed, ref, watch } from 'vue'
import { useT } from '@/i18n'
import { Alert, AlertDescription } from '@/components/ui/alert'
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
import { Textarea } from '@/components/ui/textarea'
import { createPickupAuthorization } from '../api'
import { problemDetail, problemEntries } from '../composables/problem'
import { useLabels } from '../composables/useLabels'
import type { FamilyUnitStudent, PickupAuthorization, PickupPerson, TermsVersion } from '../types'

const RELATIONSHIPS = ['abuelo', 'personal_apoyo', 'otra_familia', 'otro']

/** Campo en el que se pinta cada error de dominio, aunque el servidor lo cuelgue de otra clave. */
const CODE_FIELD: Record<string, string> = {
  'pickup.text_version_invalid': 'terms_version',
  'pickup.text_not_published': 'terms_version',
  'pickup.text_version_outdated': 'terms_version',
  'pickup.channel_requires_puntual': 'channel',
}

const props = defineProps<{
  students: FamilyUnitStudent[]
  initialStudent: string
  principalGuardian: string | null
  principalName: string
  canUpdate: boolean
  terms: TermsVersion[]
  termsLoading: boolean
}>()
const emit = defineEmits<{ 'reload-terms': [] }>()

const t = useT()
const labels = useLabels()

function today(): string {
  const now = new Date()

  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

function nowLocal(): string {
  const now = new Date()

  return `${today()}T${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`
}

const student = ref(props.initialStudent)
const relationship = ref('abuelo')
const kind = ref('permanente')
const validFrom = ref(today())
const validUntil = ref('')
const reason = ref('')
const channel = ref('presencial')
const termsVersion = ref('')
const authorizedAt = ref(nowLocal())
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

const published = computed(() => props.terms.find((item) => item.status === 'publicada') ?? null)

watch(
  () => props.terms,
  () => {
    if (!props.terms.some((item) => item.public_id === termsVersion.value)) {
      termsVersion.value = published.value?.public_id ?? ''
    }
  },
  { immediate: true },
)

watch(kind, (value) => {
  if (value === 'permanente' && channel.value === 'telefono') {
    channel.value = 'presencial'
  }
})

const isOneOff = computed(() => kind.value === 'puntual')

function errorsOf(field: string): string[] {
  return fieldErrors.value[field] ?? []
}

function describedBy(field: string, extra?: string): string | undefined {
  const ids = [
    extra,
    errorsOf(field).length ? `fam-unit-pickup-auth-${field}-errors` : undefined,
  ].filter(Boolean)

  return ids.length ? ids.join(' ') : undefined
}

function versionLabel(version: TermsVersion): string {
  return t('famUnit.pickup.authorization.termsVersionLabel', {
    number: version.version_number,
    state: labels.termsStatusLabel(version.status),
  })
}

function toIso(local: string): string | undefined {
  const date = new Date(local)

  return Number.isNaN(date.getTime()) ? undefined : date.toISOString()
}

/** Crea la autorización de `person`; devuelve `null` si el servidor la rechaza (el paso no avanza). */
async function submit(person: PickupPerson): Promise<PickupAuthorization | null> {
  fieldErrors.value = {}
  formError.value = null

  const missing: Record<string, string[]> = {}

  if (isOneOff.value && reason.value.trim() === '')
    missing.reason = [t('famUnit.pickup.authorization.reasonRequired')]
  if (termsVersion.value === '')
    missing.terms_version = [t('famUnit.pickup.authorization.noPublishedText')]
  if (props.principalGuardian === null) return null

  if (Object.keys(missing).length > 0) {
    fieldErrors.value = missing

    return null
  }

  busy.value = true

  try {
    return await createPickupAuthorization({
      student_person: student.value,
      person: person.person,
      relationship: relationship.value,
      kind: kind.value,
      valid_from: validFrom.value,
      valid_until: isOneOff.value || validUntil.value === '' ? null : validUntil.value,
      reason: isOneOff.value ? reason.value.trim() : null,
      authorized_by: props.principalGuardian,
      terms_version: termsVersion.value,
      channel: channel.value,
      authorized_at: toIso(authorizedAt.value),
    })
  } catch (err) {
    const next: Record<string, string[]> = {}

    for (const entry of problemEntries(err)) {
      const field = (entry.code && CODE_FIELD[entry.code]) || entry.field

      next[field] = [...(next[field] ?? []), entry.message]
    }

    fieldErrors.value = next
    formError.value =
      Object.keys(next).length === 0 ? (problemDetail(err) ?? t('famUnit.errors.unexpected')) : null

    return null
  } finally {
    busy.value = false
  }
}

const KNOWN_FIELDS = [
  'student_person',
  'relationship',
  'kind',
  'valid_from',
  'valid_until',
  'reason',
  'channel',
  'terms_version',
  'authorized_at',
]

/** Errores de campos que el formulario no pinta (p. ej. `person`, `authorized_by`): se muestran juntos, sin perderlos. */
const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => !KNOWN_FIELDS.includes(field))
    .flatMap(([, messages]) => messages),
)

defineExpose({ submit })
</script>

<template>
  <div class="flex flex-col gap-4">
    <Alert v-if="!canUpdate" role="status">
      <AlertDescription>{{ t('famUnit.pickup.photo.noUpdatePermission') }}</AlertDescription>
    </Alert>

    <div class="grid gap-4 sm:grid-cols-2">
      <div class="flex flex-col gap-1.5">
        <Label for="fam-unit-pickup-auth-student">{{
          t('famUnit.pickup.authorization.student')
        }}</Label>
        <Select v-model="student">
          <SelectTrigger id="fam-unit-pickup-auth-student">
            <SelectValue :placeholder="t('famUnit.form.choose')" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="item in students" :key="item.public_id" :value="item.student_person">
              {{ item.display_name }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="fam-unit-pickup-auth-relationship">{{
          t('famUnit.pickup.authorization.relationship')
        }}</Label>
        <Select v-model="relationship">
          <SelectTrigger id="fam-unit-pickup-auth-relationship">
            <SelectValue :placeholder="t('famUnit.form.choose')" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="code in RELATIONSHIPS" :key="code" :value="code">
              {{ labels.pickupLabel('relationship', code) }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="fam-unit-pickup-auth-kind">{{ t('famUnit.pickup.authorization.kind') }}</Label>
        <Select v-model="kind">
          <SelectTrigger id="fam-unit-pickup-auth-kind">
            <SelectValue :placeholder="t('famUnit.form.choose')" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="permanente">{{
              labels.pickupLabel('kind', 'permanente')
            }}</SelectItem>
            <SelectItem value="puntual">{{ labels.pickupLabel('kind', 'puntual') }}</SelectItem>
          </SelectContent>
        </Select>
        <ul
          v-if="errorsOf('kind').length"
          id="fam-unit-pickup-auth-kind-errors"
          role="alert"
          class="text-destructive text-xs"
        >
          <li v-for="m in errorsOf('kind')" :key="m">{{ m }}</li>
        </ul>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="fam-unit-pickup-auth-channel">{{
          t('famUnit.pickup.authorization.channel')
        }}</Label>
        <Select v-model="channel">
          <SelectTrigger
            id="fam-unit-pickup-auth-channel"
            :aria-invalid="errorsOf('channel').length ? 'true' : undefined"
            :aria-describedby="
              describedBy('channel', !isOneOff ? 'fam-unit-pickup-auth-channel-hint' : undefined)
            "
          >
            <SelectValue :placeholder="t('famUnit.form.choose')" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="presencial">{{
              labels.pickupLabel('channel', 'presencial')
            }}</SelectItem>
            <SelectItem value="telefono" :disabled="!isOneOff">
              {{ labels.pickupLabel('channel', 'telefono') }}
            </SelectItem>
          </SelectContent>
        </Select>
        <p
          v-if="!isOneOff"
          id="fam-unit-pickup-auth-channel-hint"
          class="text-muted-foreground text-xs"
        >
          {{ t('famUnit.pickup.authorization.telefonoOnlyPuntual') }}
        </p>
        <ul
          v-if="errorsOf('channel').length"
          id="fam-unit-pickup-auth-channel-errors"
          role="alert"
          class="text-destructive text-xs"
        >
          <li v-for="m in errorsOf('channel')" :key="m">{{ m }}</li>
        </ul>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="fam-unit-pickup-auth-from">{{
          t('famUnit.pickup.authorization.validFrom')
        }}</Label>
        <Input
          id="fam-unit-pickup-auth-from"
          v-model="validFrom"
          type="date"
          required
          :aria-invalid="errorsOf('valid_from').length ? 'true' : undefined"
          :aria-describedby="describedBy('valid_from')"
        />
        <ul
          v-if="errorsOf('valid_from').length"
          id="fam-unit-pickup-auth-valid_from-errors"
          role="alert"
          class="text-destructive text-xs"
        >
          <li v-for="m in errorsOf('valid_from')" :key="m">{{ m }}</li>
        </ul>
      </div>

      <div v-if="!isOneOff" class="flex flex-col gap-1.5">
        <Label for="fam-unit-pickup-auth-until">{{
          t('famUnit.pickup.authorization.validUntil')
        }}</Label>
        <Input
          id="fam-unit-pickup-auth-until"
          v-model="validUntil"
          type="date"
          :aria-invalid="errorsOf('valid_until').length ? 'true' : undefined"
          :aria-describedby="describedBy('valid_until')"
        />
        <ul
          v-if="errorsOf('valid_until').length"
          id="fam-unit-pickup-auth-valid_until-errors"
          role="alert"
          class="text-destructive text-xs"
        >
          <li v-for="m in errorsOf('valid_until')" :key="m">{{ m }}</li>
        </ul>
      </div>
    </div>

    <div v-if="isOneOff" class="flex flex-col gap-1.5">
      <Label for="fam-unit-pickup-auth-reason">{{
        t('famUnit.pickup.authorization.reason')
      }}</Label>
      <Textarea
        id="fam-unit-pickup-auth-reason"
        v-model="reason"
        required
        maxlength="500"
        :aria-invalid="errorsOf('reason').length ? 'true' : undefined"
        :aria-describedby="describedBy('reason')"
      />
      <ul
        v-if="errorsOf('reason').length"
        id="fam-unit-pickup-auth-reason-errors"
        role="alert"
        class="text-destructive text-xs"
      >
        <li v-for="m in errorsOf('reason')" :key="m">{{ m }}</li>
      </ul>
    </div>

    <div class="flex flex-col gap-1.5">
      <Label for="fam-unit-pickup-auth-terms">{{
        t('famUnit.pickup.authorization.termsVersion')
      }}</Label>
      <Select v-model="termsVersion">
        <SelectTrigger
          id="fam-unit-pickup-auth-terms"
          :aria-invalid="errorsOf('terms_version').length ? 'true' : undefined"
          :aria-describedby="describedBy('terms_version')"
        >
          <SelectValue :placeholder="t('famUnit.form.choose')" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="item in terms" :key="item.public_id" :value="item.public_id">
            {{ versionLabel(item) }}
          </SelectItem>
        </SelectContent>
      </Select>
      <p v-if="!termsLoading && published === null" role="status" class="text-sm">
        {{ t('famUnit.pickup.authorization.noPublishedText') }}
      </p>
      <ul
        v-if="errorsOf('terms_version').length"
        id="fam-unit-pickup-auth-terms_version-errors"
        role="alert"
        class="text-destructive text-xs"
      >
        <li v-for="m in errorsOf('terms_version')" :key="m">{{ m }}</li>
      </ul>
      <div v-if="errorsOf('terms_version').length">
        <Button type="button" variant="outline" size="sm" @click="emit('reload-terms')">
          {{ t('famUnit.pickup.states.retry') }}
        </Button>
      </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
      <div class="flex flex-col gap-1.5">
        <Label for="fam-unit-pickup-auth-at">{{
          t('famUnit.pickup.authorization.authorizedAt')
        }}</Label>
        <Input
          id="fam-unit-pickup-auth-at"
          v-model="authorizedAt"
          type="datetime-local"
          :aria-invalid="errorsOf('authorized_at').length ? 'true' : undefined"
          :aria-describedby="describedBy('authorized_at')"
        />
        <ul
          v-if="errorsOf('authorized_at').length"
          id="fam-unit-pickup-auth-authorized_at-errors"
          role="alert"
          class="text-destructive text-xs"
        >
          <li v-for="m in errorsOf('authorized_at')" :key="m">{{ m }}</li>
        </ul>
      </div>
      <div class="flex flex-col gap-1.5">
        <Label for="fam-unit-pickup-auth-by">{{
          t('famUnit.pickup.authorization.authorizedBy')
        }}</Label>
        <Input id="fam-unit-pickup-auth-by" :model-value="principalName" readonly />
      </div>
    </div>

    <ul v-if="otherErrors.length" role="alert" class="text-destructive text-sm">
      <li v-for="m in otherErrors" :key="m">{{ m }}</li>
    </ul>
    <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>
    <span class="sr-only" aria-live="polite">{{ busy ? t('shell.states.loading') : '' }}</span>
  </div>
</template>
