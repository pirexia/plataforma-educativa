<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §12.6`. `role="alert"`, título +
 * texto + **Reintentar** + referencia `request_id` si la respuesta la
 * trae (`INV-013`). El texto de `module-disabled` es el `detail` del
 * servidor, ya traducido (`RMOD-009`) — no un texto propio del cliente.
 */
import { computed } from 'vue'
import { CircleAlert, WifiOff } from '@lucide/vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import type { ShellErrorState } from '../errorState'

const props = defineProps<{ state: ShellErrorState }>()
const emit = defineEmits<{ retry: [] }>()
const t = useT()

const icon = computed(() => (props.state.kind === 'offline' ? WifiOff : CircleAlert))

const title = computed(() => {
  switch (props.state.kind) {
    case 'offline':
      return t('shell.states.error.offline.title')
    case 'forbidden':
      return t('shell.states.error.forbidden.title')
    case 'module-disabled':
      return t('shell.states.error.moduleDisabled.title')
    case 'not-found':
      return t('shell.states.error.notFound.title')
    case 'too-many-requests':
      return t('shell.states.error.tooManyRequests.title')
    default:
      return t('shell.states.error.unexpected.title')
  }
})

const text = computed(() => {
  switch (props.state.kind) {
    case 'offline':
      return t('shell.states.error.offline.text')
    case 'forbidden':
      return t('shell.states.error.forbidden.text')
    case 'module-disabled':
      return props.state.detail ?? ''
    case 'not-found':
      return t('shell.states.error.notFound.text')
    case 'too-many-requests':
      return props.state.retryAfterSeconds !== undefined
        ? t('shell.states.error.tooManyRequests.text', { seconds: props.state.retryAfterSeconds })
        : t('shell.states.error.tooManyRequests.textUnknown')
    default:
      return t('shell.states.error.unexpected.text')
  }
})
</script>

<template>
  <div role="alert" class="flex flex-col items-center gap-2 py-8 text-center">
    <component :is="icon" class="text-destructive size-8" aria-hidden="true" />
    <p class="text-sm font-medium">{{ title }}</p>
    <p v-if="text" class="text-muted-foreground text-sm">{{ text }}</p>
    <p v-if="state.requestId" class="text-muted-foreground text-xs">
      {{ t('shell.states.error.requestId', { requestId: state.requestId }) }}
    </p>
    <Button type="button" variant="outline" class="mt-2 min-h-11" @click="emit('retry')">
      {{ t('shell.states.error.retry') }}
    </Button>
  </div>
</template>
