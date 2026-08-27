<script setup lang="ts">
/**
 * Alta y confirmación de un factor TOTP (funcional.md §C.4.1/§C.4.3,
 * api.md §C.4). Panel autónomo, sin `AppLayout`, que reutilizan las dos
 * pantallas que lo necesitan: `/cuenta/seguridad` (AccountSecurityView) y
 * `/cuenta/seguridad/obligatorio` (MfaEnrollmentWallView) — es
 * literalmente "la misma alta" en las dos (`funcional.md §C.11`).
 *
 * No decide nada de sesión ni de navegación: solo emite `enrolled` cuando
 * el factor queda confirmado y el usuario ha reconocido explícitamente
 * los códigos de respaldo (si los hubo). La vista que lo embebe decide
 * qué pasa después.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import QrCode from '@/components/QrCode.vue'
import RecoveryCodesReveal from './RecoveryCodesReveal.vue'
import { confirmMfaFactor, createMfaEnrollment } from '../api'
import { apiErrorStatus, fieldErrors, retryAfterSeconds } from '../composables/formErrors'
import type { MfaEnrollment } from '../types'

const props = withDefaults(defineProps<{ autoStart?: boolean }>(), { autoStart: false })
const emit = defineEmits<{ enrolled: [] }>()

const t = useT()

type Phase = 'idle' | 'starting' | 'enrolling' | 'confirming' | 'recovery-codes'

const phase = ref<Phase>('idle')
const enrollment = ref<MfaEnrollment | null>(null)
const code = ref('')
const codeInputEl = ref<InstanceType<typeof Input> | null>(null)
const errorMessage = ref<string | null>(null)
const codeErrors = ref<string[]>([])
const recoveryCodes = ref<string[] | null>(null)
const now = ref(Date.now())

let tickHandle: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  tickHandle = setInterval(() => {
    now.value = Date.now()
  }, 1000)

  if (props.autoStart) {
    void startEnrollment()
  }
})

onBeforeUnmount(() => {
  clearInterval(tickHandle)
})

const secondsRemaining = computed(() => {
  if (!enrollment.value) {
    return null
  }

  const diff = Math.floor((new Date(enrollment.value.expires_at).getTime() - now.value) / 1000)

  return Math.max(diff, 0)
})

const expired = computed(() => secondsRemaining.value === 0)

const formattedCountdown = computed(() => {
  if (secondsRemaining.value === null) {
    return ''
  }

  const minutes = Math.floor(secondsRemaining.value / 60)
  const seconds = secondsRemaining.value % 60

  return `${minutes}:${String(seconds).padStart(2, '0')}`
})

watch(phase, async (value) => {
  if (value === 'enrolling') {
    await nextTick()
    codeInputEl.value?.$el?.focus?.()
  }
})

async function startEnrollment(): Promise<void> {
  phase.value = 'starting'
  errorMessage.value = null

  try {
    enrollment.value = await createMfaEnrollment('totp')
    code.value = ''
    codeErrors.value = []
    phase.value = 'enrolling'
  } catch (err) {
    phase.value = 'idle'
    errorMessage.value = mfaErrorMessage(err)
  }
}

function cancelEnrollment(): void {
  enrollment.value = null
  code.value = ''
  codeErrors.value = []
  errorMessage.value = null
  phase.value = 'idle'
}

async function confirmCode(): Promise<void> {
  if (!enrollment.value) {
    return
  }

  phase.value = 'confirming'
  errorMessage.value = null
  codeErrors.value = []

  try {
    const result = await confirmMfaFactor(enrollment.value.public_id, code.value)

    if (result.recovery_codes) {
      recoveryCodes.value = result.recovery_codes
      phase.value = 'recovery-codes'
    } else {
      finish()
    }
  } catch (err) {
    const status = apiErrorStatus(err)

    if (status === 410) {
      errorMessage.value = t('auth.mfa.enrollment.expired')
      enrollment.value = null
      phase.value = 'idle'
      return
    }

    codeErrors.value = fieldErrors(err, 'code')
    if (codeErrors.value.length === 0) {
      errorMessage.value = mfaErrorMessage(err)
    }
    code.value = ''
    phase.value = 'enrolling'
  }
}

function finish(): void {
  enrollment.value = null
  recoveryCodes.value = null
  code.value = ''
  phase.value = 'idle'
  emit('enrolled')
}

function mfaErrorMessage(err: unknown): string {
  const status = apiErrorStatus(err)

  if (status === 422) {
    return t('auth.mfa.enrollment.notAllowed')
  }

  if (status === 429) {
    const seconds = retryAfterSeconds(err)
    return seconds !== null
      ? t('auth.common.tooManyRequestsWithSeconds', { seconds })
      : t('auth.common.tooManyRequests')
  }

  return t('auth.common.unexpectedError')
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div v-if="phase === 'idle'">
      <p class="text-muted-foreground mb-3 text-sm">{{ t('auth.mfa.enrollment.intro') }}</p>
      <p v-if="errorMessage" role="alert" class="text-destructive mb-3 text-sm">
        {{ errorMessage }}
      </p>
      <Button type="button" @click="startEnrollment">
        {{ t('auth.mfa.enrollment.start') }}
      </Button>
    </div>

    <div v-else-if="phase === 'starting'" role="status" class="text-muted-foreground text-sm">
      {{ t('auth.mfa.enrollment.starting') }}
    </div>

    <form
      v-else-if="phase === 'enrolling' || phase === 'confirming'"
      class="flex flex-col gap-4"
      novalidate
      @submit.prevent="confirmCode"
    >
      <p class="text-sm">{{ t('auth.mfa.enrollment.scanIntro') }}</p>

      <div class="flex justify-center">
        <QrCode
          v-if="enrollment"
          :value="enrollment.otpauth_uri"
          :label="t('auth.mfa.enrollment.qrLabel')"
          :module-size="6"
        />
      </div>

      <div class="flex flex-col gap-1.5">
        <Label>{{ t('auth.mfa.enrollment.secretLabel') }}</Label>
        <p class="text-muted-foreground text-xs">{{ t('auth.mfa.enrollment.secretHint') }}</p>
        <p
          class="border-border bg-muted select-all rounded-lg border px-3 py-2 font-mono text-sm break-all"
        >
          {{ enrollment?.secret }}
        </p>
      </div>

      <p v-if="expired" role="alert" class="text-destructive text-sm">
        {{ t('auth.mfa.enrollment.expired') }}
      </p>
      <p v-else class="text-muted-foreground text-xs">
        {{ t('auth.mfa.enrollment.expiresIn', { time: formattedCountdown }) }}
      </p>

      <div class="flex flex-col gap-1.5">
        <Label for="mfa-enrollment-code">{{ t('auth.mfa.enrollment.codeLabel') }}</Label>
        <Input
          id="mfa-enrollment-code"
          ref="codeInputEl"
          v-model="code"
          type="text"
          inputmode="numeric"
          autocomplete="one-time-code"
          pattern="[0-9]*"
          maxlength="6"
          :disabled="expired"
          required
        />
        <p
          v-for="message in codeErrors"
          :key="message"
          role="alert"
          class="text-destructive text-sm"
        >
          {{ message }}
        </p>
      </div>

      <p v-if="errorMessage" role="alert" class="text-destructive text-sm">
        {{ errorMessage }}
      </p>

      <div class="flex gap-2">
        <Button type="submit" :disabled="phase === 'confirming' || expired">
          {{
            phase === 'confirming'
              ? t('auth.mfa.enrollment.confirming')
              : t('auth.mfa.enrollment.confirm')
          }}
        </Button>
        <Button type="button" variant="outline" @click="cancelEnrollment">
          {{ t('auth.mfa.enrollment.cancel') }}
        </Button>
      </div>
    </form>

    <div v-else-if="phase === 'recovery-codes'" class="flex flex-col gap-4">
      <p class="text-sm font-medium">{{ t('auth.mfa.enrollment.activatedTitle') }}</p>
      <RecoveryCodesReveal :codes="recoveryCodes ?? []" @acknowledged="finish" />
    </div>
  </div>
</template>
