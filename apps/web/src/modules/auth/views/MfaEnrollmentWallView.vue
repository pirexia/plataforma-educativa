<script setup lang="ts">
/**
 * `/cuenta/seguridad/obligatorio` (funcional.md §C.4.9/§C.11, api.md
 * §C.1.1). El muro de la sesión restringida: mismo panel de alta que
 * `/cuenta/seguridad`, **sin ninguna navegación** — el requisito es
 * literal, "una pantalla de la que no se puede salir sin completar el
 * registro" — y con "cerrar sesión" siempre visible, porque un muro sin
 * salida es un secuestro, no un control (`funcional.md §C.4.9` punto 2).
 *
 * A este muro se llega de dos formas: navegando aquí directamente (login
 * con gracia vencida, `CA-AUTH-129`) o redirigido por `src/api/client.ts`
 * al recibir `403 urn:pge:error:mfa-enrollment-required` desde cualquier
 * otro sitio de la SPA.
 *
 * 1.3b (`funcional.md §D.9`): con el correo como segundo método posible,
 * el muro ofrece un selector cuando el tenant admite más de uno —el mismo
 * criterio de "grupo de radios etiquetado" que el paso 2 del login, no
 * botones sueltos.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { getMfaStatus, logout } from '../api'
import { apiErrorStatus } from '../composables/formErrors'
import MfaEmailEnrollment from '../components/MfaEmailEnrollment.vue'
import MfaTotpEnrollment from '../components/MfaTotpEnrollment.vue'
import type { MfaMethod } from '../types'

const t = useT()
const router = useRouter()

const checkingSession = ref(true)
const errorMessage = ref<string | null>(null)
const completed = ref(false)
const allowedMethods = ref<MfaMethod[]>(['totp'])
const selectedMethod = ref<Extract<MfaMethod, 'totp' | 'email'>>('totp')

// El muro solo ofrece los dos métodos que hoy tienen alta de verdad
// (funcional.md §D.1.2: `sms` sigue cerrado, sin proveedor).
const enrollableMethods = computed(() =>
  allowedMethods.value.filter(
    (method): method is 'totp' | 'email' => method === 'totp' || method === 'email',
  ),
)

onMounted(async () => {
  try {
    const status = await getMfaStatus()

    // Cumple de sobra (o nunca estuvo obligado): el muro no es para él.
    if (!status.mfa.enforced) {
      await router.push({ name: status.mfa.obligated ? 'mfa-security' : 'home' })
      return
    }

    allowedMethods.value = status.allowed_methods
    selectedMethod.value = enrollableMethods.value.includes('totp')
      ? 'totp'
      : (enrollableMethods.value[0] ?? 'totp')
  } catch (err) {
    if (apiErrorStatus(err) === 401) {
      await router.push({ name: 'login' })
      return
    }
    errorMessage.value = t('auth.common.unexpectedError')
  } finally {
    checkingSession.value = false
  }
})

function onEnrolled(): void {
  completed.value = true
}

async function continueToApp(): Promise<void> {
  await router.push({ name: 'home' })
}

async function logoutFromWall(): Promise<void> {
  await logout().catch(() => {})
  await router.push({ name: 'login' })
}
</script>

<template>
  <div v-if="!checkingSession" class="mx-auto flex min-h-svh max-w-md flex-col px-4 py-10">
    <div class="border-border bg-background w-full rounded-xl border p-6 shadow-sm">
      <h1 class="mb-1 text-lg font-semibold">{{ t('auth.mfa.wall.title') }}</h1>
      <p class="text-muted-foreground mb-4 text-sm">{{ t('auth.mfa.wall.reason') }}</p>

      <p v-if="errorMessage" role="alert" class="text-destructive mb-4 text-sm">
        {{ errorMessage }}
      </p>

      <div v-if="completed" class="flex flex-col gap-4">
        <p role="status" class="text-sm">{{ t('auth.mfa.wall.completed') }}</p>
        <Button type="button" @click="continueToApp">{{ t('auth.mfa.wall.continue') }}</Button>
      </div>

      <template v-else>
        <RadioGroup
          v-if="enrollableMethods.length > 1"
          v-model="selectedMethod"
          class="mb-4"
          :aria-label="t('auth.mfa.wall.methodSelectorLabel')"
        >
          <div v-for="method in enrollableMethods" :key="method" class="flex items-center gap-2">
            <RadioGroupItem :id="`wall-method-${method}`" :value="method" />
            <Label :for="`wall-method-${method}`">{{ t(`auth.mfa.method.${method}`) }}</Label>
          </div>
        </RadioGroup>

        <MfaTotpEnrollment v-if="selectedMethod === 'totp'" @enrolled="onEnrolled" />
        <MfaEmailEnrollment v-else-if="selectedMethod === 'email'" @enrolled="onEnrolled" />
      </template>

      <div class="border-border mt-6 flex justify-end border-t pt-4">
        <Button type="button" variant="outline" size="sm" @click="logoutFromWall">
          {{ t('auth.mfa.wall.logout') }}
        </Button>
      </div>
    </div>
  </div>
</template>
