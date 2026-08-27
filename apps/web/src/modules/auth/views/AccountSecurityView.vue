<script setup lang="ts">
/**
 * `/cuenta/seguridad` (funcional.md §C.11, api.md §C.4). Con sesión, sin
 * `AppLayout` — misma categoría que `/cuenta/contrasena` y
 * `/cuenta/sesiones`. Sin sesión, redirige a `/entrar`.
 *
 * Autoservicio de MFA: estado, alta de TOTP con QR y clave en texto,
 * códigos de respaldo, desactivación. Si la sesión está **restringida**
 * (`mfa.enforced`, funcional.md §C.4.9) redirige al muro
 * (`/cuenta/seguridad/obligatorio`), que es la misma alta sin navegación:
 * no tiene sentido pintar aquí un panel completo con enlaces de salida
 * para alguien que no puede usarlos.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { getMfaStatus, regenerateMfaRecoveryCodes, removeMfaFactor } from '../api'
import {
  apiErrorDetail,
  apiErrorStatus,
  fieldErrors,
  retryAfterSeconds,
} from '../composables/formErrors'
import MfaEmailEnrollment from '../components/MfaEmailEnrollment.vue'
import MfaTotpEnrollment from '../components/MfaTotpEnrollment.vue'
import RecoveryCodesReveal from '../components/RecoveryCodesReveal.vue'
import type { MfaStatus } from '../types'

const { t, locale } = useI18n()
const router = useRouter()

const checkingSession = ref(true)
const loading = ref(true)
const status = ref<MfaStatus | null>(null)
const errorMessage = ref<string | null>(null)
const statusMessage = ref<string | null>(null)

const dateFormatter = computed(
  () => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }),
)

function formatDate(value: string): string {
  return dateFormatter.value.format(new Date(value))
}

// funcional.md §D.9: el bloque de alta por correo solo aparece si el
// tenant lo admite (`allowed_methods`) y el usuario no tiene ya un factor
// `email` confirmado; el mismo criterio, por método, decide TOTP — con
// dos métodos posibles, "ya tengo un factor" deja de ser "tengo alguno" y
// pasa a ser "tengo este" (funcional.md §D.1.1 punto 6, coexistencia).
const hasTotpFactor = computed(
  () => status.value?.factors.some((factor) => factor.method === 'totp') ?? false,
)
const hasEmailFactor = computed(
  () => status.value?.factors.some((factor) => factor.method === 'email') ?? false,
)
const showTotpEnrollment = computed(() => !hasTotpFactor.value)
const showEmailEnrollment = computed(
  () => (status.value?.allowed_methods.includes('email') ?? false) && !hasEmailFactor.value,
)

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = null

  try {
    const result = await getMfaStatus()

    if (result.mfa.enforced) {
      await router.push({ name: 'mfa-enrollment-wall' })
      return
    }

    status.value = result
  } catch (err) {
    if (apiErrorStatus(err) === 401) {
      await router.push({ name: 'login' })
      return
    }
    errorMessage.value = t('auth.common.unexpectedError')
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  checkingSession.value = true
  await load()
  checkingSession.value = false
})

async function onEnrolled(): Promise<void> {
  statusMessage.value = t('auth.mfa.security.enrollSuccess')
  await load()
}

// -- Desactivar un factor -------------------------------------------------

const removingId = ref<string | null>(null)
const removePassword = ref('')
const removeErrors = ref<string[]>([])
const removeConflict = ref<string | null>(null)
const removeSubmitting = ref(false)

function askRemove(publicId: string): void {
  errorMessage.value = null
  statusMessage.value = null
  removingId.value = publicId
  removePassword.value = ''
  removeErrors.value = []
  removeConflict.value = null
}

function cancelRemove(): void {
  removingId.value = null
  removePassword.value = ''
  removeErrors.value = []
  removeConflict.value = null
}

async function confirmRemove(): Promise<void> {
  if (!removingId.value) {
    return
  }

  removeSubmitting.value = true
  removeErrors.value = []
  removeConflict.value = null

  try {
    await removeMfaFactor(removingId.value, removePassword.value)
    statusMessage.value = t('auth.mfa.security.factorRemoved')
    cancelRemove()
    await load()
  } catch (err) {
    const apiStatus = apiErrorStatus(err)

    if (apiStatus === 401) {
      await router.push({ name: 'login' })
      return
    }

    if (apiStatus === 409) {
      removeConflict.value = apiErrorDetail(err) ?? t('auth.mfa.security.factorRequiredGeneric')
    } else if (apiStatus === 422) {
      removeErrors.value = fieldErrors(err, 'current_password')
      if (removeErrors.value.length === 0) {
        removeErrors.value = [t('auth.mfa.security.wrongPassword')]
      }
    } else {
      removeErrors.value = [t('auth.common.unexpectedError')]
    }
  } finally {
    removeSubmitting.value = false
  }
}

// -- Regenerar códigos de respaldo ----------------------------------------

const regeneratingOpen = ref(false)
const regeneratePassword = ref('')
const regenerateErrors = ref<string[]>([])
const regenerateSubmitting = ref(false)
const newRecoveryCodes = ref<string[] | null>(null)

function askRegenerate(): void {
  errorMessage.value = null
  statusMessage.value = null
  regeneratingOpen.value = true
  regeneratePassword.value = ''
  regenerateErrors.value = []
}

function cancelRegenerate(): void {
  regeneratingOpen.value = false
  regeneratePassword.value = ''
  regenerateErrors.value = []
}

async function confirmRegenerate(): Promise<void> {
  regenerateSubmitting.value = true
  regenerateErrors.value = []

  try {
    const result = await regenerateMfaRecoveryCodes(regeneratePassword.value)
    newRecoveryCodes.value = result.recovery_codes
    regeneratingOpen.value = false
  } catch (err) {
    const apiStatus = apiErrorStatus(err)

    if (apiStatus === 401) {
      await router.push({ name: 'login' })
      return
    }

    if (apiStatus === 429) {
      const seconds = retryAfterSeconds(err)
      regenerateErrors.value = [
        seconds !== null
          ? t('auth.common.tooManyRequestsWithSeconds', { seconds })
          : t('auth.common.tooManyRequests'),
      ]
    } else {
      regenerateErrors.value = fieldErrors(err, 'current_password')
      if (regenerateErrors.value.length === 0) {
        regenerateErrors.value = [t('auth.mfa.security.wrongPassword')]
      }
    }
  } finally {
    regenerateSubmitting.value = false
  }
}

async function onRecoveryCodesAcknowledged(): Promise<void> {
  newRecoveryCodes.value = null
  statusMessage.value = t('auth.mfa.security.recoveryCodesRegenerated')
  await load()
}

async function logoutFromHere(): Promise<void> {
  const { logout } = await import('../api')
  await logout().catch(() => {})
  await router.push({ name: 'login' })
}
</script>

<template>
  <div v-if="!checkingSession" class="mx-auto flex min-h-svh max-w-2xl flex-col px-4 py-10">
    <div class="border-border bg-background w-full rounded-xl border p-6 shadow-sm">
      <h1 class="mb-1 text-lg font-semibold">{{ t('auth.mfa.security.title') }}</h1>
      <p class="text-muted-foreground mb-4 text-sm">{{ t('auth.mfa.security.intro') }}</p>

      <p
        v-if="statusMessage"
        role="status"
        class="border-border bg-muted mb-4 rounded-lg border px-3 py-2 text-sm"
      >
        {{ statusMessage }}
      </p>
      <p v-if="errorMessage" role="alert" class="text-destructive mb-4 text-sm">
        {{ errorMessage }}
      </p>

      <p v-if="loading" class="text-muted-foreground text-sm">
        {{ t('auth.mfa.security.loading') }}
      </p>

      <template v-else-if="status">
        <p
          v-if="status.mfa.obligated && !status.mfa.enrolled && status.mfa.grace_deadline_at"
          role="alert"
          class="border-border bg-muted mb-4 rounded-lg border px-3 py-2 text-sm"
        >
          {{ t('auth.mfa.security.graceBanner', { days: status.mfa.days_remaining ?? 0 }) }}
        </p>

        <!-- funcional.md §D.9: aviso de excepción viva con su caducidad,
             sostenido por `mfa.exempt_until` sin enviar ningún correo
             (§D.4.10). -->
        <p
          v-if="status.mfa.exempt_until"
          role="status"
          class="border-border bg-muted mb-4 rounded-lg border px-3 py-2 text-sm"
        >
          {{ t('auth.mfa.security.exemptBanner', { date: formatDate(status.mfa.exempt_until) }) }}
        </p>

        <section class="mb-6">
          <h2 class="mb-2 text-sm font-semibold">{{ t('auth.mfa.security.factorsTitle') }}</h2>

          <p v-if="status.factors.length === 0" class="text-muted-foreground mb-4 text-sm">
            {{ t('auth.mfa.security.noFactors') }}
          </p>

          <ul v-else class="mb-4 flex flex-col gap-2">
            <li
              v-for="factor in status.factors"
              :key="factor.public_id"
              class="border-border flex flex-col gap-2 rounded-lg border px-3 py-2 text-sm"
            >
              <div class="flex items-center justify-between gap-2">
                <div>
                  <span class="font-medium">{{ t(`auth.mfa.method.${factor.method}`) }}</span>
                  <span v-if="factor.destination_masked" class="text-muted-foreground">
                    · {{ factor.destination_masked }}</span
                  >
                  <span v-if="factor.is_preferred" class="text-muted-foreground">
                    · {{ t('auth.mfa.security.preferred') }}</span
                  >
                  <div class="text-muted-foreground text-xs">
                    {{
                      t('auth.mfa.security.confirmedAt', { date: formatDate(factor.confirmed_at) })
                    }}
                  </div>
                </div>
                <Button
                  v-if="removingId !== factor.public_id"
                  type="button"
                  variant="outline"
                  size="sm"
                  @click="askRemove(factor.public_id)"
                >
                  {{ t('auth.mfa.security.deactivate') }}
                </Button>
              </div>

              <form
                v-if="removingId === factor.public_id"
                class="flex flex-col gap-2"
                novalidate
                @submit.prevent="confirmRemove"
              >
                <p role="alert" class="text-sm">{{ t('auth.mfa.security.confirmDeactivate') }}</p>
                <p v-if="removeConflict" role="alert" class="text-destructive text-sm">
                  {{ removeConflict }}
                </p>
                <div class="flex flex-col gap-1.5">
                  <Label :for="`remove-password-${factor.public_id}`">{{
                    t('auth.fields.currentPassword')
                  }}</Label>
                  <Input
                    :id="`remove-password-${factor.public_id}`"
                    v-model="removePassword"
                    type="password"
                    autocomplete="current-password"
                    required
                  />
                  <p
                    v-for="message in removeErrors"
                    :key="message"
                    role="alert"
                    class="text-destructive text-sm"
                  >
                    {{ message }}
                  </p>
                </div>
                <div class="flex gap-2">
                  <Button
                    type="submit"
                    variant="destructive"
                    size="sm"
                    :disabled="removeSubmitting"
                  >
                    {{
                      removeSubmitting
                        ? t('auth.mfa.security.deactivating')
                        : t('auth.mfa.security.confirmYes')
                    }}
                  </Button>
                  <Button type="button" variant="outline" size="sm" @click="cancelRemove">
                    {{ t('auth.mfa.security.confirmCancel') }}
                  </Button>
                </div>
              </form>
            </li>
          </ul>

          <div v-if="showTotpEnrollment || showEmailEnrollment" class="flex flex-col gap-6">
            <MfaTotpEnrollment v-if="showTotpEnrollment" @enrolled="onEnrolled" />
            <MfaEmailEnrollment v-if="showEmailEnrollment" @enrolled="onEnrolled" />
          </div>
        </section>

        <section v-if="status.factors.length > 0">
          <h2 class="mb-2 text-sm font-semibold">{{ t('auth.mfa.recoveryCodes.title') }}</h2>

          <RecoveryCodesReveal
            v-if="newRecoveryCodes"
            :codes="newRecoveryCodes"
            @acknowledged="onRecoveryCodesAcknowledged"
          />

          <template v-else>
            <p class="text-muted-foreground mb-3 text-sm">
              {{
                t('auth.mfa.recoveryCodes.remaining', { count: status.unused_recovery_codes_count })
              }}
            </p>

            <Button v-if="!regeneratingOpen" type="button" variant="outline" @click="askRegenerate">
              {{ t('auth.mfa.recoveryCodes.regenerate') }}
            </Button>

            <form v-else class="flex flex-col gap-2" novalidate @submit.prevent="confirmRegenerate">
              <p role="alert" class="text-sm">
                {{ t('auth.mfa.recoveryCodes.confirmRegenerate') }}
              </p>
              <div class="flex flex-col gap-1.5">
                <Label for="regenerate-password">{{ t('auth.fields.currentPassword') }}</Label>
                <Input
                  id="regenerate-password"
                  v-model="regeneratePassword"
                  type="password"
                  autocomplete="current-password"
                  required
                />
                <p
                  v-for="message in regenerateErrors"
                  :key="message"
                  role="alert"
                  class="text-destructive text-sm"
                >
                  {{ message }}
                </p>
              </div>
              <div class="flex gap-2">
                <Button type="submit" size="sm" :disabled="regenerateSubmitting">
                  {{
                    regenerateSubmitting
                      ? t('auth.mfa.security.deactivating')
                      : t('auth.mfa.security.confirmYes')
                  }}
                </Button>
                <Button type="button" variant="outline" size="sm" @click="cancelRegenerate">
                  {{ t('auth.mfa.security.confirmCancel') }}
                </Button>
              </div>
            </form>
          </template>
        </section>
      </template>

      <div class="border-border mt-6 flex justify-end border-t pt-4">
        <Button type="button" variant="outline" size="sm" @click="logoutFromHere">
          {{ t('auth.mfa.security.logout') }}
        </Button>
      </div>
    </div>
  </div>
</template>
