<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.4.1`, `§23.4.2` (fila «Ver lista vigente»), `§23.4.5`. Lista vigente de
 * recogida de **un** alumno, solo bajo demanda: `GET /pickup-lists/{alumno}` escribe `read` y gasta el cupo de 300/h
 * (`RN-FAM-UNIT-92`/`-93`, UI-2), así que se lanza únicamente al pulsar «Ver lista de recogida» (o «Volver a cargar la
 * lista»). Sin refresco automático, sondeo ni *prefetch*. La respuesta, con sus URL firmadas, vive solo en memoria de
 * este componente y se descarta al desmontarlo (UI-3): la sección lo remonta (`:key`) al cambiar de alumno.
 *
 * Estados (`§23.4.5`): inicial (texto y botón, ninguna petición), carga (`aria-busy`, anuncio *polite*), vacío, error con
 * «Reintentar» explícito, `404`, `403` y `429` (mensaje del servidor con su espera; nada se reintenta solo).
 */
import { computed, ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { useDataTableFormatters } from '@/data-table'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { getPickupList } from '../api'
import { useNtLang } from '../composables/useNtLang'
import type { PickupList } from '../types'
import PickupPersonCard from './PickupPersonCard.vue'

const props = defineProps<{ studentPerson: string }>()

const t = useT()
const ntLang = useNtLang()
const { formatDateTime } = useDataTableFormatters()

const list = ref<PickupList | null>(null)
const loading = ref(false)
const error = ref<ShellErrorState | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null

  try {
    list.value = await getPickupList(props.studentPerson)
  } catch (err) {
    list.value = null
    error.value = resolveErrorState(err) ?? { kind: 'unexpected' }
  } finally {
    loading.value = false
  }
}

/** `403` y `404` con el texto propio de §23.4.5 y sin «Reintentar» (el servidor ya decidió, UI-1). */
const finalMessage = computed<string | null>(() => {
  if (error.value?.kind === 'not-found') {
    return t('famUnit.pickup.states.notFound')
  }

  if (error.value?.kind === 'forbidden') {
    return t('famUnit.pickup.states.forbidden')
  }

  return null
})
</script>

<template>
  <div class="flex flex-col gap-3">
    <h3 class="text-sm font-semibold">{{ t('famUnit.pickup.list.title') }}</h3>

    <p :lang="ntLang" class="text-muted-foreground text-xs">
      {{ t('famUnit.pickup.list.auditNotice') }}
    </p>

    <div class="flex flex-wrap items-center gap-3">
      <Button type="button" size="sm" :disabled="loading" @click="load">
        {{ t('famUnit.pickup.list.show') }}
      </Button>
    </div>

    <p v-if="!loading && !error && list === null" class="text-muted-foreground text-sm">
      {{ t('famUnit.pickup.list.initial') }}
    </p>

    <LoadingState v-if="loading" />

    <p v-else-if="finalMessage" role="alert" class="text-sm">{{ finalMessage }}</p>
    <ErrorState v-else-if="error" :state="error" @retry="load" />

    <template v-else-if="list">
      <p v-if="list.data.length === 0" role="status" class="text-sm">
        {{ t('famUnit.pickup.list.empty') }}
      </p>

      <ul v-else class="flex flex-col gap-3">
        <PickupPersonCard
          v-for="entry in list.data"
          :key="entry.person"
          :entry="entry"
          @reload="load"
        />
      </ul>

      <p class="text-muted-foreground text-xs">
        {{
          t('famUnit.pickup.list.asOf', {
            date: formatDateTime(list.meta.as_of) ?? list.meta.as_of,
          })
        }}
      </p>
    </template>
  </div>
</template>
