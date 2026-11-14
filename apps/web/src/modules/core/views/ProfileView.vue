<script setup lang="ts">
/**
 * `/cuenta/perfil` (`docs/modulos/REQ-CORE/funcional.md §14.10c`, `RN-CORE-88`,
 * `RN-CORE-89`, `OPEN-CORE-31` = B, 1.9e). Perfil propio de autoservicio: ruta
 * con `meta.permissions: []` (séptima de la lista cerrada de `RN-CORE-24`,
 * §14.3.1) porque `PATCH /me` se autoriza por identidad, nunca por permiso.
 *
 * - Muestra en solo lectura el nombre completo y el correo de acceso (este no se
 *   cambia desde aquí, §4.9) y edita **solo** el correo y el teléfono de
 *   contacto. El idioma **no** se edita: ya lo cambia el selector del menú de
 *   usuario (§12.3.6), y dos controles para el mismo dato divergirían.
 * - Los datos salen del estado de sesión ya cargado (`RN-CORE-26`): la pantalla
 *   **no** pide `GET /me`.
 * - `PATCH /me` envía solo las claves modificadas de `person` (`RN-CORE-65`;
 *   vaciar un campo envía `null`). Con `200`, el estado de sesión se sustituye
 *   por la respuesta del propio `PATCH`, sin segunda petición (§12.3.4). La
 *   interfaz **nunca** envía `email`, `status`, `roles` ni ningún otro campo.
 */
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { i18n, useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { useSession } from '@/session/useSession'
import {
  problemDetail,
  problemFieldErrors,
  problemRetryAfter,
  problemStatus,
} from '../composables/problem'
import SettingsField from '../components/SettingsField.vue'

type Field = 'contact_email' | 'contact_phone'

const FIELDS: readonly Field[] = ['contact_email', 'contact_phone']

const t = useT()
const { user, updateSessionProfile } = useSession()

const fullName = computed(() => {
  const person = user.value?.person

  return person
    ? [person.given_name, person.family_name_1, person.family_name_2].filter(Boolean).join(' ')
    : ''
})

const values = reactive<Record<Field, string>>({ contact_email: '', contact_phone: '' })
let initial: Record<Field, string> = { ...values }

function reset(): void {
  const person = user.value?.person

  values.contact_email = person?.contact_email ?? ''
  values.contact_phone = person?.contact_phone ?? ''
  initial = { ...values }
}

reset()

const fieldErrors = ref<Partial<Record<Field, string[]>>>({})
const generalError = ref<string | null>(null)
const statusMessage = ref<string | null>(null)
const saving = ref(false)

const changed = computed(() => FIELDS.filter((name) => values[name].trim() !== initial[name]))

// El mensaje de estado se redacta en el idioma activo: se descarta al cambiarlo.
watch(
  () => i18n.global.locale.value,
  () => {
    statusMessage.value = null
  },
)

function formField(serverField: string): Field | null {
  const name = serverField.startsWith('person.') ? serverField.slice('person.'.length) : serverField

  return (FIELDS as readonly string[]).includes(name) ? (name as Field) : null
}

async function fail(err: unknown): Promise<void> {
  const status = problemStatus(err)

  if (status === 422) {
    const next: Partial<Record<Field, string[]>> = {}
    const general: string[] = []

    for (const [serverField, messages] of Object.entries(problemFieldErrors(err))) {
      const field = formField(serverField)

      if (field) {
        next[field] = [...(next[field] ?? []), ...messages]
      } else {
        general.push(...messages)
      }
    }

    fieldErrors.value = next
    generalError.value =
      general.length > 0
        ? general.join(' ')
        : Object.keys(next).length === 0
          ? t('core.profile.errors.unexpected')
          : null

    await nextTick()

    const first = FIELDS.find((name) => (next[name]?.length ?? 0) > 0)

    document.getElementById(first ? `profile-${first}` : 'profile-summary')?.focus()

    return
  }

  if (status === 429) {
    const seconds = problemRetryAfter(err)

    generalError.value =
      seconds !== null
        ? t('core.profile.errors.tooManyRequestsWithSeconds', { seconds })
        : t('core.profile.errors.tooManyRequests')

    return
  }

  generalError.value = problemDetail(err) ?? t('core.profile.errors.unexpected')
}

async function submit(): Promise<void> {
  fieldErrors.value = {}
  generalError.value = null
  statusMessage.value = null

  if (changed.value.length === 0) {
    return
  }

  // RN-CORE-89: solo las claves modificadas de `person`; vaciar un campo envía `null`.
  const person: Partial<Record<Field, string | null>> = {}

  for (const name of changed.value) {
    const value = values[name].trim()

    person[name] = value === '' ? null : value
  }

  saving.value = true

  try {
    await updateSessionProfile(person)
    reset()
    statusMessage.value = t('core.profile.saved')
  } catch (err) {
    await fail(err)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-2xl flex-col gap-4 px-4 py-6">
    <div>
      <h1 class="text-lg font-semibold">{{ t('core.profile.title') }}</h1>
      <p class="text-muted-foreground text-sm">{{ t('core.profile.intro') }}</p>
    </div>

    <dl v-if="user" class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-[12rem_1fr]">
      <dt class="text-muted-foreground">{{ t('core.profile.fullName') }}</dt>
      <dd>{{ fullName }}</dd>
      <dt class="text-muted-foreground">{{ t('core.profile.accessEmail') }}</dt>
      <dd>
        {{ user.email }}
        <span class="text-muted-foreground mt-1 block text-xs">
          {{ t('core.profile.accessEmailHint') }}
        </span>
      </dd>
    </dl>

    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <div
        v-if="generalError"
        id="profile-summary"
        role="alert"
        tabindex="-1"
        class="border-destructive text-destructive rounded-lg border px-3 py-2 text-sm"
      >
        {{ generalError }}
      </div>

      <SettingsField
        id="profile-contact_email"
        v-model="values.contact_email"
        :label="t('core.profile.contactEmail')"
        type="email"
        :errors="fieldErrors.contact_email"
      />
      <SettingsField
        id="profile-contact_phone"
        v-model="values.contact_phone"
        :label="t('core.profile.contactPhone')"
        type="tel"
        :maxlength="32"
        :errors="fieldErrors.contact_phone"
      />

      <div class="flex flex-wrap items-center gap-3">
        <Button type="submit" :disabled="saving || changed.length === 0">
          {{ saving ? t('core.profile.saving') : t('core.profile.save') }}
        </Button>
        <p v-if="statusMessage" role="status" class="text-sm">{{ statusMessage }}</p>
      </div>
    </form>
  </div>
</template>
