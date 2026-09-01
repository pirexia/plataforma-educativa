<script setup lang="ts">
/**
 * `/entrar/google` (funcional.md §E.9, api.md §E.4.2). Pública, sin
 * `AppLayout` — misma categoría que `/entrar`. Destino del `302` del
 * *callback* de Google cuando **no** hay redirección directa a la raíz
 * (login completado sin desafío, `OAuthCallbackController::SUCCESS_PATH`):
 * el `302` nunca lleva datos (`RN-AUTH-93`), solo, como mucho, un
 * `resultado` de la lista cerrada de `api.md §E.4.2`.
 *
 * Tres códigos no pintan mensaje propio, son pura redirección de cliente:
 * - `segundo_factor`: reutiliza `MfaChallengeStep` tal cual (recupera el
 *   desafío con `GET /auth/mfa-challenges`, `api.md §E.5b`, porque el
 *   302 no trae los datos que el `202` de `POST /auth/session` sí trae
 *   en el login local).
 * - `alta_mfa_requerida`: al muro de MFA que ya existe desde 1.3.
 * - `vinculado`: a `/cuenta/seguridad`, "con el aviso de éxito"
 *   (funcional.md §E.4.2) — literal: se navega allí con un banner.
 *
 * El resto son mensajes estáticos de una lista cerrada (`RN-AUTH-93`,
 * ninguno con el detalle: `sin_cuenta` no se desdobla, `acceso_denegado`
 * no dice qué estado tiene la cuenta, `proveedor_ya_vinculado` no nombra
 * al otro usuario). `acceso_denegado` y `cuenta_bloqueada` reutilizan
 * literalmente el mismo mensaje genérico que ya usan `/entrar` y el `401`
 * de `funcional.md §4.7` — no son mensajes nuevos, la propia tabla de
 * `api.md §E.4.2` lo pide así.
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { usePublicAuthScreen } from '../composables/usePublicAuthScreen'
import PublicAuthShell from '../components/PublicAuthShell.vue'
import MfaChallengeStep from '../components/MfaChallengeStep.vue'

const t = useT()
const route = useRoute()
const router = useRouter()
const { branding } = usePublicAuthScreen()

type StaticOutcome =
  | 'sin_cuenta'
  | 'cuenta_bloqueada'
  | 'acceso_denegado'
  | 'ya_vinculado'
  | 'proveedor_ya_vinculado'
  | 'cancelado'
  | 'estado_no_valido'
  | 'error_proveedor'

const outcome = computed<string | null>(() => {
  const raw = route.query.resultado
  return typeof raw === 'string' ? raw : null
})

const redirecting = ref(false)

onMounted(async () => {
  if (outcome.value === 'vinculado') {
    redirecting.value = true
    // funcional.md §E.4.2: "vuelve a /cuenta/seguridad con el aviso de
    // éxito" — `linked` es lo que AccountSecurityView lee para pintar el
    // banner, mismo patrón que `activated`/`reset` en LoginView.
    await router.replace({ name: 'mfa-security', query: { linked: '' } })
    return
  }

  if (outcome.value === 'alta_mfa_requerida') {
    redirecting.value = true
    await router.replace({ name: 'mfa-enrollment-wall' })
  }
})

const showChallenge = computed(() => outcome.value === 'segundo_factor')

// funcional.md §C.11 (heredado por este componente): sin paso 1 al que
// volver, así que ante un desafío perdido se ofrece un mensaje y un
// enlace a /entrar, en vez de intentar reconstruir un estado que esta
// pantalla nunca tuvo.
const challengeLostMessage = ref<string | null>(null)

function onChallengeLost(message: string) {
  challengeLostMessage.value = message
}

const staticMessage = computed<string | null>(() => {
  switch (outcome.value as StaticOutcome | null) {
    case 'sin_cuenta':
      return t('auth.oauthCallback.sinCuenta')
    case 'cuenta_bloqueada':
      return t('auth.common.accountLocked')
    case 'acceso_denegado':
      return t('auth.login.invalidCredentials')
    case 'ya_vinculado':
      return t('auth.oauthCallback.yaVinculado')
    case 'proveedor_ya_vinculado':
      return t('auth.oauthCallback.proveedorYaVinculado')
    case 'cancelado':
      return t('auth.oauthCallback.cancelado')
    case 'estado_no_valido':
      return t('auth.oauthCallback.estadoNoValido')
    case 'error_proveedor':
      return t('auth.oauthCallback.errorProveedor')
    default:
      return null
  }
})

// `ya_vinculado`/`proveedor_ya_vinculado` solo pueden venir de
// `intent = 'link'`, arrancado con sesión ya autenticada desde
// `/cuenta/seguridad` — sesión que sigue siéndolo al volver del
// *callback* (funcional.md §E.4.4). El resto del camino estático es
// anónimo y vuelve a `/entrar`.
const backTo = computed(() =>
  outcome.value === 'ya_vinculado' || outcome.value === 'proveedor_ya_vinculado'
    ? { name: 'mfa-security' }
    : { name: 'login' },
)

const backLabel = computed(() =>
  outcome.value === 'ya_vinculado' || outcome.value === 'proveedor_ya_vinculado'
    ? t('auth.oauthCallback.backToSecurity')
    : t('auth.oauthCallback.backToLogin'),
)
</script>

<template>
  <PublicAuthShell :branding="branding">
    <template v-if="redirecting">
      <p class="text-muted-foreground text-sm">{{ t('auth.oauthCallback.redirecting') }}</p>
    </template>

    <template v-else-if="showChallenge && !challengeLostMessage">
      <MfaChallengeStep @lost="onChallengeLost" />
    </template>

    <template v-else>
      <h1 class="mb-4 text-lg font-semibold">{{ t('auth.oauthCallback.title') }}</h1>

      <p role="alert" class="text-sm">
        {{ challengeLostMessage ?? staticMessage ?? t('auth.common.unexpectedError') }}
      </p>

      <RouterLink
        v-if="!challengeLostMessage"
        :to="backTo"
        class="text-primary mt-4 inline-block text-sm hover:underline"
      >
        {{ backLabel }}
      </RouterLink>
      <RouterLink
        v-else
        :to="{ name: 'login' }"
        class="text-primary mt-4 inline-block text-sm hover:underline"
      >
        {{ t('auth.oauthCallback.backToLogin') }}
      </RouterLink>
    </template>
  </PublicAuthShell>
</template>
