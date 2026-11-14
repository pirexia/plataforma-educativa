<script setup lang="ts">
/**
 * `funcional.md §10` (accesibilidad): los estados `autorizo`/`no_autorizo`/`pendiente` y `activo`/`revocado` **nunca se
 * transmiten solo por color** (WCAG 1.4.1): siempre llevan el texto y un icono distinto por estado, y el color solo
 * refuerza (variante de la insignia).
 */
import type { Component } from 'vue'
import { Badge } from '@/components/ui/badge'

defineProps<{
  text: string
  icon: Component
  variant?: 'default' | 'secondary' | 'destructive' | 'outline'
}>()
</script>

<template>
  <Badge :variant="variant ?? 'outline'" data-slot="fam-unit-status">
    <component :is="icon" aria-hidden="true" />
    {{ text }}
  </Badge>
</template>
