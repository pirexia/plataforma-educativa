<script setup lang="ts">
/**
 * REQ-AUTH-002 (1.4), funcional.md §E.9, §E.4.1, §E.4.4. Botón «Continuar
 * con Google» (`intent = 'login'`, en `/entrar`) o «Vincular con Google»
 * (`intent = 'link'`, en `/cuenta/seguridad`). Reutilizado por las dos
 * pantallas: la única diferencia entre ambas es el `intent` que arranca
 * el flujo (`api.md §E.3`) y la etiqueta.
 *
 * Se pinta solo si `GET /auth/identity-providers` devuelve el proveedor
 * (`RN-AUTH-98`) — nunca por una constante del cliente ni por una
 * variable de compilación: un despliegue sin credenciales configuradas
 * (`AUTH_OAUTH_DRIVER=none`, el valor por defecto) no debe enseñar un
 * botón que solo lleva a un error (`CA-AUTH-200`).
 *
 * Navega con `window.location`, nunca con un formulario: un
 * `<form action="https://accounts.google.com/...">` chocaría con
 * `form-action 'self'` en la CSP estricta de `CLAUDE.md §8`
 * (`funcional.md §E.9`). Es un `button` real que primero escribe
 * (`POST /auth/oauth-authorizations`, bajo CSRF) y después navega — nunca
 * un enlace, porque anunciarlo como tal mentiría sobre lo que hace.
 *
 * El logotipo de Google va **inline**, nunca cargado desde un dominio de
 * Google: la CSP no lo admitiría y cargarlo filtraría la IP de quien
 * abra la pantalla a Google, tenga cuenta o no (`funcional.md §E.9`).
 */
import { onMounted, ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { beginOAuthAuthorization, getIdentityProviders } from '../api'
import { apiErrorStatus, retryAfterSeconds } from '../composables/formErrors'

const props = withDefaults(defineProps<{ intent?: 'login' | 'link' }>(), {
  intent: 'login',
})

const t = useT()

const available = ref(false)
const starting = ref(false)
const errorMessage = ref<string | null>(null)

onMounted(async () => {
  try {
    const { data } = await getIdentityProviders()
    available.value = data.some((provider) => provider.provider === 'google')
  } catch {
    // RN-AUTH-98: sin proveedor confirmado, no se pinta el botón. Un
    // fallo de red aquí se trata igual que "no hay proveedor" — la
    // pantalla de login no debe romperse por un endpoint puramente
    // informativo.
    available.value = false
  }
})

async function start() {
  if (starting.value) {
    return
  }

  starting.value = true
  errorMessage.value = null

  try {
    // api.md §E.3: 201 con la URL de autorización. La SPA navega; el
    // servidor nunca responde 302 aquí (RN-AUTH-29: la escritura queda
    // bajo CSRF).
    const { authorization_url } = await beginOAuthAuthorization(props.intent)
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
      // 422 (proveedor ya no configurado), 403 (muro de MFA — client.ts
      // ya redirige, funcional.md §E.4.4 punto 5) o cualquier otro fallo:
      // no hay más detalle que dar aquí que no repita lo que ya cuenta
      // `apiFetch`.
      errorMessage.value = t('auth.common.unexpectedError')
    }

    starting.value = false
  }
}
</script>

<template>
  <div v-if="available">
    <Button
      type="button"
      variant="outline"
      class="w-full gap-2"
      :disabled="starting"
      @click="start"
    >
      <svg viewBox="0 0 18 18" class="size-4" aria-hidden="true">
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
      {{
        starting
          ? t('auth.oauth.starting')
          : intent === 'link'
            ? t('auth.oauth.linkButton')
            : t('auth.oauth.loginButton')
      }}
    </Button>

    <p v-if="errorMessage" role="alert" class="text-destructive mt-2 text-sm">
      {{ errorMessage }}
    </p>
  </div>
</template>
