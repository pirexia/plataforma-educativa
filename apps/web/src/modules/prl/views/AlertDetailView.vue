<script setup lang="ts">
/**
 * `/conserjeria/alertas/:publicId` (`docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.4`, `§23.5.3`, `§23.6`;
 * `1.14b-c2` T1, **lectura**: sin registrar la llamada ni cerrar). `GET /pickup-alerts/{publicId}` bajo demanda: al
 * abrir el detalle (UI-2). Abierta: protocolo (texto del servidor, tal cual, en una región con título) y teléfonos del
 * tutor principal y del contacto de emergencia como enlaces `tel:` (`null` ⇒ «No disponible», sin explicar por qué:
 * puede ser una revocación, `DP-5`); esa lectura escribe `read` y gasta cupo (`DP-11`), así que lleva el aviso de
 * registro. Cerrada: resolución, notas, quién y cuándo, sin teléfonos ni protocolo. WCAG 3.1.2: el protocolo y los
 * textos [NT] llevan `lang="es-ES"` cuando la interfaz no está en español. Estados: carga, error, `404`, `403`, `429`.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { CircleCheck, TriangleAlert } from '@lucide/vue'
import { useT } from '@/i18n'
import { useDataTableFormatters } from '@/data-table'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { getPickupAlert } from '../api'
import { useDoorContext } from '../composables/useDoorContext'
import { useNtLang } from '../composables/useNtLang'
import { usePermissions } from '../composables/usePermissions'
import type { PickupAlert } from '../types'

const t = useT()
const route = useRoute()
const ntLang = useNtLang()
const door = useDoorContext()
const { can } = usePermissions()
const { formatDateTime } = useDataTableFormatters()

const publicId = computed(() => String(route.params.publicId ?? ''))

const alert = ref<PickupAlert | null>(null)
const loading = ref(false)
const error = ref<ShellErrorState | null>(null)

let sequence = 0

async function load(): Promise<void> {
  const id = ++sequence
  const target = publicId.value

  loading.value = true
  error.value = null

  try {
    const response = await getPickupAlert(target)

    if (id !== sequence) return

    alert.value = response
  } catch (err) {
    if (id !== sequence) return

    alert.value = null
    error.value = resolveErrorState(err) ?? { kind: 'unexpected' }
  } finally {
    if (id === sequence) {
      loading.value = false
    }
  }
}

// Una sola lectura al abrir el detalle; otra solo si la alerta de la ruta cambia con el componente reutilizado.
watch(publicId, load, { immediate: true })

onBeforeUnmount(() => {
  sequence += 1
})

const finalMessage = computed<string | null>(() => {
  if (error.value?.kind === 'not-found') {
    return t('prl.states.notFound')
  }

  if (error.value?.kind === 'forbidden') {
    return t('prl.states.forbidden')
  }

  return null
})

const isOpen = computed(() => alert.value?.status === 'abierta')

const studentName = computed(() =>
  alert.value === null
    ? ''
    : (door.find(alert.value.student_person)?.displayName ?? t('prl.alerts.studentUnknown')),
)

const resolutionText = computed(() => {
  const resolution = alert.value?.resolution

  if (resolution === null || resolution === undefined) return null

  const key = `prl.resolution.${resolution}`
  const text = t(key)

  return text === key ? resolution : text
})

const phones = computed(() => [
  {
    id: 'principal',
    label: t('prl.alert.phones.principal'),
    number: alert.value?.contacts?.principal_phone ?? null,
  },
  {
    id: 'emergency',
    label: t('prl.alert.phones.emergency'),
    number: alert.value?.contacts?.emergency_phone ?? null,
  },
])
</script>

<template>
  <div class="mx-auto flex max-w-3xl flex-col gap-4 px-4 py-6">
    <h1 class="text-lg font-semibold">{{ t('prl.alert.title') }}</h1>

    <LoadingState v-if="loading" />

    <p v-else-if="finalMessage" role="alert" class="text-sm">{{ finalMessage }}</p>
    <ErrorState v-else-if="error" :state="error" @retry="load" />

    <template v-else-if="alert">
      <div class="flex flex-wrap items-center gap-3">
        <StatusBadge
          :text="t(`prl.alert.status.${alert.status}`)"
          :icon="isOpen ? TriangleAlert : CircleCheck"
          :variant="isOpen ? 'destructive' : 'secondary'"
        />
      </div>

      <p v-if="isOpen" :lang="ntLang" class="text-muted-foreground text-sm">
        {{ t('prl.door.auditNotice') }}
      </p>

      <section
        v-if="isOpen && alert.protocol"
        aria-labelledby="prl-alert-protocol"
        class="border-border bg-muted flex flex-col gap-2 rounded-lg border p-4"
      >
        <h2 id="prl-alert-protocol" :lang="ntLang" class="text-base font-semibold">
          {{ t('prl.alert.protocolTitle') }}
        </h2>
        <p :lang="ntLang" class="whitespace-pre-line text-sm">{{ alert.protocol }}</p>
      </section>

      <section v-if="isOpen" aria-labelledby="prl-alert-phones" class="flex flex-col gap-2">
        <h2 id="prl-alert-phones" class="text-base font-semibold">
          {{ t('prl.person.phone') }}
        </h2>
        <dl class="flex flex-col gap-1 text-sm">
          <div v-for="phone in phones" :key="phone.id" class="flex flex-wrap gap-x-2">
            <dt class="text-muted-foreground">{{ phone.label }}</dt>
            <dd>
              <a
                v-if="phone.number"
                :href="`tel:${phone.number}`"
                class="text-primary-on-background inline-flex min-h-11 items-center underline underline-offset-4"
              >
                {{ phone.number }}
              </a>
              <span v-else>{{ t('prl.alert.phones.unavailable') }}</span>
            </dd>
          </div>
        </dl>
        <p v-if="!can('alerta_entrega.actualizar')" :lang="ntLang" class="text-sm font-medium">
          {{ t('prl.alert.callManagement') }}
        </p>
      </section>

      <dl class="flex flex-col gap-2 text-sm">
        <div class="flex flex-wrap gap-x-2">
          <dt class="text-muted-foreground">{{ t('prl.alerts.columns.student') }}</dt>
          <dd>{{ studentName }}</dd>
        </div>
        <div class="flex flex-wrap gap-x-2">
          <dt class="text-muted-foreground">{{ t('prl.alert.presenterLabel') }}</dt>
          <dd class="whitespace-pre-line">{{ alert.presenter_description }}</dd>
        </div>
        <div class="flex flex-wrap gap-x-2">
          <dt class="text-muted-foreground">{{ t('prl.alerts.columns.raisedBy') }}</dt>
          <dd class="font-mono text-xs">{{ alert.raised_by }}</dd>
        </div>
        <div class="flex flex-wrap gap-x-2">
          <dt class="text-muted-foreground">{{ t('prl.alerts.columns.raisedAt') }}</dt>
          <dd>{{ formatDateTime(alert.raised_at) }}</dd>
        </div>
        <div v-if="alert.principal_contacted_at" class="flex flex-wrap gap-x-2">
          <dt class="text-muted-foreground">{{ t('prl.alert.principalContactedAt') }}</dt>
          <dd>{{ formatDateTime(alert.principal_contacted_at) }}</dd>
        </div>
        <template v-if="!isOpen">
          <div v-if="resolutionText" class="flex flex-wrap gap-x-2">
            <dt class="text-muted-foreground">{{ t('prl.alert.resolution') }}</dt>
            <dd>{{ resolutionText }}</dd>
          </div>
          <div v-if="alert.resolution_notes" class="flex flex-wrap gap-x-2">
            <dt class="text-muted-foreground">{{ t('prl.alert.resolutionNotes') }}</dt>
            <dd class="whitespace-pre-line">{{ alert.resolution_notes }}</dd>
          </div>
          <div v-if="alert.resolved_by" class="flex flex-wrap gap-x-2">
            <dt class="text-muted-foreground">{{ t('prl.alert.resolvedBy') }}</dt>
            <dd class="font-mono text-xs">{{ alert.resolved_by }}</dd>
          </div>
          <div v-if="alert.resolved_at" class="flex flex-wrap gap-x-2">
            <dt class="text-muted-foreground">{{ t('prl.alert.resolvedAt') }}</dt>
            <dd>{{ formatDateTime(alert.resolved_at) }}</dd>
          </div>
        </template>
      </dl>
    </template>
  </div>
</template>
