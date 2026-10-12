<script setup lang="ts">
import type { DialogContentEmits, DialogContentProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { XIcon } from '@lucide/vue'
import { reactiveOmit } from '@vueuse/core'
import {
  DialogClose,
  DialogContent,
  DialogOverlay,
  DialogPortal,
  useForwardPropsEmits,
} from 'reka-ui'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'

interface DialogContentExtraProps extends DialogContentProps {
  class?: HTMLAttributes['class']
  showCloseButton?: boolean
  /**
   * `docs/design-system.md §12.2` punto 3 / `RN-DS-24`: un componente base no
   * tiene texto propio. El botón de cierre es solo icono, así que su nombre
   * accesible llega por esta *prop*, traducida por quien use `DialogContent`.
   */
  closeLabel: string
}

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(defineProps<DialogContentExtraProps>(), {
  showCloseButton: true,
})
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = reactiveOmit(props, 'class', 'showCloseButton', 'closeLabel')

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <DialogPortal>
    <!-- `bg-overlay` en vez de `bg-black/10` del registro: RN-DS-19, docs/design-system.md §12.3 -->
    <DialogOverlay
      data-slot="dialog-overlay"
      class="bg-overlay supports-backdrop-filter:backdrop-blur-xs fixed inset-0 z-50 duration-100 data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0"
    />
    <DialogContent
      data-slot="dialog-content"
      v-bind="{ ...$attrs, ...forwarded }"
      :class="
        cn(
          'bg-popover text-popover-foreground ring-foreground/10 fixed top-1/2 left-1/2 z-50 grid max-h-[calc(100svh-2rem)] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 gap-4 overflow-y-auto rounded-xl p-4 text-sm ring-1 outline-none duration-100 data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95',
          props.class,
        )
      "
    >
      <slot />

      <DialogClose v-if="showCloseButton" data-slot="dialog-close" as-child>
        <Button variant="ghost" class="absolute top-3 right-3 min-h-11 min-w-11" size="icon-sm">
          <XIcon />
          <span class="sr-only">{{ closeLabel }}</span>
        </Button>
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>
