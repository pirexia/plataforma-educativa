<script setup lang="ts">
/**
 * `/conserjeria/alumnos/:studentPersonPublicId` (`docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.2`, `REQ-PRL-004`;
 * `1.14b-c2` T1 lectura y T2: salida, entrada fuera de horario y alerta). Lista de puerta de **un** alumno: al entrar, **una** petición
 * `GET /door/students/{…}/pickup-list` (la acción explícita de abrir la lista, UI-2; auditada con finalidad `puerta` y
 * con cupo, `DP-11`). Sin refresco automático ni sondeo; «Volver a cargar la lista» es una lectura nueva a petición.
 *
 * Cabecera (`OPEN-FAM-UNIT-58` A): el nombre y el código de unidad vienen solo de la memoria del módulo
 * (`useDoorContext`); por URL directa o recarga, la cabecera dice solo «Lista de recogida». `document.title` es fijo
 * (`titleKey`) y el nombre no va a la URL, a `history.state` ni al almacenamiento (UI-3, `CA-PRL-016`, `CA-PRL-025`).
 * Estados: carga, vacío, error, `404` (alumno), `403` y `429` (mensaje del servidor; nada se reintenta solo).
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { i18n, useT } from '@/i18n'
import { CircleCheck, TriangleAlert } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import ActionNotices from '../components/ActionNotices.vue'
import AlertActions from '../components/AlertActions.vue'
import AlertOpenDialog from '../components/AlertOpenDialog.vue'
import AlertProtocolPanel from '../components/AlertProtocolPanel.vue'
import DoorPersonCard from '../components/DoorPersonCard.vue'
import MovementDialog from '../components/MovementDialog.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { getDoorPickupList, getPickupAlert } from '../api'
import { mergeAlert } from '../composables/mergeAlert'
import { describeFailure } from '../composables/submitError'
import { useDoorContext } from '../composables/useDoorContext'
import { usePermissions } from '../composables/usePermissions'
import type { DoorPickupList, PickupAlert, PickupListEntry, StudentMovement } from '../types'

const t = useT()
const route = useRoute()
const door = useDoorContext()
const { can } = usePermissions()

const studentPerson = computed(() => String(route.params.studentPersonPublicId ?? ''))
const known = computed(() =>
  error.value?.kind === 'not-found' || error.value?.kind === 'forbidden'
    ? null
    : door.find(studentPerson.value),
)

const list = ref<DoorPickupList | null>(null)
const loading = ref(false)
const error = ref<ShellErrorState | null>(null)

/** Se incrementa con cada lista cargada: las tarjetas se montan de nuevo y la imagen se reintenta (UI-4). */
const version = ref(0)
/** Personas cuya imagen emitió `error` (tarjeta o diálogo): en el diálogo de salida, solo «Por el documento». */
const failedPhotos = ref<string[]>([])

const movementOpen = ref(false)
const movementMode = ref<'salida' | 'entrada'>('salida')
const selected = ref<PickupListEntry | null>(null)
const alertDialogOpen = ref(false)
const raisedAlert = ref<PickupAlert | null>(null)
const raisedAnnounce = ref(false)
const notice = ref<string | null>(null)
const actionError = ref<string | null>(null)

let sequence = 0

async function load(): Promise<void> {
  const id = ++sequence
  const target = studentPerson.value

  loading.value = true
  error.value = null

  try {
    const response = await getDoorPickupList(target)

    if (id !== sequence) return

    list.value = response
    version.value += 1
    failedPhotos.value = []
  } catch (err) {
    if (id !== sequence) return

    list.value = null
    error.value = resolveErrorState(err) ?? { kind: 'unexpected' }
  } finally {
    if (id === sequence) {
      loading.value = false
    }
  }
}

// Una sola lectura al entrar (inmediata); otra solo si el alumno de la ruta cambia con el componente reutilizado.
watch(studentPerson, load, { immediate: true })

onBeforeUnmount(() => {
  sequence += 1
})

/** `403` y `404` con el texto propio de §23.5.2 y sin «Reintentar» (el servidor ya decidió, UI-1). */
function openExit(entry: PickupListEntry): void {
  selected.value = entry
  movementMode.value = 'salida'
  movementOpen.value = true
}

function openEntry(): void {
  selected.value = null
  movementMode.value = 'entrada'
  movementOpen.value = true
}

function onPhotoError(person: string): void {
  if (!failedPhotos.value.includes(person)) {
    failedPhotos.value = [...failedPhotos.value, person]
  }
}

/** Aviso persistente *polite* de §23.5.3: con o sin nombre del alumno (`CA-PRL-025`); la hora es la de `occurred_at`. */
function onRecorded(movement: StudentMovement): void {
  const time = formatTime(movement.occurred_at)

  actionError.value = null

  if (movement.direction === 'entrada') {
    notice.value = t('prl.movement.entryRecorded', { time })

    return
  }

  const person = movement.picked_up_by?.display_name ?? selected.value?.display_name ?? ''

  notice.value = known.value
    ? t('prl.movement.recorded', { student: known.value.displayName, person, time })
    : t('prl.movement.recordedNoStudent', { person, time })
}

function onRaised(alert: PickupAlert): void {
  notice.value = null
  actionError.value = null
  raisedAlert.value = alert
  raisedAnnounce.value = true
}

function onAlertUpdated(updated: PickupAlert): void {
  raisedAnnounce.value = false
  raisedAlert.value = mergeAlert(raisedAlert.value, updated)
}

/** `409 alert.closed` ⇒ el usuario pide recargar la alerta (lectura explícita; abierta, escribe `read`, `DP-11`). */
async function reloadAlert(): Promise<void> {
  if (raisedAlert.value === null) return

  actionError.value = null

  try {
    raisedAlert.value = await getPickupAlert(raisedAlert.value.public_id)
    raisedAnnounce.value = false
  } catch (err) {
    actionError.value = describeFailure(err).message ?? t('prl.errors.unexpected')
  }
}

const raisedIsOpen = computed(() => raisedAlert.value?.status === 'abierta')

const finalMessage = computed<string | null>(() => {
  if (error.value?.kind === 'not-found') {
    return t('prl.states.studentNotFound')
  }

  if (error.value?.kind === 'forbidden') {
    return t('prl.states.forbidden')
  }

  return null
})

function formatTime(value: string): string {
  const date = new Date(value)

  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(i18n.global.locale.value, { timeStyle: 'short' }).format(date)
}
</script>

<template>
  <div class="mx-auto flex max-w-4xl flex-col gap-4 px-4 py-6">
    <header class="flex flex-col gap-1">
      <h1 class="text-lg font-semibold">
        {{ known ? known.displayName : t('prl.door.studentTitle') }}
      </h1>
      <p v-if="known && known.familyUnitCode" class="text-muted-foreground text-sm">
        {{ t('prl.search.unitCode', { code: known.familyUnitCode }) }}
      </p>
      <p v-if="list" class="text-muted-foreground text-sm">
        {{ t('prl.door.asOf', { time: formatTime(list.meta.as_of) }) }}
      </p>
    </header>

    <p class="text-muted-foreground text-sm">
      {{ t('prl.door.auditNotice') }}
    </p>

    <div>
      <Button type="button" variant="outline" class="min-h-11" :disabled="loading" @click="load">
        {{ t('prl.door.reload') }}
      </Button>
    </div>

    <ActionNotices :message="notice" :action-error="actionError" />

    <section
      v-if="raisedAlert"
      aria-labelledby="prl-door-raised-alert"
      class="border-destructive flex flex-col gap-4 rounded-lg border p-4"
    >
      <div class="flex flex-wrap items-center gap-3">
        <h2 id="prl-door-raised-alert" class="text-base font-semibold">
          {{ t('prl.alert.title') }}
        </h2>
        <StatusBadge
          :text="t(`prl.alert.status.${raisedAlert.status}`)"
          :icon="raisedIsOpen ? TriangleAlert : CircleCheck"
          :variant="raisedIsOpen ? 'destructive' : 'secondary'"
        />
      </div>

      <template v-if="raisedIsOpen">
        <AlertProtocolPanel
          :alert="raisedAlert"
          :announce="raisedAnnounce"
          :show-call-management="!can('alerta_entrega.actualizar')"
        />
        <AlertActions
          v-if="can('alerta_entrega.actualizar')"
          :alert="raisedAlert"
          @updated="onAlertUpdated"
          @reload="reloadAlert"
        />
      </template>
    </section>

    <LoadingState v-if="loading" />

    <p v-else-if="finalMessage" role="alert" class="text-sm">{{ finalMessage }}</p>
    <ErrorState v-else-if="error" :state="error" @retry="load" />

    <template v-else-if="list">
      <p v-if="list.data.length === 0" role="status" class="text-sm font-medium">
        {{ t('prl.door.empty') }}
      </p>

      <ul v-else class="flex flex-col gap-3">
        <DoorPersonCard
          v-for="entry in list.data"
          :key="`${entry.person}-${version}`"
          :entry="entry"
          :can-deliver="can('entrega_menor.crear')"
          @reload="load"
          @deliver="openExit"
          @photo-error="onPhotoError"
        />
      </ul>

      <p class="text-sm font-medium">{{ t('prl.door.onlyListed') }}</p>

      <div class="flex flex-wrap gap-3">
        <Button
          v-if="can('alerta_entrega.crear')"
          type="button"
          variant="destructive"
          class="min-h-11"
          @click="alertDialogOpen = true"
        >
          {{ t('prl.door.notListed') }}
        </Button>
        <Button
          v-if="can('entrega_menor.crear')"
          type="button"
          variant="secondary"
          class="min-h-11"
          @click="openEntry"
        >
          {{ t('prl.door.entryOffSchedule') }}
        </Button>
      </div>
    </template>

    <MovementDialog
      v-model:open="movementOpen"
      :mode="movementMode"
      :student-person="studentPerson"
      :entry="selected"
      :photo-failed="selected !== null && failedPhotos.includes(selected.person)"
      :can-alert="can('alerta_entrega.crear')"
      @recorded="onRecorded"
      @photo-error="onPhotoError"
      @reload="load"
      @open-alert="alertDialogOpen = true"
    />

    <AlertOpenDialog
      v-model:open="alertDialogOpen"
      :student-person="studentPerson"
      @raised="onRaised"
    />
  </div>
</template>
