<script setup lang="ts">
/**
 * `/recuperar` (funcional.md §1.6/§4.5, api.md §4). `RN-AUTH-10`: la
 * respuesta es siempre la misma, exista o no la cuenta — no hay nada que
 * distinguir aquí tampoco.
 */
import { ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { requestPasswordReset } from '../api'
import { usePublicAuthScreen } from '../composables/usePublicAuthScreen'
import { apiErrorStatus, fieldErrors, retryAfterSeconds } from '../composables/formErrors'
import PublicAuthShell from '../components/PublicAuthShell.vue'

const t = useT()
const { branding } = usePublicAuthScreen()

const email = ref('')
const submitting = ref(false)
const errorMessage = ref<string | null>(null)
const emailErrors = ref<string[]>([])
const succeeded = ref(false)

async function submit() {
  submitting.value = true
  errorMessage.value = null
  emailErrors.value = []

  try {
    await requestPasswordReset({ email: email.value })
    succeeded.value = true
  } catch (err) {
    const status = apiErrorStatus(err)

    if (status === 422) {
      emailErrors.value = fieldErrors(err, 'email')
      if (emailErrors.value.length === 0) {
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
    <h1 class="mb-1 text-lg font-semibold">{{ t('auth.passwordResetRequest.title') }}</h1>

    <template v-if="succeeded">
      <p role="status" class="text-muted-foreground my-4 text-sm">
        {{ t('auth.passwordResetRequest.success') }}
      </p>
      <RouterLink to="/entrar" class="text-primary text-sm hover:underline">
        {{ t('auth.passwordResetRequest.backToLogin') }}
      </RouterLink>
    </template>

    <template v-else>
      <p class="text-muted-foreground mb-4 text-sm">{{ t('auth.passwordResetRequest.intro') }}</p>

      <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="reset-request-email">{{ t('auth.fields.email') }}</Label>
          <Input
            id="reset-request-email"
            v-model="email"
            type="email"
            autocomplete="username"
            required
          />
        </div>

        <p
          v-for="message in emailErrors"
          :key="message"
          role="alert"
          class="text-destructive text-sm"
        >
          {{ message }}
        </p>
        <p v-if="errorMessage" role="alert" class="text-destructive text-sm">{{ errorMessage }}</p>

        <Button type="submit" :disabled="submitting" class="w-full">
          {{
            submitting
              ? t('auth.passwordResetRequest.submitting')
              : t('auth.passwordResetRequest.submit')
          }}
        </Button>
      </form>

      <RouterLink to="/entrar" class="text-primary mt-4 block text-center text-sm hover:underline">
        {{ t('auth.passwordResetRequest.backToLogin') }}
      </RouterLink>
    </template>
  </PublicAuthShell>
</template>
