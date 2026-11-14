<script setup lang="ts">
import type { CheckboxRootEmits, CheckboxRootProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { CheckIcon } from '@lucide/vue'
import { reactiveOmit } from '@vueuse/core'
import { CheckboxIndicator, CheckboxRoot, useForwardPropsEmits } from 'reka-ui'
import { cn } from '@/lib/utils'

const props = defineProps<CheckboxRootProps & { class?: HTMLAttributes['class'] }>()
const emits = defineEmits<CheckboxRootEmits>()

const delegatedProps = reactiveOmit(props, 'class')

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <CheckboxRoot
    v-slot="slotProps"
    data-slot="checkbox"
    v-bind="forwarded"
    :class="
      cn(
        // Mismo criterio que `RadioGroupItem` (`RN-DS-16`/`RN-DS-18`): borde ≥ 3:1 contra el fondo en estado marcado
        // (`border-primary-on-background`) y relleno con `text-primary-foreground`. La zona de contacto invisible
        // (`after:`) sube a 44×44 px en punteros gruesos sin cambiar el escritorio.
        'peer border-input dark:bg-input/30 data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=checked]:border-primary-on-background focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive relative flex size-4 shrink-0 items-center justify-center rounded-sm border outline-none focus-visible:ring-3 aria-invalid:ring-3 after:absolute after:-inset-x-3 after:-inset-y-2 [@media(any-pointer:coarse)]:after:-inset-x-[14px] [@media(any-pointer:coarse)]:after:-inset-y-[14px] disabled:cursor-not-allowed disabled:opacity-50',
        props.class,
      )
    "
  >
    <CheckboxIndicator
      data-slot="checkbox-indicator"
      class="flex items-center justify-center text-current"
    >
      <slot v-bind="slotProps">
        <CheckIcon class="size-3.5" />
      </slot>
    </CheckboxIndicator>
  </CheckboxRoot>
</template>
