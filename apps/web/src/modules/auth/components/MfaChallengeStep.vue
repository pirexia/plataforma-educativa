<script setup lang="ts">
/**
 * Paso 2 del login (segundo factor), extraído de `LoginView.vue`
 * (REQ-AUTH-003, `funcional.md §C.11`) para que REQ-AUTH-002 (1.4) pueda
 * reutilizarlo tal cual desde `/entrar/google` cuando el *callback*
 * federado abre un desafío (`resultado=segundo_factor`,
 * `api.md §E.5b`): *"continuar en la pantalla de segundo factor que ya
 * existe desde 1.3"* — es literalmente este componente, no una copia.
 *
 * Sin `initialChallenge`, lo recupera él mismo con
 * `GET /auth/mfa-challenges` (estrictamente de lectura, `CA-AUTH-239`):
 * es el camino que necesita `/entrar/google`, porque el `302` del
 * *callback* no lleva datos (`RN-AUTH-93`). El login local sigue
 * pasando el desafío que ya recibió en el `202` de `POST /auth/session`,
 * sin la vuelta extra.
 *
 * No decide navegación de "volver atrás": emite `lost` con un mensaje ya
 * traducido y deja que cada pantalla decida qué significa "atrás" en
 * su contexto (LoginView vuelve al paso de credenciales; el resultado
 * del *callback* no tiene paso 1 al que volver, así que ofrece un enlace
 * a `/entrar`). Al completar el login, navega él mismo a `home`
 * (destino confirmado, único en toda la SPA).
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { getCurrentMfaChallenge, switchMfaChallenge, verifyMfaChallenge } from '../api'
import { apiErrorStatus, retryAfterSeconds } from '../composables/formErrors'
import type { MfaChallenge } from '../types'

const props = defineProps<{ initialChallenge?: MfaChallenge }>()
const emit = defineEmits<{ lost: [message: string] }>()

const t = useT()
const router = useRouter()

const challenge = ref<MfaChallenge | null>(props.initialChallenge ?? null)
const loadingInitial = ref(!props.initialChallenge)
const code = ref('')
const recoveryCode = ref('')
const useRecoveryCode = ref(false)
const submitting = ref(false)
const errorMessage = ref<string | null>(null)
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

onMounted(async () => {
  if (challenge.value) {
    startTicking()
    void nextTick(() => codeInputEl.value?.$el?.focus?.())
    return
  }

  try {
    // api.md §E.5b: sin datos en el 302 del callback, este GET es la
    // única forma de recuperar el desafío que abrió (RN-AUTH-93).
    challenge.value = await getCurrentMfaChallenge()
    startTicking()
    void nextTick(() => codeInputEl.value?.$el?.focus?.())
  } catch {
    // 410: sin desafío vivo para esta sesión (RN-AUTH-53) — nunca 401
    // (RN-AUTH-52). Cualquier otro fallo se presenta igual: sin desafío
    // que mostrar, no hay nada más que hacer aquí.
    emit('lost', t('auth.mfaChallenge.expired'))
  } finally {
    loadingInitial.value = false
  }
})

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
  if (expired) {
    stopTicking()
    emit('lost', t('auth.mfaChallenge.expired'))
  }
})

function toggleRecoveryCode() {
  useRecoveryCode.value = !useRecoveryCode.value
  errorMessage.value = null
  code.value = ''
  recoveryCode.value = ''
  void nextTick(() => codeInputEl.value?.$el?.focus?.())
}

async function submitChallenge() {
  submitting.value = true
  errorMessage.value = null

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
      errorMessage.value = t('auth.mfaChallenge.invalidCode')
      showClockHint.value =
        !useRecoveryCode.value &&
        consecutiveFailures.value >= 2 &&
        challenge.value?.method === 'totp'
      code.value = ''
      recoveryCode.value = ''
    } else if (status === 410) {
      stopTicking()
      emit('lost', t('auth.mfaChallenge.expired'))
    } else if (status === 423) {
      stopTicking()
      emit('lost', t('auth.common.accountLocked'))
    } else if (status === 422) {
      errorMessage.value = t('auth.mfaChallenge.invalidCode')
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

async function switchMethod(method: MfaChallenge['method']) {
  if (!challenge.value || method === challenge.value.method) {
    return
  }

  try {
    challenge.value = await switchMfaChallenge(method)
    useRecoveryCode.value = false
    code.value = ''
    errorMessage.value = null
  } catch (err) {
    if (apiErrorStatus(err) === 410) {
      stopTicking()
      emit('lost', t('auth.mfaChallenge.expired'))
    }
  }
}

const hasAlternatives = computed(
  () =>
    (challenge.value?.available_methods.length ?? 0) > 1 ||
    challenge.value?.has_unused_recovery_codes === true,
)

async function resendCode() {
  if (!challenge.value || resending.value) {
    return
  }

  resending.value = true

  try {
    challenge.value = await switchMfaChallenge(challenge.value.method)
    errorMessage.value = null
  } catch (err) {
    if (apiErrorStatus(err) === 410) {
      stopTicking()
      emit('lost', t('auth.mfaChallenge.expired'))
    } else if (apiErrorStatus(err) === 429) {
      const seconds = retryAfterSeconds(err)
      const limitMessage =
        seconds !== null
          ? t('auth.common.tooManyRequestsWithSeconds', { seconds })
          : t('auth.common.tooManyRequests')

      errorMessage.value = hasAlternatives.value
        ? `${limitMessage} ${t('auth.mfaChallenge.resendLimitAlternatives')}`
        : limitMessage
    }
  } finally {
    resending.value = false
  }
}
</script>

<template>
  <p v-if="loadingInitial" class="text-muted-foreground text-sm">
    {{ t('auth.mfaChallenge.loading') }}
  </p>

  <template v-else-if="challenge">
    <h1 class="mb-1 text-lg font-semibold">{{ t('auth.mfaChallenge.title') }}</h1>
    <p class="text-muted-foreground mb-4 text-sm">
      {{
        challenge.destination_masked
          ? t('auth.mfaChallenge.introDelivery', { destination: challenge.destination_masked })
          : t('auth.mfaChallenge.intro')
      }}
    </p>

    <RadioGroup
      v-if="challenge.available_methods.length > 1"
      class="mb-4"
      :model-value="challenge.method"
      :aria-label="t('auth.mfaChallenge.methodSelectorLabel')"
      @update:model-value="(value) => switchMethod(value as MfaChallenge['method'])"
    >
      <div
        v-for="method in challenge.available_methods"
        :key="method"
        class="flex items-center gap-2"
      >
        <RadioGroupItem :id="`mfa-challenge-method-${method}`" :value="method" />
        <Label :for="`mfa-challenge-method-${method}`">{{ t(`auth.mfa.method.${method}`) }}</Label>
      </div>
    </RadioGroup>

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
      <p v-if="errorMessage" role="alert" class="text-destructive text-sm">
        {{ errorMessage }}
      </p>
      <p v-if="!challengeExpired" class="text-muted-foreground text-xs">
        {{ t('auth.mfaChallenge.expiresIn', { time: formattedCountdown }) }}
      </p>

      <Button type="submit" :disabled="submitting || challengeExpired" class="w-full">
        {{ submitting ? t('auth.mfaChallenge.submitting') : t('auth.mfaChallenge.submit') }}
      </Button>

      <div class="flex items-center justify-between text-sm">
        <button
          type="button"
          class="text-primary hover:underline"
          :disabled="resending || challengeExpired"
          @click="resendCode"
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

      <p v-if="errorMessage" role="alert" class="text-destructive text-sm">
        {{ errorMessage }}
      </p>

      <Button type="submit" :disabled="submitting" class="w-full">
        {{ submitting ? t('auth.mfaChallenge.submitting') : t('auth.mfaChallenge.submit') }}
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
</template>
