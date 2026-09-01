<script setup lang="ts">
/**
 * REQ-AUTH-004 (1.4b), funcional.md §F.9, api.md §F.6. `/entrar`: la
 * lista de proveedores deja de ser «cero o uno» (1.4, `GoogleSignInButton.vue`)
 * y pasa a ser **N** — un botón por proveedor devuelto por
 * `GET /auth/identity-providers`, con el nombre que el centro le puso.
 * Con N botones, la lista es una lista, no una fila de botones sueltos
 * (RNF-UX-002, WCAG 2.2 AA).
 *
 * El *driver* global de Google conserva su logotipo inline (mismo
 * criterio que `GoogleSignInButton.vue`, funcional.md §E.9); un
 * proveedor institucional **no lleva logotipo**: lleva el nombre que el
 * centro le puso (funcional.md §F.9 — ningún logotipo de terceros se
 * sirve desde su dominio).
 */
import { onMounted, ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { beginOAuthAuthorization, getIdentityProviders } from '../api'
import { apiErrorStatus, retryAfterSeconds } from '../composables/formErrors'
import type { IdentityProvider } from '../types'

const t = useT()

const providers = ref<IdentityProvider[]>([])
const startingId = ref<string | null>(null)
const errorMessage = ref<string | null>(null)

onMounted(async () => {
  try {
    const { data } = await getIdentityProviders()
    providers.value = data
  } catch {
    // RN-AUTH-98: sin confirmación, no se pinta ningún botón.
    providers.value = []
  }
})

function labelFor(provider: IdentityProvider): string {
  if (provider.display_name) {
    return provider.display_name
  }

  if (provider.display_name_key) {
    return t(provider.display_name_key)
  }

  return provider.id
}

async function start(provider: IdentityProvider) {
  if (startingId.value !== null) {
    return
  }

  startingId.value = provider.id
  errorMessage.value = null

  try {
    const { authorization_url } = await beginOAuthAuthorization('login', provider.id)
    window.location.href = authorization_url
  } catch (err) {
    const status = apiErrorStatus(err)

    if (status === 429) {
      const seconds = retryAfterSeconds(err)
      errorMessage.value =
        seconds !== null
          ? t('auth.common.tooManyRequestsWithSeconds', { seconds })
          : t('auth.common.tooManyRequests')
    } else {
      errorMessage.value = t('auth.common.unexpectedError')
    }

    startingId.value = null
  }
}
</script>

<template>
  <ul v-if="providers.length > 0" class="mt-4 flex list-none flex-col gap-2 p-0">
    <li v-for="provider in providers" :key="provider.id">
      <Button
        type="button"
        variant="outline"
        class="w-full gap-2"
        :disabled="startingId !== null"
        @click="start(provider)"
      >
        <svg v-if="provider.id === 'google'" viewBox="0 0 18 18" class="size-4" aria-hidden="true">
          <path
            fill="#4285F4"
            d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844a4.14 4.14 0 0 1-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.874 2.684-6.615z"
          />
          <path
            fill="#34A853"
            d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z"
          />
          <path
            fill="#FBBC05"
            d="M3.964 10.71A5.4 5.4 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z"
          />
          <path
            fill="#EA4335"
            d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z"
          />
        </svg>
        {{ startingId === provider.id ? t('auth.oauth.starting') : labelFor(provider) }}
      </Button>
    </li>
  </ul>

  <p v-if="errorMessage" role="alert" class="text-destructive mt-2 text-sm">
    {{ errorMessage }}
  </p>
</template>
