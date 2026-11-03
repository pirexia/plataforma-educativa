<script setup lang="ts">
/**
 * `/conserjeria/alumnos/:studentPersonPublicId` (`docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.2`, `REQ-PRL-004`;
 * `1.14b-c2` T1, **solo lectura**: sin botones de acción). Lista de puerta de **un** alumno: al entrar, **una** petición
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
import { Button } from '@/components/ui/button'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import DoorPersonCard from '../components/DoorPersonCard.vue'
import { getDoorPickupList } from '../api'
import { useDoorContext } from '../composables/useDoorContext'
import { useNtLang } from '../composables/useNtLang'
import type { DoorPickupList } from '../types'

const t = useT()
const route = useRoute()
const ntLang = useNtLang()
const door = useDoorContext()

const studentPerson = computed(() => String(route.params.studentPersonPublicId ?? ''))
const known = computed(() => door.find(studentPerson.value))

const list = ref<DoorPickupList | null>(null)
const loading = ref(false)
const error = ref<ShellErrorState | null>(null)

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

    <p :lang="ntLang" class="text-muted-foreground text-sm">
      {{ t('prl.door.auditNotice') }}
    </p>

    <div>
      <Button type="button" variant="outline" class="min-h-11" :disabled="loading" @click="load">
        {{ t('prl.door.reload') }}
      </Button>
    </div>

    <LoadingState v-if="loading" />

    <p v-else-if="finalMessage" role="alert" class="text-sm">{{ finalMessage }}</p>
    <ErrorState v-else-if="error" :state="error" @retry="load" />

    <template v-else-if="list">
      <p v-if="list.data.length === 0" role="status" :lang="ntLang" class="text-sm font-medium">
        {{ t('prl.door.empty') }}
      </p>

      <ul v-else class="flex flex-col gap-3">
        <DoorPersonCard
          v-for="entry in list.data"
          :key="entry.person"
          :entry="entry"
          @reload="load"
        />
      </ul>

      <p :lang="ntLang" class="text-sm font-medium">{{ t('prl.door.onlyListed') }}</p>
    </template>
  </div>
</template>
