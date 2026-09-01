<script setup lang="ts">
/**
 * `/entrar` (funcional.md §1.6, §C.11, api.md §2/§C.2-§C.3, §E.9). Pública,
 * sin `AppLayout`. Paso 1: credenciales, con el botón "Continuar con
 * Google" si el proveedor está disponible (RN-AUTH-98). Paso 2
 * (REQ-AUTH-003, 1.3): segundo factor — misma ruta, mismo componente, sin
 * navegar (`funcional.md §C.11`: "sin salir de la ruta ni perder el
 * contexto") — delegado en `MfaChallengeStep.vue` desde 1.4, que
 * `/entrar/google` reutiliza tal cual para el mismo paso llegado por el
 * *callback* federado.
 */
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { login } from '../api'
import { usePublicAuthScreen } from '../composables/usePublicAuthScreen'
import { apiErrorStatus, fieldErrors, retryAfterSeconds } from '../composables/formErrors'
import PublicAuthShell from '../components/PublicAuthShell.vue'
import IdentityProviderLoginList from '../components/IdentityProviderLoginList.vue'
import MfaChallengeStep from '../components/MfaChallengeStep.vue'
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
const initialChallenge = ref<MfaChallenge | null>(null)

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
      initialChallenge.value = result.challenge
      step.value = 'challenge'
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

function onChallengeLost(message: string) {
  initialChallenge.value = null
  step.value = 'credentials'
  password.value = ''
  errorMessage.value = message
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

      <!-- REQ-AUTH-002 (1.4)/REQ-AUTH-004 (1.4b), funcional.md §E.9/§F.9:
           solo los proveedores que GET /auth/identity-providers confirma
           — RN-AUTH-98, nunca por una constante del cliente. -->
      <IdentityProviderLoginList />

      <div class="mt-4 flex flex-col gap-1 text-center text-sm">
        <RouterLink to="/recuperar" class="text-primary hover:underline">
          {{ t('auth.login.forgotPassword') }}
        </RouterLink>
        <p class="text-muted-foreground">{{ t('auth.login.pendingHint') }}</p>
      </div>
    </template>

    <template v-else>
      <MfaChallengeStep
        :initial-challenge="initialChallenge ?? undefined"
        @lost="onChallengeLost"
      />
    </template>
  </PublicAuthShell>
</template>
