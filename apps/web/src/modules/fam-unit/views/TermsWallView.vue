<script setup lang="ts">
/**
 * `/condiciones-de-uso` (`REQ-FAM-UNIT/funcional.md §4.10`, `ADR-067 §5.11`, `CA-FAM-UNIT-076`). El muro de condiciones:
 * un tutor sin aceptación exigible de las condiciones de uso vigentes llega aquí desde el *guard* (`session_walls` de
 * `GET /me`) o desde el cliente HTTP (`403 terms-acceptance-required`), y solo sale aceptándolas o cerrando sesión.
 * `BareLayout`: sin navegación, como el muro de MFA — un muro sin salida es un secuestro, así que «cerrar sesión» siempre
 * está visible.
 *
 * El texto es contenido del centro (`ADR-062`): ya viene resuelto en el idioma del usuario y se pinta como **texto**, nunca
 * como HTML. La API es la que hace cumplir el muro (`INV-010`); esta pantalla solo registra la aceptación y recarga la
 * sesión (`GET /me`) antes de navegar, o el *guard* devolvería al muro con `session_walls` desfasado.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError } from '@/api/client'
import { clearSession, reloadSession } from '@/session/useSession'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { logout } from '@/modules/auth/api'
import { acceptMyTerms, getMyTerms } from '../api'
import type { TermsVersion } from '../types'

const t = useT()
const router = useRouter()

const loading = ref(true)
const accepting = ref(false)
const version = ref<TermsVersion | null>(null)
const errorKey = ref<string | null>(null)

const errorMessage = computed(() => (errorKey.value === null ? null : t(errorKey.value)))

async function leaveWall(): Promise<void> {
  // La sesión se recarga antes de navegar: sin ello el estado seguiría trayendo el muro y el *guard* devolvería aquí.
  await reloadSession()
  await router.push({ name: 'home' })
}

async function load(): Promise<void> {
  loading.value = true

  try {
    const terms = await getMyTerms()

    // Nada pendiente (ya aceptó, o nunca estuvo obligado): el muro no es para él.
    if (!terms.required || terms.version === null) {
      await leaveWall()
      return
    }

    version.value = terms.version
  } catch (err) {
    if (err instanceof ApiError && err.status === 401) {
      await router.push({ name: 'login' })
      return
    }

    errorKey.value = 'famUnit.termsWall.loadError'
  } finally {
    loading.value = false
  }
}

async function accept(): Promise<void> {
  if (version.value === null || accepting.value) {
    return
  }

  accepting.value = true
  errorKey.value = null

  try {
    await acceptMyTerms(version.value.public_id)
    await leaveWall()
  } catch (err) {
    if (err instanceof ApiError && err.status === 409) {
      // `409 version_not_current`: se publicó otra versión mientras leía. Se vuelve a pedir y se vuelve a mostrar.
      await load()
      errorKey.value = 'famUnit.termsWall.outdated'
    } else if (err instanceof ApiError && err.status === 401) {
      await router.push({ name: 'login' })
    } else {
      errorKey.value = 'famUnit.termsWall.acceptError'
    }
  } finally {
    accepting.value = false
  }
}

async function logoutFromWall(): Promise<void> {
  await logout().catch(() => {})
  clearSession()
  await router.push({ name: 'login' })
}

onMounted(load)
</script>

<template>
  <div class="mx-auto flex max-w-2xl flex-col">
    <div class="border-border bg-background w-full rounded-xl border p-6 shadow-sm">
      <h1 class="mb-1 text-lg font-semibold">{{ t('famUnit.termsWall.title') }}</h1>
      <p class="text-muted-foreground mb-4 text-sm">{{ t('famUnit.termsWall.reason') }}</p>

      <p v-if="loading" role="status" class="text-sm">{{ t('famUnit.termsWall.loading') }}</p>

      <p v-if="errorMessage" role="alert" class="text-destructive mb-4 text-sm">
        {{ errorMessage }}
      </p>

      <template v-if="version !== null">
        <h2 class="mb-2 text-base font-medium">{{ version.title }}</h2>
        <!-- Región desplazable alcanzable con el teclado y con nombre (WCAG 2.1.1, 4.1.2). -->
        <div
          class="border-border mb-4 max-h-96 overflow-y-auto rounded-md border p-4 text-sm whitespace-pre-wrap"
          tabindex="0"
          role="region"
          :aria-label="t('famUnit.termsWall.textLabel')"
          data-testid="terms-body"
        >
          {{ version.body }}
        </div>

        <Button type="button" :disabled="accepting" @click="accept">
          {{ t('famUnit.termsWall.accept') }}
        </Button>
      </template>

      <div class="border-border mt-6 flex justify-end border-t pt-4">
        <Button type="button" variant="outline" size="sm" @click="logoutFromWall">
          {{ t('famUnit.termsWall.logout') }}
        </Button>
      </div>
    </div>
  </div>
</template>
