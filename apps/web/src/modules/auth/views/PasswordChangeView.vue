<script setup lang="ts">
/**
 * `/cuenta/contrasena` (funcional.md §1.6/§4.8, api.md §5b). Con sesión,
 * sin `AppLayout` (funcional.md §1.6: no depende del *layout* de 1.8) —
 * pero si no hay sesión, redirige a `/entrar`.
 *
 * Detección de sesión: `GET /me` de `REQ-CORE` (autorizado por identidad,
 * no por permiso, igual que este mismo endpoint de cambio de contraseña)
 * a través de su interfaz pública (`modules/core/api`, no su código
 * interno — `INV-007`). Un `401` aquí significa "sin sesión" y redirige;
 * cualquier otro resultado dejar pasar y pintar el formulario.
 */
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { getMe } from '@/modules/core/api'
import { changePassword } from '../api'
import { apiErrorStatus, fieldErrors, retryAfterSeconds } from '../composables/formErrors'
import PasswordPolicyHint from '../components/PasswordPolicyHint.vue'

const t = useT()
const router = useRouter()

const checkingSession = ref(true)
const currentPassword = ref('')
const newPassword = ref('')
const newPasswordConfirmation = ref('')
const submitting = ref(false)
const errorMessage = ref<string | null>(null)
const currentPasswordErrors = ref<string[]>([])
const newPasswordErrors = ref<string[]>([])
const succeeded = ref(false)

onMounted(async () => {
  try {
    await getMe()
  } catch (err) {
    if (apiErrorStatus(err) === 401) {
      await router.push({ name: 'login' })
      return
    }
  } finally {
    checkingSession.value = false
  }
})

async function submit() {
  submitting.value = true
  errorMessage.value = null
  currentPasswordErrors.value = []
  newPasswordErrors.value = []
  succeeded.value = false

  try {
    await changePassword({
      current_password: currentPassword.value,
      password: newPassword.value,
      password_confirmation: newPasswordConfirmation.value,
    })
    succeeded.value = true
    currentPassword.value = ''
    newPassword.value = ''
    newPasswordConfirmation.value = ''
  } catch (err) {
    const status = apiErrorStatus(err)

    if (status === 401) {
      await router.push({ name: 'login' })
    } else if (status === 423) {
      errorMessage.value = t('auth.common.accountLocked')
    } else if (status === 422) {
      currentPasswordErrors.value = fieldErrors(err, 'current_password')
      newPasswordErrors.value = fieldErrors(err, 'password')
      if (currentPasswordErrors.value.length === 0 && newPasswordErrors.value.length === 0) {
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
  <div
    v-if="!checkingSession"
    class="flex min-h-svh flex-col items-center justify-center px-4 py-10"
  >
    <div class="border-border bg-background w-full max-w-sm rounded-xl border p-6 shadow-sm">
      <h1 class="mb-1 text-lg font-semibold">{{ t('auth.passwordChange.title') }}</h1>
      <p class="text-muted-foreground mb-4 text-sm">{{ t('auth.passwordChange.intro') }}</p>

      <p
        v-if="succeeded"
        role="status"
        class="border-border bg-muted mb-4 rounded-lg border px-3 py-2 text-sm"
      >
        {{ t('auth.passwordChange.success') }}
      </p>

      <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="change-current-password">{{ t('auth.fields.currentPassword') }}</Label>
          <Input
            id="change-current-password"
            v-model="currentPassword"
            type="password"
            autocomplete="current-password"
            required
          />
          <p
            v-for="message in currentPasswordErrors"
            :key="message"
            role="alert"
            class="text-destructive text-sm"
          >
            {{ message }}
          </p>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="change-new-password">{{ t('auth.fields.newPassword') }}</Label>
          <Input
            id="change-new-password"
            v-model="newPassword"
            type="password"
            autocomplete="new-password"
            aria-describedby="auth-password-policy-hint"
            required
          />
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="change-new-password-confirmation">{{
            t('auth.fields.newPasswordConfirmation')
          }}</Label>
          <Input
            id="change-new-password-confirmation"
            v-model="newPasswordConfirmation"
            type="password"
            autocomplete="new-password"
            required
          />
        </div>

        <PasswordPolicyHint />

        <p
          v-for="message in newPasswordErrors"
          :key="message"
          role="alert"
          class="text-destructive text-sm"
        >
          {{ message }}
        </p>
        <p v-if="errorMessage" role="alert" class="text-destructive text-sm">{{ errorMessage }}</p>

        <Button type="submit" :disabled="submitting" class="w-full">
          {{ submitting ? t('auth.passwordChange.submitting') : t('auth.passwordChange.submit') }}
        </Button>
      </form>
    </div>
  </div>
</template>
