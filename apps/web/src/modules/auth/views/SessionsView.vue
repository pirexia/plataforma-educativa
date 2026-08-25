<script setup lang="ts">
/**
 * `/cuenta/sesiones` (funcional.md §B.11, api.md §B.2-§B.4). Con sesión,
 * sin `AppLayout` — misma categoría que `/cuenta/contrasena`
 * (`PasswordChangeView.vue`): formulario/panel aislado, sin depender del
 * *layout* de 1.8 ni del *design system* de 1.7. Sin sesión, redirige a
 * `/entrar`.
 *
 * REQ-AUTH-005 puntos 2-3: listado de sesiones activas y cierre remoto,
 * individual y masivo (`scope=others`). Sin *branding* de tenant
 * (funcional.md §B.11: "el branding de las pantallas con sesión es
 * asunto de 1.7/1.8, no de este paso").
 *
 * RN-AUTH-28: ningún dato de sesión en `localStorage`/`sessionStorage` —
 * todo el estado vive en variables reactivas de este componente.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Button } from '@/components/ui/button'
import { getMe } from '@/modules/core/api'
import { listSessions, revokeOtherSessions, revokeSession, type UserSessionSummary } from '../api'
import { apiErrorStatus, retryAfterSeconds } from '../composables/formErrors'

const { t, locale } = useI18n()
const router = useRouter()

const checkingSession = ref(true)
const loading = ref(true)
const sessions = ref<UserSessionSummary[]>([])
const errorMessage = ref<string | null>(null)
const statusMessage = ref<string | null>(null)

/** public_id de la fila que pide confirmación, o null si ninguna. */
const confirmingId = ref<string | null>(null)
/** public_id de la fila cuya revocación está en curso, o null. */
const revokingId = ref<string | null>(null)

const confirmingOthers = ref(false)
const revokingOthers = ref(false)

const hasOtherSessions = computed(() => sessions.value.some((s) => !s.current))

const dateFormatter = computed(
  () => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }),
)

function formatDate(value: string): string {
  return dateFormatter.value.format(new Date(value))
}

function deviceLabel(session: UserSessionSummary): string {
  const { browser, platform } = session.client
  return `${browser} · ${platform}`
}

function deviceTypeLabel(type: UserSessionSummary['client']['device_type']): string {
  return t(`auth.sessions.deviceType.${type}`)
}

async function loadSessions(): Promise<void> {
  loading.value = true
  errorMessage.value = null

  try {
    const page = await listSessions()
    sessions.value = page.data
  } catch (err) {
    if (apiErrorStatus(err) === 401) {
      await router.push({ name: 'login' })
      return
    }
    errorMessage.value = t('auth.common.unexpectedError')
  } finally {
    loading.value = false
  }
}

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

  if (!checkingSession.value) {
    await loadSessions()
  }
})

function askRevoke(session: UserSessionSummary): void {
  errorMessage.value = null
  statusMessage.value = null
  confirmingOthers.value = false
  confirmingId.value = session.public_id
}

function cancelRevoke(): void {
  confirmingId.value = null
}

async function confirmRevoke(session: UserSessionSummary): Promise<void> {
  revokingId.value = session.public_id
  errorMessage.value = null

  try {
    await revokeSession(session.public_id)

    if (session.current) {
      await router.push({ name: 'login' })
      return
    }

    statusMessage.value = t('auth.sessions.revokedSuccess')
    confirmingId.value = null
    await loadSessions()
  } catch (err) {
    const status = apiErrorStatus(err)

    if (status === 401) {
      await router.push({ name: 'login' })
      return
    }

    if (status === 404 || status === 409) {
      // Ya no existe o ya estaba cerrada: el listado la habrá quitado o
      // la quitará en el siguiente refresco — no es un error del usuario.
      confirmingId.value = null
      await loadSessions()
      return
    }

    if (status === 429) {
      const seconds = retryAfterSeconds(err)
      errorMessage.value =
        seconds !== null
          ? t('auth.common.tooManyRequestsWithSeconds', { seconds })
          : t('auth.common.tooManyRequests')
    } else {
      errorMessage.value = t('auth.common.unexpectedError')
    }
  } finally {
    revokingId.value = null
  }
}

function askRevokeOthers(): void {
  errorMessage.value = null
  statusMessage.value = null
  confirmingId.value = null
  confirmingOthers.value = true
}

function cancelRevokeOthers(): void {
  confirmingOthers.value = false
}

async function confirmRevokeOthers(): Promise<void> {
  revokingOthers.value = true
  errorMessage.value = null

  try {
    await revokeOtherSessions()
    statusMessage.value = t('auth.sessions.revokeOthersSuccess')
    confirmingOthers.value = false
    await loadSessions()
  } catch (err) {
    const status = apiErrorStatus(err)

    if (status === 401) {
      await router.push({ name: 'login' })
      return
    }

    if (status === 429) {
      const seconds = retryAfterSeconds(err)
      errorMessage.value =
        seconds !== null
          ? t('auth.common.tooManyRequestsWithSeconds', { seconds })
          : t('auth.common.tooManyRequests')
    } else {
      errorMessage.value = t('auth.common.unexpectedError')
    }
  } finally {
    revokingOthers.value = false
  }
}
</script>

<template>
  <div v-if="!checkingSession" class="mx-auto flex min-h-svh max-w-3xl flex-col px-4 py-10">
    <div class="border-border bg-background w-full rounded-xl border p-6 shadow-sm">
      <h1 class="mb-1 text-lg font-semibold">{{ t('auth.sessions.title') }}</h1>
      <p class="text-muted-foreground mb-4 text-sm">{{ t('auth.sessions.intro') }}</p>

      <p
        v-if="statusMessage"
        role="status"
        class="border-border bg-muted mb-4 rounded-lg border px-3 py-2 text-sm"
      >
        {{ statusMessage }}
      </p>
      <p v-if="errorMessage" role="alert" class="text-destructive mb-4 text-sm">
        {{ errorMessage }}
      </p>

      <p v-if="loading" class="text-muted-foreground text-sm">{{ t('auth.sessions.loading') }}</p>

      <template v-else>
        <div class="mb-4 flex justify-end">
          <Button
            v-if="!confirmingOthers"
            type="button"
            variant="outline"
            :disabled="!hasOtherSessions"
            @click="askRevokeOthers"
          >
            {{ t('auth.sessions.revokeOthers') }}
          </Button>
          <div v-else class="flex flex-col items-end gap-2">
            <p role="alert" class="text-sm">{{ t('auth.sessions.confirmRevokeOthers') }}</p>
            <div class="flex gap-2">
              <Button
                type="button"
                variant="outline"
                :disabled="revokingOthers"
                @click="cancelRevokeOthers"
              >
                {{ t('auth.sessions.confirmCancel') }}
              </Button>
              <Button
                type="button"
                variant="destructive"
                :disabled="revokingOthers"
                @click="confirmRevokeOthers"
              >
                {{ revokingOthers ? t('auth.sessions.revoking') : t('auth.sessions.confirmYes') }}
              </Button>
            </div>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full border-collapse text-left text-sm">
            <caption class="sr-only">
              {{
                t('auth.sessions.title')
              }}
            </caption>
            <thead>
              <tr class="border-border border-b">
                <th scope="col" class="py-2 pr-3 font-medium">
                  {{ t('auth.sessions.columnDevice') }}
                </th>
                <th scope="col" class="py-2 pr-3 font-medium">
                  {{ t('auth.sessions.columnStarted') }}
                </th>
                <th scope="col" class="py-2 pr-3 font-medium">
                  {{ t('auth.sessions.columnLastActivity') }}
                </th>
                <th scope="col" class="py-2 pr-3 font-medium">{{ t('auth.sessions.columnIp') }}</th>
                <th scope="col" class="py-2 font-medium">{{ t('auth.sessions.columnActions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="session in sessions"
                :key="session.public_id"
                class="border-border border-b last:border-0"
              >
                <td class="py-2 pr-3">
                  <div>{{ deviceLabel(session) }}</div>
                  <div class="text-muted-foreground text-xs">
                    {{ deviceTypeLabel(session.client.device_type) }}
                    <span v-if="session.current">· {{ t('auth.sessions.current') }}</span>
                    <span v-if="!session.device_known"
                      >· {{ t('auth.sessions.deviceUnknownBadge') }}</span
                    >
                  </div>
                </td>
                <td class="py-2 pr-3">{{ formatDate(session.started_at) }}</td>
                <td class="py-2 pr-3">{{ formatDate(session.last_activity_at) }}</td>
                <td class="py-2 pr-3">{{ session.ip_address ?? '—' }}</td>
                <td class="py-2">
                  <template v-if="confirmingId === session.public_id">
                    <div class="flex flex-col items-start gap-2">
                      <p role="alert" class="text-sm">
                        {{
                          session.current
                            ? t('auth.sessions.confirmRevokeCurrent')
                            : t('auth.sessions.confirmRevoke')
                        }}
                      </p>
                      <div class="flex gap-2">
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          :disabled="revokingId === session.public_id"
                          @click="cancelRevoke"
                        >
                          {{ t('auth.sessions.confirmCancel') }}
                        </Button>
                        <Button
                          type="button"
                          variant="destructive"
                          size="sm"
                          :disabled="revokingId === session.public_id"
                          @click="confirmRevoke(session)"
                        >
                          {{
                            revokingId === session.public_id
                              ? t('auth.sessions.revoking')
                              : t('auth.sessions.confirmYes')
                          }}
                        </Button>
                      </div>
                    </div>
                  </template>
                  <Button
                    v-else
                    type="button"
                    variant="outline"
                    size="sm"
                    :aria-label="`${t('auth.sessions.revoke')} — ${deviceLabel(session)}`"
                    @click="askRevoke(session)"
                  >
                    {{ t('auth.sessions.revoke') }}
                  </Button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </div>
</template>
