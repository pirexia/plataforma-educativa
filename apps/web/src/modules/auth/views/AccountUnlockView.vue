<script setup lang="ts">
/**
 * `/desbloquear/:token` (funcional.md §1.6/§4.4, api.md §5). Sin
 * formulario: un botón de confirmación explícito, no una llamada
 * automática al montar. Un envío automático en cuanto se abre el enlace
 * consumiría el token de un solo uso (`RN-AUTH-13`) si algo distinto de
 * la persona lo visita primero — un cliente de correo que previsualiza
 * enlaces, un escáner de seguridad corporativo — dejando a la persona
 * real con un enlace ya gastado. El botón evita ese caso sin coste real
 * de usabilidad.
 */
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { unlockAccount } from '../api'
import { usePublicAuthScreen } from '../composables/usePublicAuthScreen'
import { apiErrorStatus, retryAfterSeconds } from '../composables/formErrors'
import PublicAuthShell from '../components/PublicAuthShell.vue'

const t = useT()
const route = useRoute()
const { branding } = usePublicAuthScreen()

const token = String(route.params.token ?? '')
const submitting = ref(false)
const errorMessage = ref<string | null>(null)
const linkInvalid = ref(false)
const succeeded = ref(false)

async function submit() {
  submitting.value = true
  errorMessage.value = null

  try {
    await unlockAccount({ token })
    succeeded.value = true
  } catch (err) {
    const status = apiErrorStatus(err)

    if (status === 410) {
      linkInvalid.value = true
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
    <h1 class="mb-4 text-lg font-semibold">{{ t('auth.accountUnlock.title') }}</h1>

    <template v-if="succeeded">
      <p role="status" class="text-muted-foreground mb-4 text-sm">
        {{ t('auth.accountUnlock.success') }}
      </p>
      <RouterLink to="/entrar" class="text-primary text-sm hover:underline">
        {{ t('auth.accountUnlock.goToLogin') }}
      </RouterLink>
    </template>

    <template v-else-if="linkInvalid">
      <p role="alert" class="text-destructive text-sm">
        {{ t('auth.accountUnlock.invalidToken') }}
      </p>
    </template>

    <template v-else>
      <p class="text-muted-foreground mb-4 text-sm">{{ t('auth.accountUnlock.intro') }}</p>

      <p v-if="errorMessage" role="alert" class="text-destructive mb-4 text-sm">
        {{ errorMessage }}
      </p>

      <Button type="button" :disabled="submitting" class="w-full" @click="submit">
        {{ submitting ? t('auth.accountUnlock.submitting') : t('auth.accountUnlock.submit') }}
      </Button>
    </template>
  </PublicAuthShell>
</template>
