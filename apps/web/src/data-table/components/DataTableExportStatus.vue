<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §13.14`, `RN-CORE-46`/`-49`,
 * `CA-CORE-189`/`-190`/`-191`/`-202`, `ADR-054 §7`. Estado de la exportación
 * en curso: «Preparando exportación…» con `role="status"`, enlace de
 * descarga con la caducidad visible (un enlace a la URL firmada: sin
 * `fetch` del fichero, sin `Blob`), errores con `role="alert"`, y el aviso
 * permanente de que, si el usuario sale de la vista, no podrá descargarla
 * desde aquí (`OPEN-CORE-25`: aviso, **sin** diálogo de confirmación ni
 * `beforeunload`).
 */
import { computed } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { useDataTableFormatters } from '../formatters'
import type { ExportFlowState } from '../useExportFlow'

const props = defineProps<{ state: ExportFlowState }>()
const emit = defineEmits<{ 'check-again': []; 'request-again': [] }>()

const t = useT()
const { formatDateTime } = useDataTableFormatters()

/** La exportación sigue en curso en servidor: `pendiente`/`generando` (o la consulta se agotó). */
const inProgress = computed(
  () =>
    props.state.phase === 'requesting' ||
    props.state.phase === 'preparing' ||
    props.state.phase === 'timedOut',
)

const failedMessage = computed(() => {
  if (props.state.phase !== 'failed' || !props.state.errorCode) {
    return t('dataTable.export.failed')
  }

  const key = `dataTable.export.errors.${props.state.errorCode}`
  const message = t(key)

  return message === key ? t('dataTable.export.failed') : message
})
</script>

<template>
  <div
    v-if="props.state.phase !== 'idle'"
    data-slot="data-table-export-status"
    class="flex flex-col gap-1.5 text-sm"
  >
    <p v-if="props.state.phase === 'requesting' || props.state.phase === 'preparing'" role="status">
      {{ t('dataTable.export.preparing') }}
    </p>

    <template v-else-if="props.state.phase === 'timedOut'">
      <p role="status">{{ t('dataTable.export.timedOut') }}</p>
      <div>
        <Button type="button" variant="outline" @click="emit('check-again')">
          {{ t('dataTable.export.checkAgain') }}
        </Button>
      </div>
    </template>

    <p v-else-if="props.state.phase === 'completed'" role="status" class="flex flex-wrap gap-x-3">
      <a
        :href="props.state.url"
        rel="noopener"
        class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm font-medium underline underline-offset-4 outline-none focus-visible:ring-3"
      >
        {{ t('dataTable.export.download') }}
      </a>
      <span v-if="props.state.expiresAt" class="text-muted-foreground">
        {{ t('dataTable.export.expiresAt', { date: formatDateTime(props.state.expiresAt) }) }}
      </span>
    </p>

    <p v-else-if="props.state.phase === 'failed'" role="alert" class="text-destructive">
      {{ failedMessage }}
    </p>

    <template v-else-if="props.state.phase === 'expired'">
      <p role="alert" class="text-destructive">{{ t('dataTable.export.expired') }}</p>
      <div>
        <Button type="button" variant="outline" @click="emit('request-again')">
          {{ t('dataTable.export.requestAgain') }}
        </Button>
      </div>
    </template>

    <p v-else-if="props.state.phase === 'requestError'" role="alert" class="text-destructive">
      {{ props.state.message ?? t('dataTable.export.requestError') }}
    </p>

    <template v-else-if="props.state.phase === 'statusError'">
      <p role="alert" class="text-destructive">{{ t('dataTable.export.statusError') }}</p>
      <div>
        <Button type="button" variant="outline" @click="emit('check-again')">
          {{ t('dataTable.export.checkAgain') }}
        </Button>
      </div>
    </template>

    <p
      v-if="inProgress"
      data-slot="data-table-export-leave-warning"
      class="text-muted-foreground text-xs"
    >
      {{ t('dataTable.export.leaveWarning') }}
    </p>
  </div>
</template>
