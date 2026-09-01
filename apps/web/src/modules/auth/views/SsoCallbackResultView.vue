<script setup lang="ts">
/**
 * `/entrar/sso` (REQ-AUTH-004, 1.4b, funcional.md §F.9, api.md §F.7).
 * Paralela de `/entrar/google`: destino del `302` del *callback*
 * institucional cuando no hay redirección directa a la raíz. Mismos
 * *códigos* que `GoogleCallbackResultView.vue` (herencia literal,
 * `api.md §F.7.1`) más dos propios de 1.4b: `dominio_no_permitido` y
 * `proveedor_no_disponible`.
 *
 * El *texto* de los seis códigos compartidos con Google (`sin_cuenta`,
 * `ya_vinculado`, `proveedor_ya_vinculado`, `cancelado`,
 * `estado_no_valido`, `error_proveedor`) **no** se hereda de
 * `auth.oauthCallback`: esas claves mencionan "Google" literalmente, y
 * aquí el usuario entró por el proveedor de su centro. Usa el juego
 * neutro `auth.ssoCallback` (issue #148).
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
  | 'dominio_no_permitido'
  | 'proveedor_no_disponible'
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
    await router.replace({ name: 'mfa-security', query: { linked: '' } })
    return
  }

  if (outcome.value === 'alta_mfa_requerida') {
    redirecting.value = true
    await router.replace({ name: 'mfa-enrollment-wall' })
  }
})

const showChallenge = computed(() => outcome.value === 'segundo_factor')

const challengeLostMessage = ref<string | null>(null)

function onChallengeLost(message: string) {
  challengeLostMessage.value = message
}

const staticMessage = computed<string | null>(() => {
  switch (outcome.value as StaticOutcome | null) {
    case 'sin_cuenta':
      return t('auth.ssoCallback.sinCuenta')
    case 'dominio_no_permitido':
      return t('auth.oauthCallback.dominioNoPermitido')
    case 'proveedor_no_disponible':
      return t('auth.oauthCallback.proveedorNoDisponible')
    case 'cuenta_bloqueada':
      return t('auth.common.accountLocked')
    case 'acceso_denegado':
      return t('auth.login.invalidCredentials')
    case 'ya_vinculado':
      return t('auth.ssoCallback.yaVinculado')
    case 'proveedor_ya_vinculado':
      return t('auth.ssoCallback.proveedorYaVinculado')
    case 'cancelado':
      return t('auth.ssoCallback.cancelado')
    case 'estado_no_valido':
      return t('auth.ssoCallback.estadoNoValido')
    case 'error_proveedor':
      return t('auth.ssoCallback.errorProveedor')
    default:
      return null
  }
})

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
      <h1 class="mb-4 text-lg font-semibold">{{ t('auth.oauthCallback.ssoTitle') }}</h1>

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
