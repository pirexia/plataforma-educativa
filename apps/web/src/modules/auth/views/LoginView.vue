<script setup lang="ts">
/**
 * `/entrar` (funcional.md §1.6, api.md §2). Pública, sin `AppLayout`.
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

const t = useT()
const route = useRoute()
const router = useRouter()
const { branding } = usePublicAuthScreen()

const email = ref('')
const password = ref('')
const submitting = ref(false)
const errorMessage = ref<string | null>(null)

// funcional.md §4.7: los cuatro casos de 401 (credencial incorrecta,
// correo inexistente, pendiente, inactivo) comparten cuerpo idéntico y se
// muestran con el mismo mensaje genérico — no hay forma, ni intención, de
// distinguirlos aquí.
async function submit() {
  submitting.value = true
  errorMessage.value = null

  try {
    await login({ email: email.value, password: password.value })
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
</script>

<template>
  <PublicAuthShell :branding="branding">
    <h1 class="mb-4 text-lg font-semibold">{{ t('auth.login.title') }}</h1>

    <p v-if="banner" class="border-border bg-muted mb-4 rounded-lg border px-3 py-2 text-sm">
      {{ banner }}
    </p>

    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
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
  </PublicAuthShell>
</template>
