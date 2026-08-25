<script setup lang="ts">
/**
 * `/activar/:token` (funcional.md §1.6/§4.1, api.md §3). El token viaja
 * en la URL de la SPA, nunca en la de la API: se extrae aquí y se envía
 * en el cuerpo (`funcional.md §4.7`).
 */
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { redeemInvitation } from '../api'
import { usePublicAuthScreen } from '../composables/usePublicAuthScreen'
import { apiErrorStatus, fieldErrors, retryAfterSeconds } from '../composables/formErrors'
import PublicAuthShell from '../components/PublicAuthShell.vue'
import PasswordPolicyHint from '../components/PasswordPolicyHint.vue'

const t = useT()
const route = useRoute()
const router = useRouter()
const { branding } = usePublicAuthScreen()

const token = String(route.params.token ?? '')
const password = ref('')
const passwordConfirmation = ref('')
const submitting = ref(false)
const errorMessage = ref<string | null>(null)
const linkInvalid = ref(false)
const passwordErrors = ref<string[]>([])

async function submit() {
  submitting.value = true
  errorMessage.value = null
  passwordErrors.value = []

  try {
    await redeemInvitation({
      token,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    await router.push({ name: 'login', query: { activated: '1' } })
  } catch (err) {
    const status = apiErrorStatus(err)

    if (status === 410) {
      linkInvalid.value = true
    } else if (status === 422) {
      passwordErrors.value = fieldErrors(err, 'password')
      if (passwordErrors.value.length === 0) {
        errorMessage.value = t('auth.common.unexpectedError')
      }
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
</script>

<template>
  <PublicAuthShell :branding="branding">
    <template v-if="linkInvalid">
      <h1 class="mb-4 text-lg font-semibold">{{ t('auth.activation.title') }}</h1>
      <p role="alert" class="text-destructive mb-4 text-sm">
        {{ t('auth.activation.invalidToken') }}
      </p>
      <RouterLink to="/entrar" class="text-primary text-sm hover:underline">
        {{ t('auth.activation.backToLogin') }}
      </RouterLink>
    </template>

    <template v-else>
      <h1 class="mb-1 text-lg font-semibold">{{ t('auth.activation.title') }}</h1>
      <p class="text-muted-foreground mb-4 text-sm">{{ t('auth.activation.intro') }}</p>

      <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="activation-password">{{ t('auth.fields.newPassword') }}</Label>
          <Input
            id="activation-password"
            v-model="password"
            type="password"
            autocomplete="new-password"
            aria-describedby="auth-password-policy-hint"
            required
          />
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="activation-password-confirmation">{{
            t('auth.fields.newPasswordConfirmation')
          }}</Label>
          <Input
            id="activation-password-confirmation"
            v-model="passwordConfirmation"
            type="password"
            autocomplete="new-password"
            required
          />
        </div>

        <PasswordPolicyHint />

        <p
          v-for="message in passwordErrors"
          :key="message"
          role="alert"
          class="text-destructive text-sm"
        >
          {{ message }}
        </p>
        <p v-if="errorMessage" role="alert" class="text-destructive text-sm">{{ errorMessage }}</p>

        <Button type="submit" :disabled="submitting" class="w-full">
          {{ submitting ? t('auth.activation.submitting') : t('auth.activation.submit') }}
        </Button>
      </form>
    </template>
  </PublicAuthShell>
</template>
