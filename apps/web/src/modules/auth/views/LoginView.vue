<script setup lang="ts">
/**
 * `/entrar` (funcional.md §1.6, §C.11, api.md §2/§C.2-§C.3). Pública, sin
 * `AppLayout`. Paso 1: credenciales. Paso 2 (REQ-AUTH-003, 1.3): segundo
 * factor — misma ruta, mismo componente, sin navegar
 * (`funcional.md §C.11`: "sin salir de la ruta ni perder el contexto").
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { login, switchMfaChallenge, verifyMfaChallenge } from '../api'
import { usePublicAuthScreen } from '../composables/usePublicAuthScreen'
import { apiErrorStatus, fieldErrors, retryAfterSeconds } from '../composables/formErrors'
import PublicAuthShell from '../components/PublicAuthShell.vue'
import type { MfaChallenge } from '../types'

const t = useT()
const route = useRoute()
const router = useRouter()
const { branding } = usePublicAuthScreen()

type Step = 'credentials' | 'challenge'

const step = ref<Step>('credentials')

// -- Paso 1: credenciales ---------------------------------------------------

const email = ref('')
const password = ref('')
const submitting = ref(false)
const errorMessage = ref<string | null>(null)

// funcional.md §4.7: los cuatro casos de 401 (credencial incorrecta,
// correo inexistente, pendiente, inactivo) comparten cuerpo idéntico y se
// muestran con el mismo mensaje genérico — no hay forma, ni intención, de
// distinguirlos aquí.
async function submitCredentials() {
  submitting.value = true
  errorMessage.value = null

  try {
    const result = await login({ email: email.value, password: password.value })

    if (result.kind === 'mfa-challenge') {
      openChallenge(result.challenge)
      return
    }

    await router.push({ name: 'home' })
  } catch (err) {
    const status = apiErrorStatus(err)

    if (status === 401) {
      errorMessage.value = t('auth.login.invalidCredentials')
    } else if (status === 423) {
      errorMessage.value = t('auth.common.accountLocked')
    } else if (status === 422) {
      errorMessage.value =
        fieldErrors(err, 'email')[0] ??
        fieldErrors(err, 'password')[0] ??
        t('auth.login.invalidCredentials')
    } else if (status === 429) {
      const seconds = retryAfterSeconds(err)
      errorMessage.value =
        seconds !== null
          ? t('auth.common.tooManyRequestsWithSeconds', { seconds })
          : t('auth.common.tooManyRequests')
    } else {
      errorMessage.value = t('auth.common.unexpectedError')
    }
  } finally {
    submitting.value = false
  }
}

const banner = computed(() => {
  if (route.query.activated !== undefined) {
    return t('auth.login.activatedBanner')
  }

  if (route.query.reset !== undefined) {
    return t('auth.login.resetBanner')
  }

  return null
})

// -- Paso 2: segundo factor (REQ-AUTH-003, `RN-AUTH-52`) --------------------

const challenge = ref<MfaChallenge | null>(null)
const code = ref('')
const recoveryCode = ref('')
const useRecoveryCode = ref(false)
const challengeSubmitting = ref(false)
const challengeError = ref<string | null>(null)
const showClockHint = ref(false)
const consecutiveFailures = ref(0)
const resending = ref(false)
const codeInputEl = ref<InstanceType<typeof Input> | null>(null)
const now = ref(Date.now())

let tickHandle: ReturnType<typeof setInterval> | undefined

function startTicking() {
  stopTicking()
  tickHandle = setInterval(() => {
    now.value = Date.now()
  }, 1000)
}

function stopTicking() {
  if (tickHandle !== undefined) {
    clearInterval(tickHandle)
    tickHandle = undefined
  }
}

onBeforeUnmount(stopTicking)

const secondsRemaining = computed(() => {
  if (!challenge.value) {
    return null
  }

  const diff = Math.floor((new Date(challenge.value.expires_at).getTime() - now.value) / 1000)

  return Math.max(diff, 0)
})

const challengeExpired = computed(() => secondsRemaining.value === 0)

const formattedCountdown = computed(() => {
  if (secondsRemaining.value === null) {
    return ''
  }

  const minutes = Math.floor(secondsRemaining.value / 60)
  const seconds = secondsRemaining.value % 60

  return `${minutes}:${String(seconds).padStart(2, '0')}`
})

watch(challengeExpired, (expired) => {
  if (expired && step.value === 'challenge') {
    backToCredentials(t('auth.mfaChallenge.expired'))
  }
})

function openChallenge(value: MfaChallenge) {
  challenge.value = value
  code.value = ''
  recoveryCode.value = ''
  useRecoveryCode.value = false
  challengeError.value = null
  showClockHint.value = false
  consecutiveFailures.value = 0
  step.value = 'challenge'
  startTicking()
  void nextTick(() => codeInputEl.value?.$el?.focus?.())
}

function backToCredentials(message: string) {
  stopTicking()
  challenge.value = null
  step.value = 'credentials'
  password.value = ''
  errorMessage.value = message
}

function toggleRecoveryCode() {
  useRecoveryCode.value = !useRecoveryCode.value
  challengeError.value = null
  code.value = ''
  recoveryCode.value = ''
  void nextTick(() => codeInputEl.value?.$el?.focus?.())
}

async function submitChallenge() {
  challengeSubmitting.value = true
  challengeError.value = null

  try {
    const payload = useRecoveryCode.value
      ? { recovery_code: recoveryCode.value }
      : { code: code.value }

    await verifyMfaChallenge(payload)
    stopTicking()
    await router.push({ name: 'home' })
  } catch (err) {
    const status = apiErrorStatus(err)

    if (status === 401) {
      consecutiveFailures.value += 1
      challengeError.value = t('auth.mfaChallenge.invalidCode')
      showClockHint.value = !useRecoveryCode.value && consecutiveFailures.value >= 2
      code.value = ''
      recoveryCode.value = ''
    } else if (status === 410) {
      backToCredentials(t('auth.mfaChallenge.expired'))
    } else if (status === 423) {
      backToCredentials(t('auth.common.accountLocked'))
    } else if (status === 422) {
      challengeError.value = t('auth.mfaChallenge.invalidCode')
    } else if (status === 429) {
      const seconds = retryAfterSeconds(err)
      challengeError.value =
        seconds !== null
          ? t('auth.common.tooManyRequestsWithSeconds', { seconds })
          : t('auth.common.tooManyRequests')
    } else {
      challengeError.value = t('auth.common.unexpectedError')
    }
  } finally {
    challengeSubmitting.value = false
  }
}

async function switchMethod(method: MfaChallenge['method']) {
  if (!challenge.value || method === challenge.value.method) {
    return
  }

  try {
    challenge.value = await switchMfaChallenge(method)
    useRecoveryCode.value = false
    code.value = ''
    challengeError.value = null
  } catch (err) {
    if (apiErrorStatus(err) === 410) {
      backToCredentials(t('auth.mfaChallenge.expired'))
    }
  }
}

async function resendCode() {
  if (!challenge.value || resending.value) {
    return
  }

  resending.value = true

  try {
    challenge.value = await switchMfaChallenge(challenge.value.method)
    challengeError.value = null
  } catch (err) {
    if (apiErrorStatus(err) === 410) {
      backToCredentials(t('auth.mfaChallenge.expired'))
    } else if (apiErrorStatus(err) === 429) {
      const seconds = retryAfterSeconds(err)
      challengeError.value =
        seconds !== null
          ? t('auth.common.tooManyRequestsWithSeconds', { seconds })
          : t('auth.common.tooManyRequests')
    }
  } finally {
    resending.value = false
  }
}
</script>

<template>
  <PublicAuthShell :branding="branding">
    <template v-if="step === 'credentials'">
      <h1 class="mb-4 text-lg font-semibold">{{ t('auth.login.title') }}</h1>

      <p v-if="banner" class="border-border bg-muted mb-4 rounded-lg border px-3 py-2 text-sm">
        {{ banner }}
      </p>

      <form class="flex flex-col gap-4" novalidate @submit.prevent="submitCredentials">
        <div class="flex flex-col gap-1.5">
          <Label for="login-email">{{ t('auth.fields.email') }}</Label>
          <Input id="login-email" v-model="email" type="email" autocomplete="username" required />
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="login-password">{{ t('auth.fields.password') }}</Label>
          <Input
            id="login-password"
            v-model="password"
            type="password"
            autocomplete="current-password"
            required
          />
        </div>

        <p v-if="errorMessage" role="alert" class="text-destructive text-sm">
          {{ errorMessage }}
        </p>

        <Button type="submit" :disabled="submitting" class="w-full">
          {{ submitting ? t('auth.login.submitting') : t('auth.login.submit') }}
        </Button>
      </form>

      <div class="mt-4 flex flex-col gap-1 text-center text-sm">
        <RouterLink to="/recuperar" class="text-primary hover:underline">
          {{ t('auth.login.forgotPassword') }}
        </RouterLink>
        <p class="text-muted-foreground">{{ t('auth.login.pendingHint') }}</p>
      </div>
    </template>

    <template v-else-if="challenge">
      <h1 class="mb-1 text-lg font-semibold">{{ t('auth.mfaChallenge.title') }}</h1>
      <p class="text-muted-foreground mb-4 text-sm">{{ t('auth.mfaChallenge.intro') }}</p>

      <div
        v-if="challenge.available_methods.length > 1"
        class="mb-4 flex gap-2"
        role="group"
        :aria-label="t('auth.mfaChallenge.methodSelectorLabel')"
      >
        <Button
          v-for="method in challenge.available_methods"
          :key="method"
          type="button"
          size="sm"
          :variant="method === challenge.method ? 'default' : 'outline'"
          @click="switchMethod(method)"
        >
          {{ t(`auth.mfa.method.${method}`) }}
        </Button>
      </div>

      <form
        v-if="!useRecoveryCode"
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitChallenge"
      >
        <div class="flex flex-col gap-1.5">
          <Label for="mfa-challenge-code">{{ t('auth.mfaChallenge.codeLabel') }}</Label>
          <Input
            id="mfa-challenge-code"
            ref="codeInputEl"
            v-model="code"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            pattern="[0-9]*"
            maxlength="6"
            :disabled="challengeExpired"
            required
          />
        </div>

        <p v-if="showClockHint" class="text-muted-foreground text-sm">
          {{ t('auth.mfaChallenge.clockHint') }}
        </p>
        <p v-if="challengeError" role="alert" class="text-destructive text-sm">
          {{ challengeError }}
        </p>
        <p v-if="!challengeExpired" class="text-muted-foreground text-xs">
          {{ t('auth.mfaChallenge.expiresIn', { time: formattedCountdown }) }}
        </p>

        <Button type="submit" :disabled="challengeSubmitting || challengeExpired" class="w-full">
          {{
            challengeSubmitting ? t('auth.mfaChallenge.submitting') : t('auth.mfaChallenge.submit')
          }}
        </Button>

        <div class="flex items-center justify-between text-sm">
          <button
            type="button"
            class="text-primary hover:underline"
            @click="resendCode"
            :disabled="resending || challengeExpired"
          >
            {{ resending ? t('auth.mfaChallenge.resending') : t('auth.mfaChallenge.resend') }}
          </button>

          <button
            v-if="challenge.has_unused_recovery_codes"
            type="button"
            class="text-primary hover:underline"
            @click="toggleRecoveryCode"
          >
            {{ t('auth.mfaChallenge.useRecoveryCode') }}
          </button>
        </div>
      </form>

      <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submitChallenge">
        <div class="flex flex-col gap-1.5">
          <Label for="mfa-challenge-recovery-code">{{
            t('auth.mfaChallenge.recoveryCodeLabel')
          }}</Label>
          <Input
            id="mfa-challenge-recovery-code"
            ref="codeInputEl"
            v-model="recoveryCode"
            type="text"
            autocomplete="one-time-code"
            required
          />
        </div>

        <p v-if="challengeError" role="alert" class="text-destructive text-sm">
          {{ challengeError }}
        </p>

        <Button type="submit" :disabled="challengeSubmitting" class="w-full">
          {{
            challengeSubmitting ? t('auth.mfaChallenge.submitting') : t('auth.mfaChallenge.submit')
          }}
        </Button>

        <button
          type="button"
          class="text-primary text-sm hover:underline"
          @click="toggleRecoveryCode"
        >
          {{ t('auth.mfaChallenge.useCodeInstead') }}
        </button>
      </form>
    </template>
  </PublicAuthShell>
</template>
